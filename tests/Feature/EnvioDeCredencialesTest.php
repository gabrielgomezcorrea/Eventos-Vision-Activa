<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\PaymentReviewAction;
use App\Enums\Rol;
use App\Mail\CredencialParticipante;
use App\Mail\PagoAprobado;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Las credenciales salen siempre al completar el pago, aunque la inscripción
 * se haya creado sin correos al cliente: al responsable y a cada participante.
 */
class EnvioDeCredencialesTest extends TestCase
{
    use RefreshDatabase;

    private Order $orden;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));

        $event = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
        $jornada = $event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 10]);
        $acceso = $event->accessTypes()->create(['name' => 'J1', 'position' => 1, 'price' => 90000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $event->id,
            'responsible_name' => 'Manuel',
            'responsible_lastname' => 'Rain',
            'responsible_email' => 'manuel@colegio.cl',
            'notify_client' => false,
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Yasna', 'email' => 'yasna@colegio.cl', 'access_type_id' => $acceso->id]);
        $orden->participants()->create(['first_name' => 'Sin correo', 'access_type_id' => $acceso->id]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());
    }

    private function pagar(): void
    {
        $pago = app(RegistrarComprobante::class)(
            $this->orden, ['amount' => $this->orden->total], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        );
        app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $this->usuarioConRol(Rol::Contabilidad));
    }

    public function test_sin_correos_al_cliente_igual_salen_las_credenciales_al_responsable_y_participantes(): void
    {
        Mail::assertNothingQueued(); // la casilla apagada sí silencia la confirmación

        $this->pagar();

        Mail::assertQueued(PagoAprobado::class, fn ($m) => $m->hasTo('manuel@colegio.cl'));
        Mail::assertQueued(CredencialParticipante::class, 1);
        Mail::assertQueued(CredencialParticipante::class, fn ($m) => $m->hasTo('yasna@colegio.cl'));

        $orden = $this->orden->fresh();
        $this->assertSame('manuel@colegio.cl', $orden->credentials_sent_to);
        $this->assertSame(1, Ticket::whereNotNull('emailed_at')->count());
    }

    public function test_el_panel_muestra_a_donde_se_enviaron(): void
    {
        $this->pagar();

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('inscripciones.show', $this->orden))
            ->assertInertia(fn (Assert $page) => $page
                ->where('credenciales_al_responsable', fn ($v) => str_starts_with($v, 'manuel@colegio.cl · '))
                ->where('credenciales', fn ($filas) => collect($filas)->pluck('enviada')->sort()->values()->map(
                    fn (string $v): string => str_starts_with($v, 'yasna@colegio.cl · ') ? 'yasna' : $v,
                )->all() === ['Sin correo del participante', 'yasna']));
    }

    public function test_reenviar_al_cliente_manda_las_credenciales_a_todos(): void
    {
        $this->pagar();
        Mail::fake();

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->post(route('inscripciones.reenviar', $this->orden))->assertRedirect();

        Mail::assertQueued(PagoAprobado::class, fn ($m) => $m->hasTo('manuel@colegio.cl'));
        Mail::assertQueued(CredencialParticipante::class, fn ($m) => $m->hasTo('yasna@colegio.cl'));
    }

    public function test_el_comando_envia_las_pendientes_y_no_repite(): void
    {
        $this->pagar();
        // Simula lo que quedó en producción antes del arreglo: nada enviado.
        $this->orden->fresh()->forceFill(['credentials_sent_at' => null, 'credentials_sent_to' => null])->save();
        Ticket::query()->update(['emailed_at' => null, 'emailed_to' => null]);
        Mail::fake();

        $this->artisan('credenciales:enviar-pendientes --dry-run')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->artisan('credenciales:enviar-pendientes')->assertSuccessful();
        Mail::assertQueued(PagoAprobado::class, 1);
        Mail::assertQueued(CredencialParticipante::class, 1);

        Mail::fake();
        $this->artisan('credenciales:enviar-pendientes')->assertSuccessful();
        Mail::assertNothingQueued();
    }
}
