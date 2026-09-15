<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\PaymentReviewAction;
use App\Enums\Rol;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredencialWebTest extends TestCase
{
    use RefreshDatabase;

    private Order $orden;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $event = Event::create([
            'name' => 'Seminario Liderazgo 2026', 'slug' => 'seminario-2026',
            'status' => EventStatus::Publicado, 'location' => 'Centro de Eventos', 'city' => 'Santiago',
        ]);
        $j1 = $event->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 50, 'starts_at' => now()->addMonth()]);
        $j2 = $event->sessions()->create(['name' => 'Jornada 2', 'position' => 2, 'capacity' => 50, 'starts_at' => now()->addMonth()->addDay()]);

        $acceso = $event->accessTypes()->create([
            'name' => 'Ambas jornadas', 'position' => 1, 'price' => 150000,
            'wristband_label' => 'Acceso completo', 'wristband_color' => '#F58A07',
        ]);
        $acceso->sessions()->sync([$j1->id => ['seats' => 1], $j2->id => ['seats' => 1]]);

        $establecimiento = Establishment::create(['name' => 'Colegio San José', 'rbd' => '12345']);

        $orden = Order::create([
            'event_id' => $event->id,
            'responsible_name' => 'Ana Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $orden->establishments()->attach($establecimiento);
        $orden->participants()->create([
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'rut' => '11111111-1',
            'email' => 'carlos@colegio.cl', 'access_type_id' => $acceso->id,
            'establishment_id' => $establecimiento->id,
        ]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());

        $pago = app(RegistrarComprobante::class)(
            $this->orden, ['amount' => 150000], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')
        );
        $contadora = User::factory()->create(['is_active' => true]);
        $contadora->assignRole(Rol::Contabilidad->value);
        app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contadora->fresh());

        $this->orden->refresh();
        $this->ticket = Ticket::sole();
    }

    public function test_la_credencial_muestra_lo_necesario_en_la_puerta(): void
    {
        $this->get($this->ticket->url())
            ->assertOk()
            ->assertSee('Carlos Rojas')
            ->assertSee('Colegio San José')
            ->assertSee('Ambas jornadas')
            ->assertSee('Seminario Liderazgo 2026')
            ->assertSee('Acceso completo')
            ->assertSee($this->ticket->code)
            ->assertSee($this->orden->number);
    }

    public function test_la_credencial_no_expone_el_rut_ni_el_correo(): void
    {
        $this->get($this->ticket->url())
            ->assertOk()
            ->assertDontSee('11111111-1')
            ->assertDontSee('carlos@colegio.cl');
    }

    public function test_la_credencial_incluye_el_qr_dibujado(): void
    {
        $html = $this->get($this->ticket->url())->assertOk()->getContent();

        $this->assertStringContainsString('<svg', $html);
        $this->assertMatchesRegularExpression('/<svg[^>]*viewBox/i', $html);
    }

    public function test_un_token_inventado_no_muestra_credencial(): void
    {
        $this->get(route('ticket.mostrar', ['token' => 'inventado']))->assertNotFound();
    }

    public function test_una_credencial_anulada_lo_dice_con_claridad(): void
    {
        $this->ticket->revocar('Reemplazo de participante');

        $this->get($this->ticket->url())
            ->assertOk()
            ->assertSee('Esta credencial ya no es válida')
            ->assertDontSee('Código de respaldo');
    }

    public function test_el_responsable_ve_todas_las_credenciales_de_su_orden(): void
    {
        [, $token] = MagicLink::emitir($this->orden->event, $this->orden->responsible_email, $this->orden);

        $this->get(route('inscripcion.credenciales', ['token' => $token]))
            ->assertOk()
            ->assertSee($this->orden->number)
            ->assertSee('Carlos Rojas')
            ->assertSee('Imprimir todas');
    }

    public function test_un_enlace_ajeno_no_da_acceso_a_las_credenciales(): void
    {
        $this->get(route('inscripcion.credenciales', ['token' => 'inventado']))->assertForbidden();
    }

    public function test_la_credencial_esta_preparada_para_imprimirse(): void
    {
        $html = $this->get($this->ticket->url())->assertOk()->getContent();

        // Reglas de impresión y control de corte de página entre credenciales.
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('page-break-inside:avoid', $html);
        $this->assertStringContainsString('window.print()', $html);
    }
}
