<?php

namespace Tests\Feature;

use App\Actions\CancelarOrden;
use App\Actions\ConfirmarConjunto;
use App\Actions\ConfirmarOrden;
use App\Actions\IniciarInscripcionAdicional;
use App\Actions\ReactivarReserva;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Exceptions\OrdenNoConfirmable;
use App\Mail\OrdenConfirmada;
use App\Models\AccessType;
use App\Models\DiscountCode;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
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

    public function test_la_pestana_inscripcion_ofrece_el_codigo_lo_muestra_aplicado_y_permite_quitarlo(): void
    {
        $codigo = $this->codigo(['value' => 20]);
        $orden = $this->borrador(5);
        $orden->forceFill(['payer_entity_id' => null])->save(); // inscripción aún incompleta
        [, $token] = MagicLink::emitir($this->evento, 'ana@colegio.cl', $orden);

        // En la columna izquierda desde el inicio, en cualquier pestaña.
        foreach (['programa', 'inscripcion'] as $pestana) {
            $this->get(route("inscripcion.portal.{$pestana}", ['token' => $token]))
                ->assertOk()->assertSee('Código de descuento')->assertSee('¿Tienes un código?')->assertSee('id="codigo"', false);
        }

        $this->post(route('inscripcion.codigo.aplicar', ['token' => $token]), ['codigo' => $codigo->code])
            ->assertRedirect(route('inscripcion.portal.inscripcion', ['token' => $token]));

        $this->get(route('inscripcion.portal.inscripcion', ['token' => $token]))
            ->assertSee('Descuento aplicado')->assertSee('Quitar código');

        $this->post(route('inscripcion.codigo.quitar', ['token' => $token]));
        $this->assertNull($orden->fresh()->discount_code_id);
    }

    public function test_el_descuento_se_refleja_en_el_total_al_aplicar_y_vuelve_al_quitar(): void
    {
        $codigo = $this->codigo(['value' => 20]);
        $orden = $this->borrador(5); // 5 x 100.000 = 500.000
        $orden->forceFill(['payer_entity_id' => null])->save();
        [, $token] = MagicLink::emitir($this->evento, 'ana@colegio.cl', $orden);
        $inscripcion = fn () => $this->get(route('inscripcion.portal.inscripcion', ['token' => $token]));

        $inscripcion()->assertDontSee('Descuento aplicado');

        $this->post(route('inscripcion.codigo.aplicar', ['token' => $token]), ['codigo' => $codigo->code]);

        // La caja dice "Descuento aplicado (20%)" y el total del bloque Participantes ya lo trae.
        $inscripcion()->assertSee('Descuento aplicado (20%)')
            ->assertSee('Subtotal')->assertSee('-$100.000', false)->assertSee('$400.000', false);

        $this->post(route('inscripcion.codigo.quitar', ['token' => $token]));

        // Sin código vuelve el descuento por cantidad (10% con 5 personas).
        $inscripcion()->assertDontSee('Descuento aplicado')->assertSee('$450.000', false);
    }

    public function test_con_la_inscripcion_completa_la_caja_del_codigo_se_oculta(): void
    {
        $orden = $this->borrador(5); // responsable, pagador y participantes: completa
        [, $token] = MagicLink::emitir($this->evento, 'ana@colegio.cl', $orden);

        $this->get(route('inscripcion.portal.programa', ['token' => $token]))->assertOk()->assertDontSee('¿Tienes un código?');
    }

    public function test_un_codigo_invalido_muestra_el_motivo_y_conserva_lo_escrito(): void
    {
        $orden = $this->borrador(5);
        $orden->forceFill(['payer_entity_id' => null])->save();
        [, $token] = MagicLink::emitir($this->evento, 'ana@colegio.cl', $orden);

        $this->post(route('inscripcion.codigo.aplicar', ['token' => $token]), ['codigo' => 'NOEXISTE1'])
            ->assertOk()->assertSee('value="NOEXISTE1"', false)->assertSee('aviso-error', false);
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
        // Una persona (Ana) lo usa, aunque inscriba a 5.
        $this->assertSame(1, $codigo->refresh()->used_people);
    }

    public function test_si_ya_no_quedan_personas_al_confirmar_no_queda_nada_a_medias(): void
    {
        $codigo = $this->codigo(['max_people' => 10]);
        $orden = $this->borrador(5);
        $this->aplicar($orden, $codigo->code);
        // Mientras tanto otras 10 personas lo usaron.
        $codigo->forceFill(['used_people' => 10])->save();

        try {
            app(ConfirmarOrden::class)($orden->fresh());
            $this->fail('debió rechazar');
        } catch (OrdenNoConfirmable $e) {
            $this->assertStringContainsString('máximo de personas', $e->getMessage());
        }

        $this->assertSame(10, $codigo->refresh()->used_people);
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

    public function test_el_cupo_de_la_persona_vuelve_cuando_ya_no_le_queda_ninguna_inscripcion_viva(): void
    {
        $codigo = $this->codigo();
        $cancelada = $this->borrador(3);
        $vencida = $this->borrador(2);
        $this->aplicar($cancelada, $codigo->code);
        $this->aplicar($vencida, $codigo->code);
        $cancelada = app(ConfirmarOrden::class)($cancelada->fresh());
        $vencida = app(ConfirmarOrden::class)($vencida->fresh());
        $this->assertSame(1, $codigo->refresh()->used_people, 'Ana es una sola persona.');

        app(CancelarOrden::class)($cancelada, 'Ya no asisten');
        $this->assertSame(1, $codigo->refresh()->used_people, 'Le queda otra inscripción viva.');

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
        $this->assertSame(1, $codigo->refresh()->used_people);
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
                // Una persona (mismo correo), con solo su inscripción viva.
                ->has('codigos.0.personas', 1)
                ->where('codigos.0.personas.0.correo', 'ana@colegio.cl')
                ->has('codigos.0.personas.0.inscripciones', 1)
                ->where('codigos.0.personas.0.inscripciones.0.folio', $vigente->fresh()->number));
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

    public function test_una_persona_no_junta_dos_codigos_distintos_en_el_mismo_evento(): void
    {
        $x = $this->codigo();
        $y = $this->codigo();
        $b = $this->borradorConRut('12.345.678-5');
        $this->aplicar($b, $y->code);

        // Mientras tanto la misma persona (RUT) confirma otra inscripción con OTRO código.
        $a = $this->borradorConRut('12.345.678-5');
        $a->forceFill(['discount_code_id' => $x->id])->save();
        app(ConfirmarOrden::class)($a->fresh());

        $this->expectException(OrdenNoConfirmable::class);
        app(ConfirmarOrden::class)($b->fresh());
    }

    // ------------------------------------------------ cupo por persona y usos

    private function borradorDe(string $correo, int $participantes = 2): Order
    {
        $orden = $this->borrador($participantes);
        $orden->forceFill(['responsible_email' => $correo])->save();

        return $orden->fresh();
    }

    public function test_el_tope_cuenta_personas_por_correo_y_no_participantes(): void
    {
        $codigo = $this->codigo(['max_people' => 1]);

        $ana = $this->borradorDe('ana@colegio.cl', 5);
        $ana->forceFill(['discount_code_id' => $codigo->id])->save();
        app(ConfirmarOrden::class)($ana->fresh());
        $this->assertSame(1, $codigo->refresh()->used_people);

        $beto = $this->borradorDe('beto@colegio.cl');
        $beto->forceFill(['discount_code_id' => $codigo->id])->save();

        $this->expectException(OrdenNoConfirmable::class);
        app(ConfirmarOrden::class)($beto->fresh());
    }

    public function test_sin_limite_de_uso_la_misma_persona_lo_usa_en_otro_recorrido_sin_gastar_cupo(): void
    {
        $codigo = $this->codigo(['value' => 20, 'max_people' => 1, 'uses_per_person' => 'unlimited']);

        $primera = $this->borradorDe('ana@colegio.cl');
        $primera->forceFill(['discount_code_id' => $codigo->id])->save();
        app(ConfirmarOrden::class)($primera->fresh());

        $segunda = $this->borradorDe('ana@colegio.cl');
        $this->aplicar($segunda, $codigo->code)->assertRedirect();
        $confirmada = app(ConfirmarOrden::class)($segunda->fresh());

        $this->assertSame(40000, $confirmada->discount_amount);
        $this->assertSame(1, $codigo->refresh()->used_people);
    }

    public function test_una_sola_vez_no_se_usa_en_un_segundo_recorrido(): void
    {
        $codigo = $this->codigo(['uses_per_person' => 'once']);

        $primera = $this->borradorDe('ana@colegio.cl');
        $primera->forceFill(['discount_code_id' => $codigo->id])->save();
        app(ConfirmarOrden::class)($primera->fresh());

        $segunda = $this->borradorDe('ana@colegio.cl');
        $segunda->forceFill(['payer_entity_id' => null])->save(); // aún completando: la caja del código está a la vista
        $this->aplicar($segunda, $codigo->code)->assertOk()->assertSee('una sola vez');

        $this->assertNull($segunda->fresh()->discount_code_id);
    }

    public function test_un_monto_fijo_se_descuenta_una_vez_en_el_recorrido_y_se_reparte_entre_colegios(): void
    {
        $codigo = $this->codigo(['type' => 'amount', 'value' => 40000]);
        $orden = $this->borrador(0);
        $norte = Establishment::factory()->create(['name' => 'Colegio Norte']);
        $sur = Establishment::factory()->create(['name' => 'Colegio Sur']);
        $orden->establishments()->attach([$norte->id, $sur->id]);
        foreach ([[$norte, 3], [$sur, 1]] as [$colegio, $cuantos]) {
            for ($i = 1; $i <= $cuantos; $i++) {
                $orden->participants()->create(['first_name' => 'P', 'last_name' => "{$colegio->id}-{$i}", 'access_type_id' => $this->acceso->id, 'establishment_id' => $colegio->id]);
            }
        }
        $orden->forceFill(['discount_code_id' => $codigo->id, 'group_id' => OrderGroup::paraResponsable($this->evento, 'ana@colegio.cl')->id])->save();

        // En el borrador, una sola vez sobre el total: 400.000 - 40.000.
        $this->assertSame(40000, $orden->fresh()->calcularDescuento());

        $ordenes = collect(app(ConfirmarConjunto::class)($orden->fresh()));

        // 300.000 y 100.000: en proporción, 30.000 y 10.000.
        $this->assertSame([30000, 10000], $ordenes->sortByDesc('subtotal')->pluck('discount_amount')->map(fn ($v) => (int) $v)->values()->all());
        $this->assertSame(40000, (int) $ordenes->sum('discount_amount'));
        $this->assertSame(1, $codigo->refresh()->used_people);
    }

    public function test_inscribir_mas_hereda_el_codigo_el_porcentaje_aplica_y_el_monto_fijo_ya_usado_no_se_repite(): void
    {
        foreach ([['percent', 20, 20000], ['amount', 30000, 0]] as [$tipo, $valor, $esperado]) {
            $codigo = $this->codigo(['type' => $tipo, 'value' => $valor]);
            $original = $this->borradorDe('ana'.$tipo.'@colegio.cl', 1);
            $original->forceFill(['discount_code_id' => $codigo->id, 'group_id' => OrderGroup::paraResponsable($this->evento, 'ana'.$tipo.'@colegio.cl')->id])->save();
            $original = app(ConfirmarOrden::class)($original->fresh());

            $adicional = app(IniciarInscripcionAdicional::class)($original);
            $this->assertSame($codigo->id, $adicional->discount_code_id);
            $adicional->participants()->create(['first_name' => 'Nuevo', 'access_type_id' => $this->acceso->id]);

            $confirmada = app(ConfirmarOrden::class)($adicional->fresh());
            $this->assertSame($esperado, (int) $confirmada->discount_amount, "Tipo {$tipo}");
            $this->assertSame(1, $codigo->refresh()->used_people, 'Sigue siendo la misma persona.');
        }
    }
}
