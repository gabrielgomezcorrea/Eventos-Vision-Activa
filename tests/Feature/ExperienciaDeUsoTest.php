<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Reglas de experiencia de uso que se pueden verificar solas.
 *
 * Nacen de la revisión de la Fase 8.5 y están aquí porque son exactamente
 * las que se rompen sin que nadie se dé cuenta: un formulario que borra lo
 * escrito o un mensaje que vuelve al inglés no hacen fallar ninguna otra
 * prueba, pero obligan al cliente a llamar por teléfono.
 */
class ExperienciaDeUsoTest extends TestCase
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
        $this->event->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 10]);
        $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
    }

    public function test_los_mensajes_de_validacion_estan_en_espanol(): void
    {
        $this->post("/f/{$this->event->slug}", ['email' => 'no-es-un-correo', 'phone' => '123'])
            ->assertOk()
            // El mensaje dice qué se espera, no que el campo es inválido: con
            // "revisa el formato" la persona tiene que adivinar y abandona.
            ->assertSee('Escribe un solo correo, como nombre@colegio.cl.')
            ->assertSee('Debes seguir el formato +56912345678.')
            ->assertDontSee('must be a valid email address');
    }

    public function test_un_error_de_validacion_no_borra_lo_que_el_usuario_escribio(): void
    {
        $respuesta = $this->post("/f/{$this->event->slug}", [
            'first_name' => 'María José',
            'last_name' => 'Pérez Soto',
            'email' => 'correo-malo',
            'phone' => '+56912345678',
            'position' => 'Otro',
            'position_otro' => 'Jefa UTP',
            'institution' => 'Liceo A-12',
        ])->assertOk();

        $respuesta->assertSee('María José', escape: false)
            ->assertSee('Pérez Soto', escape: false)
            ->assertSee('correo-malo')
            ->assertSee('Jefa UTP')
            ->assertSee('Liceo A-12');
    }

    public function test_un_enlace_vencido_explica_como_seguir_en_vez_de_dar_un_403_crudo(): void
    {
        [, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $token]));

        MagicLink::query()->update(['expires_at' => now()->subDay()]);

        $this->get(route('inscripcion.estado', ['token' => $token]))
            ->assertForbidden()
            ->assertSee('Este enlace ya no es válido')
            ->assertSee('Pedir un enlace nuevo')
            ->assertSee(route('inscripcion.inicio', ['event' => $this->event->slug]));
    }

    public function test_una_orden_ya_confirmada_ofrece_ver_su_estado_en_vez_de_cerrar_la_puerta(): void
    {
        [, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $token]));

        Order::sole()->update(['status' => OrderStatus::Reservada]);

        $this->get(route('inscripcion.participantes', ['token' => $token]))
            ->assertForbidden()
            ->assertSee('Tu inscripción ya está confirmada')
            ->assertSee('Ver el estado de mi inscripción')
            ->assertSee(route('inscripcion.estado', ['token' => $token]));
    }

    public function test_quitar_un_participante_pide_confirmacion_diciendo_a_quien(): void
    {
        [, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $token]));

        $orden = Order::sole();
        $orden->participants()->create([
            'first_name' => 'Rosa',
            'last_name' => 'Miranda',
            'access_type_id' => $this->event->accessTypes()->sole()->id,
        ]);

        $this->get(route('inscripcion.participantes', ['token' => $token]))
            ->assertOk()
            ->assertSee('data-confirmar="¿Quitar a Rosa Miranda', escape: false);
    }

    public function test_los_envios_largos_avisan_que_estan_en_curso(): void
    {
        [, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $token]));

        // Sin señal de espera la persona vuelve a hacer clic y envía dos veces.
        $this->get(route('inscripcion.responsable', ['token' => $token]))
            ->assertOk()
            ->assertSee('data-enviando-texto', escape: false);
    }
}
