<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\DiscountCode;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Inscribir más participantes": una inscripción nueva del mismo colegio, con su
 * propio folio, cupos y pago. La ya confirmada o pagada no cambia en nada.
 */
class InscripcionAdicionalTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventSession $jornada;

    private Order $original;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
        $this->jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 10]);
        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 90000]);
        $acceso->sessions()->sync([$this->jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => 'ana@colegio.cl',
            'responsible_phone' => '56912345678',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $orden->establishments()->attach(Establishment::factory()->create(['name' => 'Colegio Norte']));
        $orden->participants()->create(['first_name' => 'Carlos', 'last_name' => 'Rojas', 'access_type_id' => $acceso->id]);

        $this->original = app(ConfirmarOrden::class)($orden->fresh());
        [, $this->token] = MagicLink::emitir($this->event, 'ana@colegio.cl', $this->original);
    }

    private function iniciar(?string $token = null): TestResponse
    {
        return $this->post(route('inscripcion.adicional', ['token' => $token ?? $this->token]));
    }

    private function tokenDe(string $url): string
    {
        preg_match('#/i/o/([^/]+)/portal#', $url, $m);

        return $m[1];
    }

    public function test_crea_una_inscripcion_nueva_del_mismo_colegio_y_la_original_no_cambia(): void
    {
        $antes = $this->original->fresh();
        $cuposAntes = $this->jornada->fresh()->reserved_seats;

        $respuesta = $this->iniciar()->assertRedirect();

        $nueva = Order::where('parent_order_id', $this->original->id)->sole();
        $this->assertSame(OrderStatus::Borrador, $nueva->status);
        $this->assertSame($antes->event_id, $nueva->event_id);
        $this->assertSame($antes->payer_entity_id, $nueva->payer_entity_id, 'Es el mismo registro, no una copia.');
        $this->assertSame($antes->establishments->modelKeys(), $nueva->establishments->modelKeys());
        $this->assertSame(0, $nueva->participants()->count());
        $this->assertNotNull($nueva->group_id);
        $this->assertSame($nueva->group_id, $this->original->fresh()->group_id);

        // Va a completar la nueva con su propio enlace, directo a Participantes.
        $url = $respuesta->headers->get('Location');
        $this->assertStringContainsString('inscripcion='.$nueva->public_id, $url);
        $this->assertStringEndsWith('#participantes', $url);
        $this->assertNotSame($this->token, $this->tokenDe($url));

        // La original, intacta.
        $despues = $this->original->fresh();
        $this->assertSame($antes->number, $despues->number);
        $this->assertSame($antes->total, $despues->total);
        $this->assertSame($antes->status, $despues->status);
        $this->assertSame(1, $despues->participants()->count());
        $this->assertSame($cuposAntes, $this->jornada->fresh()->reserved_seats);
    }

    public function test_un_segundo_clic_no_crea_otro_borrador(): void
    {
        $this->iniciar();
        $this->iniciar();

        $this->assertSame(1, Order::where('parent_order_id', $this->original->id)->count());
    }

    public function test_la_nueva_confirma_con_su_folio_cupos_y_precio_propios(): void
    {
        $url = $this->iniciar()->headers->get('Location');
        $propio = $this->tokenDe($url);
        $nueva = Order::where('parent_order_id', $this->original->id)->sole();

        $this->post(route('inscripcion.participantes.agregar', ['token' => $propio]), [
            'first_name' => 'Marta', 'last_name' => 'Soto', 'position' => 'Director/a',
            'email' => 'marta@colegio.cl', 'rut' => '22.222.222-2',
            'access_type_id' => $this->event->accessTypes()->first()->id,
            'establishment_id' => $nueva->establishments->first()->id,
        ]);

        // Los participantes caen en la nueva, nunca en la original.
        $this->assertSame(1, $nueva->participants()->count());
        $this->assertSame(1, $this->original->participants()->count());

        $this->post(route('inscripcion.confirmar', ['token' => $propio]))->assertRedirect();

        $nueva->refresh();
        $this->assertSame(OrderStatus::Reservada, $nueva->status);
        $this->assertNotNull($nueva->number);
        $this->assertNotSame($this->original->number, $nueva->number);
        $this->assertSame(90000, $nueva->total);
        $this->assertSame(2, $this->jornada->fresh()->reserved_seats);
        $this->assertSame(90000, $this->original->fresh()->total);
    }

    public function test_sin_cupo_la_nueva_no_confirma_y_la_original_sigue_intacta(): void
    {
        $propio = $this->tokenDe($this->iniciar()->headers->get('Location'));
        $nueva = Order::where('parent_order_id', $this->original->id)->sole();
        $nueva->participants()->create(['first_name' => 'Marta', 'access_type_id' => $this->event->accessTypes()->first()->id]);

        $this->jornada->forceFill(['reserved_seats' => 10])->save();

        $this->post(route('inscripcion.confirmar', ['token' => $propio]))->assertOk()->assertSee('No quedan cupos');

        $this->assertSame(OrderStatus::Borrador, $nueva->fresh()->status);
        $this->assertSame(OrderStatus::Reservada, $this->original->fresh()->status);
        $this->assertSame(10, $this->jornada->fresh()->reserved_seats);
    }

    public function test_no_se_puede_desde_un_invitado_una_vencida_ni_con_el_evento_cerrado(): void
    {
        $this->original->forceFill(['kind' => OrderKind::Invitado])->save();
        $this->iniciar()->assertForbidden();

        $this->original->forceFill(['kind' => OrderKind::Institucional, 'status' => OrderStatus::Vencida])->save();
        $this->iniciar()->assertForbidden();

        $this->original->forceFill(['status' => OrderStatus::Reservada])->save();
        $this->event->update(['status' => EventStatus::Cerrado]);
        $this->iniciar()->assertForbidden();

        $this->assertSame(0, Order::where('parent_order_id', $this->original->id)->count());
    }

    public function test_en_una_adicional_el_pagador_y_los_establecimientos_no_se_tocan(): void
    {
        $propio = $this->tokenDe($this->iniciar()->headers->get('Location'));
        $nueva = Order::where('parent_order_id', $this->original->id)->sole();
        $colegio = $nueva->establishments->first();

        $this->post(route('inscripcion.pagador.guardar', ['token' => $propio]), ['name' => 'Otra', 'rut' => '76.086.428-5'])->assertForbidden();
        $this->post(route('inscripcion.establecimientos.agregar', ['token' => $propio]), ['name' => 'Colegio Otro', 'address' => 'x', 'commune' => 'Talca'])->assertForbidden();
        $this->delete(route('inscripcion.establecimientos.quitar', ['token' => $propio, 'establishment' => $colegio->id]))->assertForbidden();

        $this->assertSame('Fundación', $this->original->fresh()->payerEntity->name);
        $this->assertSame([$colegio->id], $nueva->fresh()->establishments->modelKeys());

        // Responsable y Participantes sí se editan; el resto se ve como texto.
        $this->get(route('inscripcion.portal.inscripcion', ['token' => $propio]))->assertOk()
            ->assertSee('Colegio Norte')
            ->assertDontSee('Guardar establecimiento');
    }

    public function test_mirar_la_adicional_con_el_enlace_de_la_original_entra_con_uno_propio(): void
    {
        $this->iniciar();
        $nueva = Order::where('parent_order_id', $this->original->id)->sole();

        $respuesta = $this->get(route('inscripcion.portal.inscripcion', ['token' => $this->token, 'inscripcion' => $nueva->public_id]))->assertRedirect();

        $this->assertNotSame($this->token, $this->tokenDe($respuesta->headers->get('Location')));
        $this->assertStringContainsString('inscripcion='.$nueva->public_id, $respuesta->headers->get('Location'));
    }

    public function test_la_tarjeta_se_ve_en_pago_y_credenciales_salvo_en_un_invitado(): void
    {
        foreach (['pago', 'credenciales'] as $pestana) {
            $this->get(route("inscripcion.portal.{$pestana}", ['token' => $this->token]))
                ->assertOk()
                ->assertSee('¿Quieres inscribir más participantes?')
                ->assertSee('¿Quieres agregar otro establecimiento?')
                ->assertSee('Inscribir más participantes')
                ->assertSee('Sumas personas a Colegio Norte, con su propio pago.');
        }

        $this->original->forceFill(['kind' => OrderKind::Invitado, 'payment_status' => PaymentStatus::Aprobado])->save();

        foreach (['pago', 'credenciales'] as $pestana) {
            $this->get(route("inscripcion.portal.{$pestana}", ['token' => $this->token]))
                ->assertOk()
                ->assertDontSee('Inscribir más participantes');
        }
    }

    public function test_otro_token_no_crea_inscripciones_en_la_orden_ajena(): void
    {
        $this->iniciar('inventado')->assertForbidden();
        $this->assertSame(0, Order::where('parent_order_id', $this->original->id)->count());
    }

    public function test_otro_establecimiento_crea_un_borrador_del_conjunto_sin_colegio_ni_pagador_y_con_el_codigo(): void
    {
        $codigo = DiscountCode::factory()->create(['event_id' => $this->event->id]);
        $this->original->forceFill(['discount_code_id' => $codigo->id])->save();

        $url = $this->post(route('inscripcion.otro-establecimiento', ['token' => $this->token]))->assertRedirect()->headers->get('Location');

        $nueva = Order::where('group_id', $this->original->fresh()->group_id)->whereKeyNot($this->original->id)->sole();
        $this->assertSame(OrderStatus::Borrador, $nueva->status);
        $this->assertNull($nueva->parent_order_id);
        $this->assertNull($nueva->payer_entity_id);
        $this->assertSame(0, $nueva->establishments()->count());
        $this->assertSame($codigo->id, $nueva->discount_code_id);
        $this->assertSame('ana@colegio.cl', $nueva->responsible_email);
        $this->assertStringContainsString('inscripcion='.$nueva->public_id, $url);

        // A diferencia de la adicional, aquí sí se agrega colegio y pagador.
        $propio = $this->tokenDe($url);
        $this->post(route('inscripcion.establecimientos.agregar', ['token' => $propio]), ['name' => 'Colegio Sur', 'rbd' => '54321', 'address' => 'Calle 1', 'commune' => 'Ñuñoa'])->assertRedirect();
        $this->post(route('inscripcion.pagador.guardar', ['token' => $propio]), ['name' => 'Otra Fundación', 'rut' => '76.086.428-5', 'address' => 'Av. Grecia 1234', 'commune' => 'Ñuñoa', 'billing_email' => 'pagos@fundacion.cl', 'phone' => '56912345678'])->assertRedirect();
        $this->assertSame(1, $nueva->establishments()->count());
        $this->assertNotNull($nueva->fresh()->payer_entity_id);
        $this->assertSame('Fundación', $this->original->fresh()->payerEntity->name);
    }

    public function test_un_segundo_clic_en_otro_establecimiento_reabre_el_mismo_borrador(): void
    {
        $this->post(route('inscripcion.otro-establecimiento', ['token' => $this->token]));
        $this->post(route('inscripcion.otro-establecimiento', ['token' => $this->token]));

        $this->assertSame(1, Order::where('group_id', $this->original->fresh()->group_id)->whereKeyNot($this->original->id)->count());
    }

    public function test_otro_establecimiento_no_se_puede_desde_un_invitado_ni_con_el_evento_cerrado(): void
    {
        $this->original->forceFill(['kind' => OrderKind::Invitado])->save();
        $this->post(route('inscripcion.otro-establecimiento', ['token' => $this->token]))->assertForbidden();

        $this->original->forceFill(['kind' => OrderKind::Institucional])->save();
        $this->event->update(['status' => EventStatus::Cerrado]);
        $this->post(route('inscripcion.otro-establecimiento', ['token' => $this->token]))->assertForbidden();

        $this->assertSame(0, Order::where('event_id', $this->event->id)->whereKeyNot($this->original->id)->count());
    }

    public function test_un_abono_observado_que_se_reenvio_se_ve_como_reemplazado_para_el_cliente(): void
    {
        $observado = $this->original->payments()->create(['status' => PaymentStatus::Observado, 'amount' => 90000]);

        $this->get(route('inscripcion.portal.pago', ['token' => $this->token]))->assertOk()->assertSee('Observado')->assertDontSee('Reemplazado');

        $this->original->payments()->create(['status' => PaymentStatus::Aprobado, 'amount' => 90000]);

        $this->assertTrue($this->original->fresh()->load('payments')->abonoReemplazado($observado));
        $this->get(route('inscripcion.portal.pago', ['token' => $this->token]))->assertOk()->assertSee('Reemplazado')->assertDontSee('Observado');
    }
}
