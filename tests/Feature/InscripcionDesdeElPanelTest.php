<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\EnlaceDeAcceso;
use App\Mail\OrdenConfirmada;
use App\Mail\PagoAprobado;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\ProgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InscripcionDesdeElPanelTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
    }

    private function crear(string $correo = 'Flor@Colegio.cl', ?Event $evento = null)
    {
        return $this->post(route('inscripciones.crear'), [
            'event_id' => ($evento ?? $this->event)->id,
            'email' => $correo,
        ]);
    }

    public function test_coordinacion_crea_la_inscripcion_y_el_cliente_recibe_su_enlace(): void
    {
        $usuario = $this->usuarioConRol(Rol::Coordinacion);
        $this->actingAs($usuario);

        $orden = null;
        $this->crear()->assertRedirect()->assertSessionHasNoErrors();

        $orden = Order::query()->firstOrFail();
        $this->assertSame($usuario->id, $orden->created_by_user_id);
        $this->assertTrue($orden->creadaPorElEquipo());
        $this->assertSame(OrderStatus::Borrador, $orden->status);
        $this->assertSame('flor@colegio.cl', $orden->responsible_email);

        $solicitud = ProgramRequest::query()->firstOrFail();
        $this->assertSame(ProgramRequest::ORIGEN_DIRECTO, $solicitud->origin);
        $this->assertSame($solicitud->id, $orden->program_request_id);

        Mail::assertQueued(EnlaceDeAcceso::class, fn (EnlaceDeAcceso $m) => $m->hasTo('flor@colegio.cl'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'orden.creada_desde_panel']);
    }

    public function test_el_mismo_correo_no_crea_otra_inscripcion_y_lleva_a_la_existente(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador));

        $this->crear();
        $existente = Order::query()->firstOrFail();
        Mail::fake();

        $this->crear('flor@colegio.cl')->assertRedirect(route('inscripciones.show', $existente));

        $this->assertSame(1, Order::count());
        $this->assertSame(1, ProgramRequest::count());
        Mail::assertNothingQueued();
    }

    public function test_otros_roles_reciben_403(): void
    {
        foreach ([Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $this->actingAs($this->usuarioConRol($rol));
            $this->crear()->assertForbidden();
        }

        $this->assertSame(0, Order::count());
    }

    public function test_evento_cerrado_o_correo_invalido_no_crean_nada(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));

        $cerrado = Event::create(['name' => 'Viejo', 'slug' => 'viejo', 'status' => EventStatus::Cerrado]);
        $this->crear('flor@colegio.cl', $cerrado)->assertSessionHasErrors('event_id');
        $this->crear('sin-arroba')->assertSessionHasErrors('email');

        $this->assertSame(0, Order::count());
    }

    public function test_la_ficha_ofrece_el_mismo_enlace_que_recibio_el_cliente(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));
        $this->crear();
        $orden = Order::query()->firstOrFail();

        $enviado = Mail::queued(EnlaceDeAcceso::class)->first()->url;

        $this->get(route('inscripciones.show', $orden))->assertInertia(fn (Assert $page) => $page
            ->where('enlace_cliente', $enviado)
            ->where('orden.creada_por_el_equipo', true));
    }

    public function test_una_inscripcion_normal_no_ofrece_enlace_del_cliente(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));
        $orden = Order::create(['event_id' => $this->event->id, 'responsible_email' => 'a@b.cl', 'responsible_name' => 'A']);

        $this->get(route('inscripciones.show', $orden))->assertInertia(fn (Assert $page) => $page
            ->where('enlace_cliente', null)
            ->where('orden.creada_por_el_equipo', false));
    }

    public function test_el_portal_acepta_participante_sin_rut_ni_acceso_y_no_cobra_hasta_elegirlo(): void
    {
        $orden = Order::create(['event_id' => $this->event->id, 'responsible_email' => 'otra@colegio.cl', 'responsible_name' => 'O']);
        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 1000]);
        [, $token] = MagicLink::emitir($this->event, 'otra@colegio.cl', $orden);

        $datos = ['first_name' => 'Ana', 'last_name' => 'Pérez', 'position' => 'Director/a', 'email' => 'ana@colegio.cl'];

        $this->post(route('inscripcion.participantes.agregar', ['token' => $token]), $datos + ['access_type_id' => '']);

        $participante = $orden->participants()->sole();
        $this->assertNull($participante->rut);
        $this->assertNull($participante->access_type_id);
        $this->assertSame(0, $orden->fresh()->calcularSubtotal());

        $this->patch(route('inscripcion.participantes.acceso', ['token' => $token, 'participante' => $participante->id]), ['access_type_id' => $acceso->id]);

        $this->assertSame($acceso->id, $participante->fresh()->access_type_id);
        $this->assertSame(1000, $orden->fresh()->calcularSubtotal());
    }

    public function test_el_invitado_especial_puede_registrarse_sin_rut(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador));
        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 1000]);

        $this->post(route('inscripciones.invitado'), [
            'event_id' => $this->event->id, 'first_name' => 'Luz', 'last_name' => 'Rojas', 'position' => 'Director/a',
            'email' => 'luz@colegio.cl', 'access_type_id' => $acceso->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Order::query()->latest('id')->first()->participants()->first()->rut);
    }

    public function test_reenviar_al_cliente_manda_el_correo_de_su_etapa(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));
        $this->crear();
        $orden = Order::query()->firstOrFail();
        Mail::fake();

        $this->post(route('inscripciones.reenviar', $orden))->assertRedirect();
        Mail::assertQueued(EnlaceDeAcceso::class, fn ($m) => $m->hasTo('flor@colegio.cl'));

        $orden->forceFill(['status' => OrderStatus::Reservada, 'payment_status' => PaymentStatus::Pendiente])->save();
        $this->post(route('inscripciones.reenviar', $orden));
        Mail::assertQueued(OrdenConfirmada::class);

        $orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        $this->post(route('inscripciones.reenviar', $orden));
        Mail::assertQueued(PagoAprobado::class);

        $this->assertDatabaseHas('audit_logs', ['action' => 'orden.correo_reenviado']);
    }

    public function test_reenviar_no_manda_nada_si_esta_cancelada_o_vencida_ni_a_otros_roles(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));
        $this->crear();
        $orden = Order::query()->firstOrFail();
        Mail::fake();

        foreach ([OrderStatus::Cancelada, OrderStatus::Vencida] as $estado) {
            $orden->forceFill(['status' => $estado])->save();
            $this->post(route('inscripciones.reenviar', $orden));
        }
        Mail::assertNothingQueued();

        $orden->forceFill(['status' => OrderStatus::Reservada])->save();

        foreach ([Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $this->actingAs($this->usuarioConRol($rol));
            $this->post(route('inscripciones.reenviar', $orden))->assertForbidden();
        }
        Mail::assertNothingQueued();
    }

    public function test_sin_la_casilla_no_sale_ningun_correo_automatico_al_cliente(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));

        $this->post(route('inscripciones.crear'), [
            'event_id' => $this->event->id, 'email' => 'flor@colegio.cl', 'notify_client' => false,
        ])->assertRedirect();

        $orden = Order::query()->firstOrFail();
        $this->assertFalse($orden->notify_client);
        Mail::assertNothingQueued();

        // El reenvío manual sigue siendo una decisión del equipo.
        $this->post(route('inscripciones.reenviar', $orden));
        Mail::assertQueued(EnlaceDeAcceso::class);
    }

    public function test_sin_la_casilla_no_salen_los_avisos_de_pago_ni_de_reserva(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));
        $this->post(route('inscripciones.crear'), [
            'event_id' => $this->event->id, 'email' => 'flor@colegio.cl', 'notify_client' => false,
        ]);
        $orden = Order::query()->firstOrFail();
        $orden->forceFill(['status' => OrderStatus::Reservada, 'reserved_until' => now()->subHour()])->save();

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Vencida, $orden->fresh()->status);
        Mail::assertNothingQueued();
    }
}
