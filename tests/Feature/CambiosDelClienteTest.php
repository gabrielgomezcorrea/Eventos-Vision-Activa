<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Enums\EventStatus;
use App\Enums\NivelDeCambio;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Portal V2, fase 5: el cliente corrige solo, con límites.
 */
class CambiosDelClienteTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventSession $jornada1;

    private EventSession $jornada2;

    private AccessType $general;

    private AccessType $premium;

    private Order $orden;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));

        $this->event = Event::create(['name' => 'Seminario', 'slug' => 'seminario', 'status' => EventStatus::Publicado]);
        $this->jornada1 = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 5]);
        $this->jornada2 = $this->event->sessions()->create(['name' => 'J2', 'position' => 2, 'capacity' => 5]);

        $this->general = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 90000]);
        $this->general->sessions()->sync([$this->jornada1->id => ['seats' => 1]]);
        $this->premium = $this->event->accessTypes()->create(['name' => 'Premium', 'position' => 2, 'price' => 150000]);
        $this->premium->sessions()->sync([$this->jornada2->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $orden->participants()->create([
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'rut' => '11111111-1', 'access_type_id' => $this->general->id,
        ]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());
        [, $this->token] = MagicLink::emitir($this->event, 'ana@colegio.cl', $this->orden);
    }

    private function ruta(string $nombre, array $extra = []): string
    {
        return route("inscripcion.{$nombre}", ['token' => $this->token] + $extra);
    }

    private function nuevoParticipante(array $extra = []): array
    {
        return $extra + [
            'first_name' => 'Luisa', 'last_name' => 'Soto', 'rut' => '12.345.678-5',
            'position' => 'Docente Diferencial', 'email' => 'luisa@colegio.cl', 'access_type_id' => $this->general->id,
        ];
    }

    private function asientos(EventSession $jornada): int
    {
        return (int) $jornada->fresh()->reserved_seats;
    }

    // ------------------------------------------------------------ nivel de cambio

    public function test_sin_abonos_el_nivel_es_completo(): void
    {
        $this->assertSame(NivelDeCambio::Completo, $this->orden->fresh()->nivelDeCambioDelCliente());
    }

    public function test_con_un_abono_en_revision_solo_corrige_datos(): void
    {
        app(RegistrarComprobante::class)(
            $this->orden, ['amount' => 40000, 'paid_on' => now()->toDateString()], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        );

        $this->assertSame(NivelDeCambio::SoloDatos, $this->orden->fresh()->nivelDeCambioDelCliente());
    }

    public function test_pasado_el_limite_o_fuera_de_reserva_o_invitado_no_cambia_nada(): void
    {
        // Evento que empieza hoy: el límite fue ayer a las 23:59.
        $this->event->update(['starts_on' => now()->toDateString()]);
        $this->assertSame(NivelDeCambio::Ninguno, $this->orden->fresh()->nivelDeCambioDelCliente());

        $this->event->update(['starts_on' => now()->addMonth()->toDateString()]);
        $this->assertSame(NivelDeCambio::Completo, $this->orden->fresh()->nivelDeCambioDelCliente());

        $this->orden->update(['status' => OrderStatus::Vencida]);
        $this->assertSame(NivelDeCambio::Ninguno, $this->orden->fresh()->nivelDeCambioDelCliente());

        $this->orden->update(['status' => OrderStatus::Reservada, 'kind' => OrderKind::Invitado]);
        $this->assertSame(NivelDeCambio::Ninguno, $this->orden->fresh()->nivelDeCambioDelCliente());
    }

    // ------------------------------------------------------------- nivel completo

    public function test_agregar_un_participante_toma_cupo_y_recalcula_el_total(): void
    {
        $this->assertSame(1, $this->asientos($this->jornada1));

        $this->post($this->ruta('participantes.agregar'), $this->nuevoParticipante())
            ->assertRedirect($this->ruta('portal.inscripcion', ['guardado' => 'participantes']).'#participantes');

        $orden = $this->orden->fresh();
        $this->assertSame(2, $orden->participantesVigentes()->count());
        $this->assertSame(2, $this->asientos($this->jornada1));
        $this->assertSame(180000, (int) $orden->total);
        $this->assertSame(180000, $orden->saldo());
        $this->assertSame(90000, (int) $orden->participantesVigentes()->where('first_name', 'Luisa')->value('unit_price'));
        $this->assertTrue(AuditLog::where('action', 'orden.participante_agregado_por_cliente')->exists());
    }

    public function test_sin_cupo_no_se_agrega_y_nada_cambia(): void
    {
        $this->jornada1->update(['capacity' => 1]);

        $this->post($this->ruta('participantes.agregar'), $this->nuevoParticipante())
            ->assertOk()
            ->assertSee('J1');

        $orden = $this->orden->fresh();
        $this->assertSame(1, $orden->participantesVigentes()->count());
        $this->assertSame(1, $this->asientos($this->jornada1));
        $this->assertSame(90000, (int) $orden->total);
    }

    public function test_quitar_devuelve_el_cupo_y_el_ultimo_participante_no_se_quita(): void
    {
        $this->post($this->ruta('participantes.agregar'), $this->nuevoParticipante());
        $luisa = $this->orden->fresh()->participantesVigentes()->where('first_name', 'Luisa')->sole();

        $this->delete($this->ruta('participantes.quitar', ['participante' => $luisa->id]))
            ->assertRedirect();

        $orden = $this->orden->fresh();
        $this->assertSame(1, $orden->participantesVigentes()->count());
        $this->assertSame(1, $this->asientos($this->jornada1));
        $this->assertSame(90000, (int) $orden->total);

        $carlos = $orden->participantesVigentes()->sole();
        $this->delete($this->ruta('participantes.quitar', ['participante' => $carlos->id]))
            ->assertOk()
            ->assertSee('al menos un participante');

        $this->assertSame(1, $orden->participantesVigentes()->count());
        $this->assertSame(1, $this->asientos($this->jornada1));
    }

    public function test_cambiar_el_acceso_mueve_los_cupos_y_el_precio(): void
    {
        $carlos = $this->orden->participantesVigentes()->sole();

        $this->patch($this->ruta('participantes.acceso', ['participante' => $carlos->id]), ['access_type_id' => $this->premium->id])
            ->assertRedirect();

        $this->assertSame(0, $this->asientos($this->jornada1));
        $this->assertSame(1, $this->asientos($this->jornada2));
        $this->assertSame(150000, (int) $this->orden->fresh()->total);
        $this->assertSame(150000, (int) $carlos->fresh()->unit_price);
    }

    public function test_cambiar_el_acceso_sin_cupo_en_el_nuevo_no_altera_nada(): void
    {
        $this->jornada2->update(['capacity' => 1, 'reserved_seats' => 1]);
        $carlos = $this->orden->participantesVigentes()->sole();

        $this->patch($this->ruta('participantes.acceso', ['participante' => $carlos->id]), ['access_type_id' => $this->premium->id])
            ->assertOk();

        $this->assertSame(1, $this->asientos($this->jornada1), 'El acceso viejo conserva su cupo.');
        $this->assertSame(1, $this->asientos($this->jornada2));
        $this->assertSame($this->general->id, $carlos->fresh()->access_type_id);
        $this->assertSame(90000, (int) $this->orden->fresh()->total);
    }

    public function test_el_descuento_por_cantidad_se_recalcula_con_cada_cambio(): void
    {
        $this->event->discountTiers()->create(['min_participants' => 2, 'type' => 'percent', 'value' => 10]);

        $this->post($this->ruta('participantes.agregar'), $this->nuevoParticipante());

        $orden = $this->orden->fresh();
        $this->assertSame(180000, (int) $orden->subtotal);
        $this->assertSame(18000, (int) $orden->discount_amount);
        $this->assertSame(162000, (int) $orden->total);

        $luisa = $orden->participantesVigentes()->where('first_name', 'Luisa')->sole();
        $this->delete($this->ruta('participantes.quitar', ['participante' => $luisa->id]));

        $orden = $orden->fresh();
        $this->assertSame(0, (int) $orden->discount_amount);
        $this->assertSame(90000, (int) $orden->total);
    }

    public function test_con_codigo_sumar_o_quitar_gente_no_gasta_usos_y_recalcula_el_porcentaje(): void
    {
        $codigo = DiscountCode::factory()->create(['event_id' => $this->event->id, 'max_people' => 5, 'used_people' => 1]);
        $this->orden->forceFill(['discount_code_id' => $codigo->id, 'discount_amount' => 9000, 'total' => 81000])->save();

        $this->post($this->ruta('participantes.agregar'), $this->nuevoParticipante());

        // El cupo del código es por persona (el solicitante), no por participante.
        $this->assertSame(1, (int) $codigo->fresh()->used_people);
        // 10 % sobre 180.000
        $this->assertSame(162000, (int) $this->orden->fresh()->total);

        $luisa = $this->orden->fresh()->participantesVigentes()->where('first_name', 'Luisa')->sole();
        $this->delete($this->ruta('participantes.quitar', ['participante' => $luisa->id]));

        $this->assertSame(1, (int) $codigo->fresh()->used_people);
        $this->assertSame(81000, (int) $this->orden->fresh()->total);
    }

    public function test_con_el_codigo_lleno_igual_se_suma_gente_porque_la_persona_ya_lo_usa(): void
    {
        $codigo = DiscountCode::factory()->create(['event_id' => $this->event->id, 'max_people' => 1, 'used_people' => 1]);
        $this->orden->forceFill(['discount_code_id' => $codigo->id])->save();

        $this->post($this->ruta('participantes.agregar'), $this->nuevoParticipante())->assertRedirect();

        $this->assertSame(2, $this->orden->fresh()->participantesVigentes()->count());
        $this->assertSame(1, (int) $codigo->fresh()->used_people);
    }

    public function test_edita_la_entidad_pagadora(): void
    {
        $this->post($this->ruta('pagador.guardar'), [
            'name' => 'Fundación Nueva', 'rut' => '76.086.428-5', 'address' => 'Av. Grecia 1234',
            'commune' => 'Ñuñoa', 'billing_email' => 'factura@fundacion.cl', 'phone' => '56912345678',
        ])->assertRedirect($this->ruta('portal.inscripcion', ['guardado' => 'pagador']).'#pagador');

        $this->assertSame('Fundación Nueva', $this->orden->fresh()->payerEntity->name);
        $this->assertTrue(AuditLog::where('action', 'orden.pagador_actualizado_por_cliente')->exists());
    }

    // ---------------------------------------------------------------- solo datos

    private function conAbono(): void
    {
        app(RegistrarComprobante::class)(
            $this->orden, ['amount' => 40000, 'paid_on' => now()->toDateString()], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        );
    }

    public function test_con_un_abono_corrige_el_nombre_pero_no_el_rut_ni_el_acceso(): void
    {
        $this->conAbono();
        $carlos = $this->orden->participantesVigentes()->sole();

        $this->patch($this->ruta('participantes.corregir', ['participante' => $carlos->id]), [
            'first_name' => 'Carlos Andrés', 'last_name' => 'Rojas', 'position' => 'Docente Diferencial',
            'email' => 'carlos@colegio.cl', 'phone' => '56912345678',
            // Estos no deben tener efecto aunque editen la petición.
            'rut' => '99999999-9', 'access_type_id' => $this->premium->id, 'unit_price' => 1,
        ])->assertRedirect();

        $carlos = $carlos->fresh();
        $this->assertSame('Carlos Andrés', $carlos->first_name);
        $this->assertSame('11111111-1', $carlos->rut);
        $this->assertSame($this->general->id, $carlos->access_type_id);
        $this->assertSame(90000, (int) $carlos->unit_price);
        $this->assertTrue(AuditLog::where('action', 'participante.corregido_por_cliente')->exists());
    }

    public function test_con_un_abono_no_se_agrega_ni_se_quita_ni_se_cambia_el_acceso(): void
    {
        $this->conAbono();
        $carlos = $this->orden->participantesVigentes()->sole();

        $this->post($this->ruta('participantes.agregar'), $this->nuevoParticipante())->assertForbidden();
        $this->delete($this->ruta('participantes.quitar', ['participante' => $carlos->id]))->assertForbidden();
        $this->patch($this->ruta('participantes.acceso', ['participante' => $carlos->id]), ['access_type_id' => $this->premium->id])->assertForbidden();
        $this->post($this->ruta('pagador.guardar'), ['name' => 'Otra'])->assertForbidden();

        $this->assertSame(1, $this->orden->fresh()->participantesVigentes()->count());
        $this->assertSame(1, $this->asientos($this->jornada1));
        $this->assertSame(0, $this->asientos($this->jornada2));
    }

    public function test_el_responsable_corrige_su_telefono_con_un_abono(): void
    {
        $this->conAbono();

        $this->patch($this->ruta('responsable.corregir'), [
            'responsible_name' => 'Ana María', 'responsible_lastname' => 'Pérez',
            'responsible_position' => 'Directivo', 'responsible_phone' => '56987654321',
            // El correo no se puede cambiar: identifica el enlace.
            'responsible_email' => 'otra@colegio.cl',
        ])->assertRedirect();

        $orden = $this->orden->fresh();
        $this->assertSame('56987654321', $orden->responsible_phone);
        $this->assertSame('ana@colegio.cl', $orden->responsible_email);
    }

    public function test_pasado_el_limite_no_se_corrige_nada(): void
    {
        $this->event->update(['starts_on' => now()->toDateString()]);
        $carlos = $this->orden->participantesVigentes()->sole();

        $this->patch($this->ruta('participantes.corregir', ['participante' => $carlos->id]), [
            'first_name' => 'Otro', 'last_name' => 'Nombre', 'position' => 'Docente Diferencial', 'email' => 'x@colegio.cl',
        ])->assertForbidden();

        $this->assertSame('Carlos', $carlos->fresh()->first_name);
    }

    public function test_la_pestana_explica_por_que_no_se_puede_cambiar(): void
    {
        $this->conAbono();

        $this->get($this->ruta('portal.inscripcion'))
            ->assertOk()
            ->assertDontSee('Ya enviaste un pago')
            ->assertSee('Corregir datos');
    }
}
