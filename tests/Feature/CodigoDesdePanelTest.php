<?php

namespace Tests\Feature;

use App\Actions\AplicarCodigoDesdePanel;
use App\Actions\ConfirmarOrden;
use App\Actions\QuitarCodigoDesdePanel;
use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Exceptions\CodigoNoAplicable;
use App\Models\AccessType;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\ProgramRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El equipo aplica o quita un código en una inscripción ya confirmada. Es
 * plata: el total, el cupo del código y las credenciales dependen de esto.
 */
class CodigoDesdePanelTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    private AccessType $ambas;

    private User $coordinacion;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->evento = Event::create(['name' => 'Liderazgo', 'slug' => 'liderazgo', 'status' => EventStatus::Publicado]);
        $s1 = $this->evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 100]);
        $s2 = $this->evento->sessions()->create(['name' => 'Jornada 2', 'position' => 2, 'capacity' => 100]);
        $this->ambas = $this->evento->accessTypes()->create(['name' => 'Ambas Jornadas', 'position' => 1, 'price' => 180000]);
        $this->ambas->sessions()->attach([$s1->id => ['seats' => 1], $s2->id => ['seats' => 1]]);
        $this->coordinacion = $this->usuarioConRol(Rol::Coordinacion);
    }

    private function codigoPorJornada(string $codigo = 'CMEI2026'): DiscountCode
    {
        $creado = DiscountCode::factory()->create(['event_id' => $this->evento->id, 'code' => $codigo, 'type' => 'per_access', 'value' => 0]);
        $creado->preciosPorAcceso()->attach($this->ambas->id, ['price' => 140000]);

        return $creado;
    }

    private function porcentaje(int $valor = 10, array $extra = []): DiscountCode
    {
        return DiscountCode::factory()->create(['event_id' => $this->evento->id, 'code' => 'DESC'.$valor, 'type' => 'percent', 'value' => $valor, ...$extra]);
    }

    /** Inscripción confirmada sin código, de 2 personas en Ambas Jornadas. */
    private function confirmada(string $correo = 'david@colegio.cl'): Order
    {
        $orden = $this->evento->orders()->create([
            'responsible_name' => 'David', 'responsible_lastname' => 'Caceres', 'responsible_email' => $correo,
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);
        foreach (['A', 'B'] as $letra) {
            $orden->participants()->create(['first_name' => 'Persona', 'last_name' => $letra, 'access_type_id' => $this->ambas->id]);
        }

        return app(ConfirmarOrden::class)($orden->fresh());
    }

    private function abono(Order $orden, int $monto, PaymentStatus $estado = PaymentStatus::Aprobado): void
    {
        $orden->payments()->create(['amount' => $monto, 'method' => 'bank_transfer', 'status' => $estado]);
    }

    private function aplicar(Order $orden, string $codigo): Order
    {
        return app(AplicarCodigoDesdePanel::class)($orden, $codigo, $this->coordinacion);
    }

    public function test_el_caso_de_david_queda_pagado_con_credenciales_y_sin_correo(): void
    {
        $orden = $this->confirmada();
        $this->assertSame(360000, (int) $orden->total);
        $this->abono($orden, 280000);
        $orden->forceFill(['payment_status' => PaymentStatus::Pendiente])->save();
        $codigo = $this->codigoPorJornada();
        Mail::fake();

        $resultado = $this->aplicar($orden->fresh(), 'cmei-2026');

        $this->assertSame(280000, (int) $resultado->total);
        $this->assertSame(80000, (int) $resultado->discount_amount);
        $this->assertSame(PaymentStatus::Aprobado, $resultado->payment_status);
        $this->assertSame($codigo->id, $resultado->discount_code_id);
        $this->assertSame(2, $resultado->tickets()->count());
        $this->assertSame(1, $codigo->fresh()->used_people);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('inscripciones.show', $resultado))
            ->assertInertia(fn ($page) => $page
                ->where('participantes.0.valor', 140000));
    }

    public function test_con_porcentaje_baja_el_total_y_sigue_pendiente_sin_credenciales(): void
    {
        $orden = $this->confirmada();
        $this->abono($orden, 100000);
        $orden->forceFill(['payment_status' => PaymentStatus::Pendiente])->save();
        $this->porcentaje(50);

        $resultado = $this->aplicar($orden->fresh(), 'DESC50');

        $this->assertSame(180000, (int) $resultado->total);
        $this->assertSame(PaymentStatus::Pendiente, $resultado->payment_status);
        $this->assertSame(0, $resultado->tickets()->count());
        $this->assertSame(80000, $resultado->saldo());
    }

    public function test_el_descuento_por_cantidad_se_reemplaza_por_el_codigo(): void
    {
        $this->evento->discountTiers()->create(['min_participants' => 2, 'type' => 'percent', 'value' => 10]);
        $orden = $this->confirmada();
        $this->assertSame(324000, (int) $orden->total);
        $this->porcentaje(20);

        $resultado = $this->aplicar($orden, 'DESC20');

        $this->assertSame(288000, (int) $resultado->total);
        $this->assertStringContainsString('Código', (string) $resultado->discount_label);
    }

    public function test_un_codigo_vencido_desactivado_o_inexistente_responde_su_mensaje_y_no_cambia_nada(): void
    {
        $orden = $this->confirmada();
        $this->porcentaje(10, ['code' => 'VENCIDO', 'expires_at' => now()->subDay()]);
        $this->porcentaje(10, ['code' => 'APAGADO', 'is_active' => false]);

        foreach ([['VENCIDO', 'venció el'], ['APAGADO', 'ya no está disponible'], ['NOEXISTE', 'no es válido']] as [$codigo, $mensaje]) {
            try {
                $this->aplicar($orden->fresh(), $codigo);
                $this->fail("{$codigo} debía rechazarse.");
            } catch (CodigoNoAplicable $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $this->assertNull($orden->fresh()->discount_code_id);
        $this->assertSame(360000, (int) $orden->fresh()->total);
    }

    public function test_con_un_codigo_ya_aplicado_pide_quitarlo_primero(): void
    {
        $orden = $this->confirmada();
        $this->porcentaje(10);
        $this->porcentaje(20);
        $this->aplicar($orden, 'DESC10');

        $this->expectException(CodigoNoAplicable::class);
        $this->expectExceptionMessage('Quítalo primero');

        $this->aplicar($orden->fresh(), 'DESC20');
    }

    public function test_lo_aprobado_mayor_al_total_nuevo_se_rechaza(): void
    {
        $orden = $this->confirmada();
        $this->abono($orden, 300000);
        $orden->forceFill(['payment_status' => PaymentStatus::Pendiente])->save();
        $this->codigoPorJornada();

        try {
            $this->aplicar($orden->fresh(), 'CMEI2026');
            $this->fail('Debía rechazarse.');
        } catch (CodigoNoAplicable $e) {
            $this->assertStringContainsString('habría que devolver plata', $e->getMessage());
        }

        $this->assertNull($orden->fresh()->discount_code_id);
        $this->assertSame(360000, (int) $orden->fresh()->total);
        $this->assertSame(0, $this->evento->discountCodes()->sum('used_people'));
    }

    public function test_un_comprobante_en_revision_se_rechaza(): void
    {
        $orden = $this->confirmada();
        $this->abono($orden, 100000, PaymentStatus::EnValidacion);
        $this->porcentaje(10);

        $this->expectException(CodigoNoAplicable::class);
        $this->expectExceptionMessage('Hay un comprobante en revisión');

        $this->aplicar($orden->fresh(), 'DESC10');
    }

    public function test_solo_se_aplica_a_confirmadas_y_no_a_invitados(): void
    {
        $this->porcentaje(10);
        $borrador = $this->evento->orders()->create(['responsible_name' => 'X', 'responsible_lastname' => 'Y', 'responsible_email' => 'x@y.cl']);
        $vencida = $this->confirmada('v@colegio.cl');
        $vencida->forceFill(['status' => OrderStatus::Vencida])->save();
        $cancelada = $this->confirmada('c@colegio.cl');
        $cancelada->forceFill(['status' => OrderStatus::Cancelada])->save();
        $invitado = $this->confirmada('i@colegio.cl');
        $invitado->forceFill(['kind' => OrderKind::Invitado])->save();

        foreach ([$borrador, $vencida, $cancelada, $invitado] as $orden) {
            try {
                $this->aplicar($orden->fresh(), 'DESC10');
                $this->fail('Debía rechazarse.');
            } catch (CodigoNoAplicable $e) {
                $this->assertSame('Solo se aplica a inscripciones confirmadas.', $e->getMessage());
            }
        }
    }

    public function test_quitar_vuelve_al_total_sin_codigo_y_libera_el_cupo(): void
    {
        $orden = $this->confirmada();
        $codigo = $this->porcentaje(50);
        $this->aplicar($orden, 'DESC50');
        $this->assertSame(1, $codigo->fresh()->used_people);

        $resultado = app(QuitarCodigoDesdePanel::class)($orden->fresh(), $this->coordinacion);

        $this->assertSame(360000, (int) $resultado->total);
        $this->assertNull($resultado->discount_code_id);
        $this->assertNull($resultado->discount_label);
        $this->assertSame(0, $codigo->fresh()->used_people);
    }

    public function test_quitar_devuelve_el_tramo_por_cantidad_si_corresponde(): void
    {
        $this->evento->discountTiers()->create(['min_participants' => 2, 'type' => 'percent', 'value' => 10]);
        $orden = $this->confirmada();
        $this->porcentaje(50);
        $this->aplicar($orden, 'DESC50');

        $resultado = app(QuitarCodigoDesdePanel::class)($orden->fresh(), $this->coordinacion);

        $this->assertSame(324000, (int) $resultado->total);
    }

    public function test_quitar_con_pago_aprobado_o_sin_codigo_se_rechaza(): void
    {
        $orden = $this->confirmada();

        try {
            app(QuitarCodigoDesdePanel::class)($orden, $this->coordinacion);
            $this->fail('Sin código debía rechazarse.');
        } catch (CodigoNoAplicable $e) {
            $this->assertSame('Esta inscripción no tiene código.', $e->getMessage());
        }

        $this->porcentaje(10);
        $this->aplicar($orden, 'DESC10');
        $orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();

        try {
            app(QuitarCodigoDesdePanel::class)($orden->fresh(), $this->coordinacion);
            $this->fail('Pagada debía rechazarse.');
        } catch (CodigoNoAplicable $e) {
            $this->assertStringContainsString('ya está pagada', $e->getMessage());
        }

        $this->assertNotNull($orden->fresh()->discount_code_id);
    }

    public function test_contabilidad_y_acreditacion_reciben_403_y_coordinacion_aplica_y_quita(): void
    {
        $orden = $this->confirmada();
        $this->porcentaje(10);

        foreach ([Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $usuario = $this->usuarioConRol($rol);
            $this->actingAs($usuario)->post(route('inscripciones.codigo.aplicar', $orden), ['codigo' => 'DESC10'])->assertForbidden();
            $this->actingAs($usuario)->delete(route('inscripciones.codigo.quitar', $orden))->assertForbidden();
        }
        $this->assertNull($orden->fresh()->discount_code_id);

        $this->actingAs($this->coordinacion)->post(route('inscripciones.codigo.aplicar', $orden), ['codigo' => 'desc10'])->assertRedirect();
        $this->assertSame(324000, (int) $orden->fresh()->total);

        $this->actingAs($this->coordinacion)->delete(route('inscripciones.codigo.quitar', $orden))->assertRedirect();
        $this->assertSame(360000, (int) $orden->fresh()->total);
    }

    public function test_un_codigo_no_aplicable_responde_con_aviso_y_no_cambia_la_orden(): void
    {
        $orden = $this->confirmada();

        $this->actingAs($this->coordinacion)->post(route('inscripciones.codigo.aplicar', $orden), ['codigo' => 'NOEXISTE'])->assertRedirect();
        $this->actingAs($this->coordinacion)->post(route('inscripciones.codigo.aplicar', $orden), ['codigo' => ''])->assertSessionHasErrors('codigo');

        $this->assertSame(360000, (int) $orden->fresh()->total);
    }

    public function test_el_portal_confirmado_muestra_el_codigo_sin_quitar_y_el_cliente_no_puede_quitarlo(): void
    {
        $orden = $this->confirmada();
        $this->porcentaje(10);
        $orden = $this->aplicar($orden, 'DESC10');
        [, $token] = MagicLink::emitir($this->evento, 'david@colegio.cl', $orden);

        foreach (['inscripcion', 'pago', 'credenciales'] as $pestana) {
            $this->get(route("inscripcion.portal.{$pestana}", ['token' => $token]))
                ->assertOk()
                ->assertSee('Descuento aplicado')
                ->assertDontSee('Quitar código');
        }

        $this->post(route('inscripcion.codigo.quitar', ['token' => $token]));

        $this->assertNotNull($orden->fresh()->discount_code_id);
        $this->assertSame(324000, (int) $orden->fresh()->total);
    }

    public function test_el_listado_dice_inscrito_con_el_pago_aprobado_y_la_solicitud_lleva_a_su_inscripcion(): void
    {
        $orden = $this->confirmada();
        $orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        $solicitud = ProgramRequest::factory()->create(['event_id' => $this->evento->id, 'email' => $orden->responsible_email]);
        $orden->forceFill(['program_request_id' => $solicitud->id])->save();

        $this->actingAs($this->coordinacion)->get(route('inscripciones.index'))
            ->assertInertia(fn ($page) => $page->where('ordenes.data.0.estado.label', 'Inscrito')->where('ordenes.data.0.estado.color', 'success'));

        $this->actingAs($this->coordinacion)->get(route('solicitudes.show', $solicitud))
            ->assertInertia(fn ($page) => $page->where('solicitud.inscripcion_id', $orden->id));
    }
}
