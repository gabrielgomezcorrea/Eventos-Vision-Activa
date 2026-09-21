<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\PagoAprobado;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La revisión de pagos en el panel: quién decide, que el motivo sea
 * obligatorio y que un pago resuelto no se vuelva a decidir.
 */
class PanelComprobantesTest extends TestCase
{
    use RefreshDatabase;

    private Payment $pago;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
        $jornada = $evento->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 50]);
        $acceso = $evento->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $evento->id,
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Carlos', 'access_type_id' => $acceso->id]);

        $this->pago = app(RegistrarComprobante::class)(
            app(ConfirmarOrden::class)($orden->fresh()),
            ['amount' => 90000, 'paid_on' => now()->toDateString()],
            UploadedFile::fake()->create('transferencia.pdf', 100, 'application/pdf'),
        );
    }

    private function usuario(Rol $rol): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol->value);

        return $usuario->fresh();
    }

    public function test_las_pantallas_de_comprobantes_cargan(): void
    {
        $this->actingAs($this->usuario(Rol::Contabilidad));

        $this->get(route('comprobantes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('comprobantes/index')->has('pagos.data', 1));

        $this->get(route('comprobantes.show', $this->pago))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('comprobantes/show')
                ->where('puedeRevisar', true)
                ->has('comprobantes', 1));
    }

    public function test_contabilidad_aprueba_el_pago_y_se_avisa_al_responsable(): void
    {
        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('comprobantes.revisar', $this->pago), [
                'decision' => 'approved',
                'comment' => 'Monto abonado y verificado.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Aprobado, $this->pago->fresh()->status);
        $this->assertSame(PaymentStatus::Aprobado, $this->pago->order->fresh()->payment_status);
        Mail::assertQueued(PagoAprobado::class);
    }

    public function test_aprobar_tambien_exige_una_nota(): void
    {
        // Toda revisión queda explicada en la auditoría, no solo las que le
        // llegan al cliente.
        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('comprobantes.revisar', $this->pago), ['decision' => 'approved', 'comment' => ''])
            ->assertSessionHasErrors(['comment' => 'Escribe una nota de la revisión.']);

        $this->assertSame(PaymentStatus::EnValidacion, $this->pago->fresh()->status);
    }

    public function test_observar_exige_decir_que_corregir(): void
    {
        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('comprobantes.revisar', $this->pago), ['decision' => 'observed', 'comment' => ''])
            ->assertSessionHasErrors(['comment' => 'Indica qué debe corregir el cliente.']);

        $this->assertSame(PaymentStatus::EnValidacion, $this->pago->fresh()->status);
    }

    public function test_un_pago_resuelto_no_se_vuelve_a_decidir(): void
    {
        $contadora = $this->usuario(Rol::Contabilidad);

        $this->actingAs($contadora)->post(route('comprobantes.revisar', $this->pago), [
            'decision' => 'approved',
            'comment' => 'Monto abonado y verificado.',
        ]);
        $this->actingAs($contadora)->post(route('comprobantes.revisar', $this->pago), [
            'decision' => 'rejected',
            'comment' => 'No corresponde a esta inscripción.',
        ]);

        $this->assertSame(PaymentStatus::Aprobado, $this->pago->fresh()->status);
        $this->assertCount(1, $this->pago->reviews()->get());
    }

    public function test_coordinacion_ve_el_comprobante_pero_no_decide(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->get(route('comprobantes.show', $this->pago))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('puedeRevisar', false));

        $this->post(route('comprobantes.revisar', $this->pago), ['decision' => 'approved'])->assertForbidden();

        $this->assertSame(PaymentStatus::EnValidacion, $this->pago->fresh()->status);
    }

    public function test_acreditacion_no_ve_comprobantes_ni_sus_archivos(): void
    {
        $this->actingAs($this->usuario(Rol::Acreditacion));

        $this->get(route('comprobantes.index'))->assertForbidden();
        $this->get(route('comprobantes.show', $this->pago))->assertForbidden();
        $this->get(route('comprobantes.descargar', $this->pago->proofs->first()))->assertForbidden();
    }

    public function test_el_archivo_no_se_alcanza_por_una_url_directa(): void
    {
        $archivo = $this->pago->proofs->first()->path;

        $this->actingAs($this->usuario(Rol::Administrador));

        $this->get('/storage/'.$archivo)->assertNotFound();
        $this->get('/storage/documentos/'.$archivo)->assertNotFound();
    }

    public function test_el_comprobante_se_muestra_en_pantalla_sin_descargarlo(): void
    {
        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->get(route('comprobantes.descargar', [$this->pago->proofs->first(), 'ver' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=transferencia.pdf');
    }
}
