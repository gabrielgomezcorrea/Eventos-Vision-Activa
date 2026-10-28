<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\Rol;
use App\Mail\AvisoInscripcionConfirmada;
use App\Mail\OrdenConfirmada;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConfirmacionWebTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventSession $jornada;

    private AccessType $acceso;

    private Order $orden;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
            'bank_name' => 'BancoEstado', 'bank_account_number' => '12345678',
            'bank_holder_name' => 'ATE SpA',
        ]);
        $this->jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 2]);
        $this->acceso = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $this->acceso->sessions()->sync([$this->jornada->id => ['seats' => 1]]);

        [, $this->token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $this->token]));
        $this->orden = Order::sole();

        $this->orden->update([
            'responsible_email' => 'ana@colegio.cl',
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);
        $this->orden->participants()->create(['first_name' => 'Carlos', 'access_type_id' => $this->acceso->id]);
    }

    private function ruta(string $nombre): string
    {
        return route("inscripcion.{$nombre}", ['token' => $this->token]);
    }

    public function test_el_resumen_ofrece_confirmar(): void
    {
        $this->get($this->ruta('portal.pago'))
            ->assertOk()
            ->assertSee('confirmar tu inscripción');
    }

    public function test_confirmar_lleva_al_estado_con_los_datos_bancarios(): void
    {
        $this->post($this->ruta('confirmar'))->assertRedirect($this->ruta('portal.pago'));

        $this->orden->refresh();

        $this->assertSame(OrderStatus::Reservada, $this->orden->status);
        $this->assertNotNull($this->orden->number);

        $this->get($this->ruta('portal.pago'))
            ->assertOk()
            ->assertSee($this->orden->number)
            ->assertSee('BancoEstado')
            ->assertSee('12345678');

        Mail::assertQueued(OrdenConfirmada::class);
    }

    public function test_confirmar_avisa_una_sola_vez_al_equipo_suscrito(): void
    {
        $flor = $this->usuarioConRol(Rol::Coordinacion, ['notify_confirmed_orders' => true]);
        $this->usuarioConRol(Rol::Coordinacion, ['notify_confirmed_orders' => false]);

        $this->post($this->ruta('confirmar'));
        // Un segundo clic no confirma de nuevo ni vuelve a avisar.
        $this->post($this->ruta('confirmar'));

        Mail::assertQueued(AvisoInscripcionConfirmada::class, 1);
        Mail::assertQueued(AvisoInscripcionConfirmada::class, fn (AvisoInscripcionConfirmada $m): bool => $m->hasTo($flor->email)
            && str_contains($m->envelope()->subject, 'Nueva inscripción:')
            && str_contains($m->render(), $this->orden->fresh()->number));
    }

    public function test_si_no_hay_cupo_no_se_avisa_al_equipo(): void
    {
        $this->usuarioConRol(Rol::Coordinacion, ['notify_confirmed_orders' => true]);
        $this->jornada->forceFill(['reserved_seats' => 2])->save();

        $this->post($this->ruta('confirmar'))->assertOk();

        Mail::assertNotQueued(AvisoInscripcionConfirmada::class);
    }

    public function test_tras_confirmar_ya_no_se_puede_editar_pero_si_consultar(): void
    {
        $this->post($this->ruta('confirmar'));

        $this->post($this->ruta('responsable.guardar'))->assertForbidden();
        $this->get($this->ruta('portal.pago'))->assertOk();
    }

    public function test_muestra_un_mensaje_claro_si_se_agotaron_los_cupos(): void
    {
        // Otro cliente se lleva las dos vacantes mientras esta orden estaba abierta.
        $this->jornada->forceFill(['reserved_seats' => 2])->save();

        $this->post($this->ruta('confirmar'))
            ->assertOk()
            ->assertSee('No quedan cupos disponibles')
            ->assertSee('J1');

        $this->assertSame(OrderStatus::Borrador, $this->orden->fresh()->status);
        $this->assertNull($this->orden->fresh()->number);
    }

    public function test_no_confirma_sin_participantes(): void
    {
        $this->orden->participants()->delete();

        $this->post($this->ruta('confirmar'))
            ->assertOk()
            ->assertSee('Agrega al menos un participante');

        $this->assertSame(OrderStatus::Borrador, $this->orden->fresh()->status);
    }

    public function test_un_doble_envio_no_crea_dos_reservas(): void
    {
        $this->post($this->ruta('confirmar'))->assertRedirect($this->ruta('portal.pago'));
        $numero = $this->orden->fresh()->number;

        // El cliente vuelve atrás y reenvía el formulario.
        $this->post($this->ruta('confirmar'));

        $this->assertSame($numero, $this->orden->fresh()->number);
        $this->assertSame(1, $this->jornada->fresh()->reserved_seats);
    }
}
