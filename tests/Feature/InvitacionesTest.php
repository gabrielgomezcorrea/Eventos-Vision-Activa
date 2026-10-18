<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\InvitacionAlEvento;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Invitaciones personales: regalan un cupo, así que el enlace es de un solo uso,
 * atado a su correo, y el tipo y el evento salen de la base, nunca de la URL.
 */
class InvitacionesTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    private AccessType $acceso;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
        $this->jornada = $this->evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 10]);
        $this->acceso = $this->evento->accessTypes()->create(['name' => 'Curso completo', 'position' => 1, 'price' => 100000]);
        $this->acceso->sessions()->attach($this->jornada->id, ['seats' => 1]);
    }

    private $jornada;

    private function datos(array $cambios = []): array
    {
        return [
            'first_name' => 'juan', 'last_name' => 'de la cruz', 'rut' => '12.345.678-5',
            'phone' => '9 1234 5678', 'position' => $this->evento->cargosDeParticipante()[0], 'establecimiento' => 'liceo a-12',
            ...$cambios,
        ];
    }

    /** @return array{0: Invitation, 1: string} */
    private function invitar(string $correo = 'juan@colegio.cl', array $atributos = []): array
    {
        [$invitacion, $token] = Invitation::emitir($this->evento, $this->acceso, $correo, now()->addWeek());
        $invitacion->update($atributos);

        return [$invitacion, $token];
    }

    private function crear(array $cambios = []): array
    {
        return [
            'first_name' => 'ana maría', 'last_name' => 'pérez soto', 'email' => 'ANA@colegio.cl',
            'access_type_id' => $this->acceso->id,
            'expires_on' => now()->addWeek()->format('Y-m-d'),
            ...$cambios,
        ];
    }

    public function test_administracion_invita_con_nombre_y_el_correo_saluda_por_el_nombre(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.invitaciones.store', $this->evento), $this->crear())
            ->assertSessionHasNoErrors();

        $invitacion = Invitation::sole();
        $this->assertSame('Ana María', $invitacion->first_name);
        $this->assertSame('Pérez Soto', $invitacion->last_name);
        $this->assertSame('ana@colegio.cl', $invitacion->email);

        Mail::assertQueued(InvitacionAlEvento::class, function (InvitacionAlEvento $correo): bool {
            $this->assertStringContainsString('Hola Ana María,', $correo->render());
            // En la base solo queda el hash: el enlace no se puede reconstruir desde ahí.
            $this->assertStringNotContainsString(Invitation::first()->token_hash, $correo->url);

            return true;
        });
    }

    public function test_una_invitacion_antigua_sin_nombre_saluda_hola(): void
    {
        [$invitacion] = $this->invitar();

        $html = (new InvitacionAlEvento($invitacion->load('event'), 'https://x.test/i'))->render();

        $this->assertStringContainsString('Hola,', $html);
    }

    public function test_sin_nombre_o_con_correo_mal_escrito_no_se_crea_nada(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.invitaciones.store', $this->evento), $this->crear(['first_name' => '', 'last_name' => '']))
            ->assertSessionHasErrors(['first_name', 'last_name']);

        $this->post(route('eventos.invitaciones.store', $this->evento), $this->crear(['email' => 'beto@colegio']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Invitation::count());
    }

    public function test_quien_ya_tiene_una_invitacion_pendiente_no_recibe_otra(): void
    {
        $this->invitar('ana@colegio.cl');

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.invitaciones.store', $this->evento), $this->crear());

        $this->assertSame(1, Invitation::count());
        Mail::assertNotQueued(InvitacionAlEvento::class);
    }

    public function test_la_pagina_de_la_invitacion_trae_el_nombre_precargado(): void
    {
        [, $token] = Invitation::emitir($this->evento, $this->acceso, 'juan@colegio.cl', now()->addWeek(), null, 'Juan', 'De la Cruz');

        $this->get(route('publico.invitacion', $token))
            ->assertOk()
            ->assertSee('value="Juan"', false)
            ->assertSee('value="De la Cruz"', false);
    }

    public function test_coordinacion_crea_invitaciones(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('eventos.invitaciones.index', $this->evento))->assertOk();
        $this->post(route('eventos.invitaciones.store', $this->evento), $this->crear())->assertSessionHasNoErrors();

        $this->assertSame(1, Invitation::count());
    }

    public function test_contabilidad_y_acreditacion_no_crean_ni_anulan_invitaciones(): void
    {
        [$invitacion] = $this->invitar();

        foreach ([Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $usuario = $this->usuarioConRol($rol);

            $this->actingAs($usuario)->get(route('eventos.invitaciones.index', $this->evento))->assertForbidden();
            $this->actingAs($usuario)->post(route('eventos.invitaciones.store', $this->evento), [
                ...$this->crear(['email' => 'x@colegio.cl']),
            ])->assertForbidden();
            $this->actingAs($usuario)->patch(route('eventos.invitaciones.anular', [$this->evento, $invitacion]))->assertForbidden();
        }

        $this->assertNull($invitacion->refresh()->revoked_at);
    }

    public function test_la_invitada_se_inscribe_sin_pagar_queda_aprobada_con_credencial_y_el_enlace_muere(): void
    {
        [$invitacion, $token] = $this->invitar('juan@colegio.cl');

        $this->get(route('publico.invitacion', $token))->assertOk()->assertSee('juan@colegio.cl');
        $this->post(route('publico.invitacion.guardar', $token), $this->datos())->assertOk()->assertSee('Te enviamos tu credencial');

        $orden = Order::sole();
        $this->assertSame(OrderKind::Invitado, $orden->kind);
        $this->assertSame(PaymentStatus::Aprobado, $orden->payment_status);
        $this->assertSame(0, $orden->total);
        $this->assertSame('juan@colegio.cl', $orden->responsible_email);
        $this->assertSame('Juan De la Cruz', $orden->participants->first()->nombre_completo);
        $this->assertSame(1, $orden->tickets()->count());
        $this->assertSame(1, $this->jornada->refresh()->reserved_seats);
        $this->assertNotNull($invitacion->refresh()->used_at);

        // Un segundo intento con el mismo enlace no crea nada.
        $this->post(route('publico.invitacion.guardar', $token), $this->datos())->assertForbidden();
        $this->assertSame(1, Order::count());
    }

    public function test_usada_vencida_anulada_o_inexistente_responden_lo_mismo(): void
    {
        [, $vencida] = $this->invitar('a@colegio.cl', ['expires_at' => now()->subDay()]);
        [, $anulada] = $this->invitar('b@colegio.cl', ['revoked_at' => now()]);
        [, $usada] = $this->invitar('c@colegio.cl', ['used_at' => now()]);

        foreach ([$vencida, $anulada, $usada, 'token-que-no-existe'] as $token) {
            $this->get(route('publico.invitacion', $token))->assertForbidden()->assertSee('Esta invitación ya no es válida');
            $this->post(route('publico.invitacion.guardar', $token), $this->datos())->assertForbidden();
        }

        $this->assertSame(0, Order::count());
    }

    public function test_sin_cupo_la_invitacion_no_se_consume(): void
    {
        $this->jornada->update(['capacity' => 1, 'reserved_seats' => 1]);
        [$invitacion, $token] = $this->invitar();

        $this->post(route('publico.invitacion.guardar', $token), $this->datos())
            ->assertOk()->assertSee('Ya no quedan cupos');

        $this->assertNull($invitacion->refresh()->used_at);
        $this->assertSame(0, Order::count());

        // Se amplía la jornada y el mismo enlace vuelve a servir.
        $this->jornada->update(['capacity' => 5]);
        $this->post(route('publico.invitacion.guardar', $token), $this->datos())->assertOk()->assertSee('Te enviamos tu credencial');
        $this->assertSame(1, Order::count());
    }

    public function test_los_datos_se_validan_como_en_el_resto_del_sistema(): void
    {
        [$invitacion, $token] = $this->invitar();

        $this->post(route('publico.invitacion.guardar', $token), $this->datos(['first_name' => '.', 'rut' => '123']))
            ->assertOk()->assertSee('aria-invalid', false);

        $this->assertNull($invitacion->refresh()->used_at);
    }

    public function test_anular_deja_el_enlace_sin_uso(): void
    {
        [$invitacion, $token] = $this->invitar();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->patch(route('eventos.invitaciones.anular', [$this->evento, $invitacion]))->assertRedirect();

        $this->get(route('publico.invitacion', $token))->assertForbidden();
    }

    public function test_el_correo_de_invitacion_trae_el_enlace_y_el_contacto_del_evento(): void
    {
        $this->evento->update(['contact_name' => 'Flor Vidal', 'contact_email' => 'flor@visionactiva.cl']);
        [$invitacion] = $this->invitar();

        $html = (new InvitacionAlEvento($invitacion->load('event'), 'https://eventos.test/invitacion/abc'))->render();

        $this->assertStringContainsString('Seminario 2026', $html);
        $this->assertStringContainsString('https://eventos.test/invitacion/abc', $html);
        $this->assertStringContainsString('no lo respondas', $html);
        $this->assertStringContainsString('flor@visionactiva.cl', $html);
    }
}
