<?php

namespace Tests\Feature;

use App\Actions\CancelarOrden;
use App\Actions\ConfirmarOrden;
use App\Actions\ReactivarReserva;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Exceptions\OrdenNoConfirmable;
use App\Mail\OrdenConfirmada;
use App\Models\AccessType;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Códigos de descuento en la inscripción. Hay plata y cupos de por medio: el
 * tope no se pasa, el descuento del código reemplaza al de cantidad y los usos
 * vuelven cuando la inscripción vence o se cancela.
 */
class CodigoDeDescuentoEnInscripcionTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    private AccessType $acceso;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
        $jornada = $this->evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 100]);
        $this->acceso = $this->evento->accessTypes()->create(['name' => 'Curso completo', 'position' => 1, 'price' => 100000]);
        $this->acceso->sessions()->attach($jornada->id, ['seats' => 1]);
        $this->evento->discountTiers()->create(['min_participants' => 5, 'type' => 'percent', 'value' => 10]);
    }

    private function borrador(int $participantes = 5): Order
    {
        $orden = $this->evento->orders()->create([
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);

        for ($i = 1; $i <= $participantes; $i++) {
            $orden->participants()->create(['first_name' => 'Persona', 'last_name' => (string) $i, 'access_type_id' => $this->acceso->id]);
        }

        return $orden;
    }

    private function borradorConRut(string $rut, ?Event $evento = null): Order
    {
        $evento ??= $this->evento;
        $orden = $evento->orders()->create([
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Juan', 'last_name' => 'Soto', 'rut' => $rut, 'access_type_id' => $this->acceso->id]);

        return $orden;
    }

    private function codigo(array $atributos = []): DiscountCode
    {
        return DiscountCode::factory()->create(['event_id' => $this->evento->id, ...$atributos]);
    }

    private function aplicar(Order $orden, string $texto)
    {
        [, $token] = MagicLink::emitir($this->evento, 'ana@colegio.cl', $orden);

        return $this->post(route('inscripcion.codigo.aplicar', ['token' => $token]), ['codigo' => $texto]);
    }

    public function test_el_codigo_reemplaza_al_descuento_por_cantidad(): void
    {
        $codigo = $this->codigo(['value' => 20]);
        $orden = $this->borrador(5);

        $this->aplicar($orden, strtolower($codigo->formateado()))->assertRedirect();

        $confirmada = app(ConfirmarOrden::class)($orden->fresh());

        // 20% del código, no el 10% por cantidad y tampoco los dos juntos.
        $this->assertSame(100000, $confirmada->discount_amount);
        $this->assertSame(400000, $confirmada->total);
        $this->assertSame(5, $codigo->refresh()->used_people);
    }

    public function test_cada_caso_de_error_tiene_su_mensaje(): void
    {
        $orden = $this->borrador();
        $otroEvento = DiscountCode::factory()->create();

        $casos = [
            'NOEXISTE1' => 'no es válido',
            $otroEvento->code => 'no es válido',
            $this->codigo(['expires_at' => now()->subDay()])->code => 'venció',
            $this->codigo(['used_people' => 20])->code => 'ya no tiene cupos',
            $this->codigo(['is_active' => false])->code => 'ya no está disponible',
            $this->codigo(['max_people' => 3])->code => 'quedan 3 usos',
        ];

        foreach ($casos as $texto => $mensaje) {
            // Cada caso se prueba solo: sin esto el límite de intentos corta el sexto.
            RateLimiter::clear('codigo-de-descuento:'.$orden->getKey());
            $this->aplicar($orden->fresh(), $texto)->assertOk()->assertSee($mensaje);
            $this->assertNull($orden->fresh()->discount_code_id);
        }
    }

    public function test_tras_cinco_intentos_fallidos_se_bloquea_incluso_el_codigo_bueno(): void
    {
        $codigo = $this->codigo();
        $orden = $this->borrador();

        for ($i = 0; $i < 5; $i++) {
            $this->aplicar($orden, 'INTENTO'.$i.'XY')->assertSee('no es válido');
        }

        $this->aplicar($orden, $codigo->code)->assertOk()->assertSee('Probaste muchos códigos');
        $this->assertNull($orden->fresh()->discount_code_id);
    }

    public function test_si_no_alcanza_al_confirmar_no_queda_nada_a_medias(): void
    {
        $codigo = $this->codigo(['max_people' => 10]);
        $orden = $this->borrador(5);
        $this->aplicar($orden, $codigo->code);
        $codigo->forceFill(['used_people' => 8])->save();

        try {
            app(ConfirmarOrden::class)($orden->fresh());
            $this->fail('debió rechazar');
        } catch (OrdenNoConfirmable $e) {
            $this->assertStringContainsString('quedan 2 usos', $e->getMessage());
        }

        $this->assertSame(8, $codigo->refresh()->used_people);
        $this->assertSame(OrderStatus::Borrador, $orden->fresh()->status);
        $this->assertSame(0, $this->evento->sessions()->first()->reserved_seats);
    }

    public function test_descuento_completo_deja_la_orden_aprobada_con_credenciales_y_sin_instrucciones_de_pago(): void
    {
        $codigo = $this->codigo(['value' => 100]);
        $orden = $this->borrador(2);
        $this->aplicar($orden, $codigo->code);

        $confirmada = app(ConfirmarOrden::class)($orden->fresh());

        $this->assertSame(0, $confirmada->total);
        $this->assertSame(PaymentStatus::Aprobado, $confirmada->payment_status);
        $this->assertSame(2, $confirmada->tickets()->count());
        Mail::assertNotQueued(OrdenConfirmada::class);
    }

    public function test_los_usos_vuelven_al_cancelar_y_al_vencer_la_reserva(): void
    {
        $codigo = $this->codigo();
        $cancelada = $this->borrador(3);
        $vencida = $this->borrador(2);
        $this->aplicar($cancelada, $codigo->code);
        $this->aplicar($vencida, $codigo->code);
        $cancelada = app(ConfirmarOrden::class)($cancelada->fresh());
        $vencida = app(ConfirmarOrden::class)($vencida->fresh());
        $this->assertSame(5, $codigo->refresh()->used_people);

        app(CancelarOrden::class)($cancelada, 'Ya no asisten');
        $this->assertSame(2, $codigo->refresh()->used_people);

        $vencida->forceFill(['reserved_until' => now()->subDay()])->save();
        Artisan::call('reservas:expirar');
        $this->assertSame(0, $codigo->refresh()->used_people);
    }

    public function test_reactivar_conserva_el_codigo_aunque_haya_vencido(): void
    {
        $codigo = $this->codigo(['value' => 20]);
        $orden = $this->borrador(2);
        $this->aplicar($orden, $codigo->code);
        $orden = app(ConfirmarOrden::class)($orden->fresh());
        $orden->forceFill(['reserved_until' => now()->subDay()])->save();
        Artisan::call('reservas:expirar');
        $codigo->forceFill(['expires_at' => now()->subDay()])->save();

        $reactivada = app(ReactivarReserva::class)($orden->fresh());

        $this->assertSame(40000, $reactivada->discount_amount);
        $this->assertSame(2, $codigo->refresh()->used_people);
    }

    public function test_administracion_ve_quienes_usaron_el_codigo_y_no_las_inscripciones_vencidas(): void
    {
        $codigo = $this->codigo();
        $vigente = $this->borrador(2);
        $vencida = $this->borrador(1);
        $this->aplicar($vigente, $codigo->code);
        $this->aplicar($vencida, $codigo->code);
        app(ConfirmarOrden::class)($vigente->fresh());
        $vencida = app(ConfirmarOrden::class)($vencida->fresh());
        $vencida->forceFill(['reserved_until' => now()->subDay()])->save();
        Artisan::call('reservas:expirar');

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->get(route('eventos.codigos.index', $this->evento))
            ->assertInertia(fn ($pagina) => $pagina
                ->has('codigos.0.personas', 2)
                ->where('codigos.0.personas.0.folio', $vigente->fresh()->number));
    }

    public function test_una_persona_usa_un_solo_codigo_por_evento(): void
    {
        $primero = $this->codigo();
        $segundo = $this->codigo();

        $a = $this->borradorConRut('12.345.678-5');
        $this->aplicar($a, $primero->code);
        app(ConfirmarOrden::class)($a->fresh());

        // Mismo RUT, otro código, mismo evento: rechazado y con el nombre.
        $b = $this->borradorConRut('12.345.678-5');
        $this->aplicar($b, $segundo->code)->assertOk()->assertSee('Juan Soto ya usó un código');
        $this->assertNull($b->fresh()->discount_code_id);

        // Otro RUT sí puede.
        $c = $this->borradorConRut('11.111.111-1');
        $this->aplicar($c, $segundo->code)->assertRedirect();
    }

    public function test_el_codigo_previo_no_cuenta_en_otro_evento_ni_si_la_inscripcion_vencio(): void
    {
        $codigo = $this->codigo();
        $a = $this->borradorConRut('12.345.678-5');
        $this->aplicar($a, $codigo->code);
        $a = app(ConfirmarOrden::class)($a->fresh());

        $otroEvento = Event::create(['name' => 'Otro', 'slug' => 'otro', 'status' => EventStatus::Publicado]);
        $this->assertCount(0, $this->borradorConRut('12.345.678-5', $otroEvento)->participantesConCodigoPrevio());

        $a->forceFill(['reserved_until' => now()->subDay()])->save();
        Artisan::call('reservas:expirar');
        $this->assertCount(0, $this->borradorConRut('12.345.678-5')->participantesConCodigoPrevio());
    }

    public function test_se_revalida_al_confirmar_si_otro_uso_un_codigo_mientras_tanto(): void
    {
        $codigo = $this->codigo();
        $b = $this->borradorConRut('12.345.678-5');
        $this->aplicar($b, $codigo->code);

        // Mientras B está en el Resumen, la misma persona confirma otra inscripción con código.
        $a = $this->borradorConRut('12.345.678-5');
        $a->forceFill(['discount_code_id' => $codigo->id])->save();
        app(ConfirmarOrden::class)($a->fresh());

        $this->expectException(OrdenNoConfirmable::class);
        app(ConfirmarOrden::class)($b->fresh());
    }
}
