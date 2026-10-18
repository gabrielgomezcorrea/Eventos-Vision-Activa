<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalDelClienteTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Order $orden;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('documentos');

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
            'location' => 'Centro de Eventos', 'city' => 'Santiago',
            'program_email_intro' => 'Bienvenidos al seminario.',
            'contact_name' => 'Flor Vidal', 'contact_email' => 'contacto@visionactiva.cl',
        ]);
        $jornada = $this->event->sessions()->create([
            'name' => 'Jornada inaugural', 'position' => 1, 'capacity' => 10,
            'starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 13:00:00',
        ]);
        $acceso = $this->event->accessTypes()->create(['name' => 'Acceso general', 'position' => 1, 'price' => 90000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Carlos', 'last_name' => 'Rojas', 'access_type_id' => $acceso->id]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());
        [, $this->token] = MagicLink::emitir($this->event, 'ana@colegio.cl', $this->orden);
    }

    private function ruta(string $nombre, array $extra = []): string
    {
        return route("inscripcion.{$nombre}", ['token' => $this->token] + $extra);
    }

    public function test_las_cuatro_pestanas_responden_con_el_mismo_encabezado_y_la_activa_marcada(): void
    {
        foreach (['programa' => 'Programa', 'inscripcion' => 'Inscripción', 'pago' => 'Pago', 'credenciales' => 'Credenciales'] as $clave => $etiqueta) {
            $html = $this->get($this->ruta("portal.{$clave}"))
                ->assertOk()
                ->assertSee('Inscripción '.$this->orden->number)
                ->assertSee('Contáctanos')
                ->getContent();

            $this->assertMatchesRegularExpression(
                '#class="pestana activa"\s+aria-current="page"\s*>\d\. '.preg_quote($etiqueta, '#').'#u',
                $html,
                "La pestaña {$etiqueta} no quedó marcada como activa.",
            );
        }
    }

    public function test_un_token_invalido_da_la_pagina_de_enlace_no_utilizable(): void
    {
        foreach (['programa', 'inscripcion', 'pago', 'credenciales'] as $pestana) {
            $this->get(route("inscripcion.portal.{$pestana}", ['token' => 'inventado']))
                ->assertForbidden()
                ->assertSee('Este enlace ya no es válido');
        }
    }

    public function test_el_programa_muestra_las_jornadas_sin_mezclar_los_accesos(): void
    {
        $this->get($this->ruta('portal.programa'))
            ->assertOk()
            ->assertSee('Jornada inaugural')
            ->assertSee('09:00')
            ->assertDontSee('Acceso general')
            ->assertSee('Centro de Eventos, Santiago')
            ->assertSee('Agregar al calendario');
    }

    public function test_el_calendario_es_un_ics_con_la_hora_de_la_jornada(): void
    {
        $respuesta = $this->get($this->ruta('portal.calendario'))->assertOk();

        $this->assertStringStartsWith('text/calendar', $respuesta->headers->get('Content-Type'));
        $contenido = $respuesta->getContent();
        $this->assertStringContainsString('BEGIN:VEVENT', $contenido);
        // 09:00 en Santiago (UTC-3 en noviembre) son las 12:00 UTC.
        $this->assertStringContainsString('DTSTART:20261110T120000Z', $contenido);
        $this->assertStringContainsString('Jornada inaugural', $contenido);
    }

    public function test_sin_jornadas_con_hora_no_se_ofrece_el_calendario(): void
    {
        $this->event->sessions()->update(['starts_at' => null, 'ends_at' => null]);

        $this->get($this->ruta('portal.programa'))
            ->assertOk()
            ->assertDontSee('Agregar al calendario');
    }

    public function test_un_adjunto_se_descarga_con_el_token_y_no_sin_el(): void
    {
        Storage::disk('documentos')->put('programas/programa.pdf', '%PDF-1.4');
        $adjunto = $this->event->attachments()->create([
            'disk' => 'documentos', 'path' => 'programas/programa.pdf', 'original_name' => 'Programa.pdf',
            'mime_type' => 'application/pdf', 'size' => 8,
        ]);

        $this->get($this->ruta('portal.adjunto', ['attachment' => $adjunto->id]))
            ->assertOk()
            ->assertDownload('Programa.pdf');

        $this->get(route('inscripcion.portal.adjunto', ['token' => 'inventado', 'attachment' => $adjunto->id]))
            ->assertForbidden();

        // Un adjunto de otro evento no se sirve con el token de este.
        $otro = Event::create(['name' => 'Otro', 'slug' => 'otro', 'status' => EventStatus::Publicado]);
        Storage::disk('documentos')->put('programas/otro.pdf', '%PDF-1.4');
        $ajeno = $otro->attachments()->create([
            'disk' => 'documentos', 'path' => 'programas/otro.pdf', 'original_name' => 'Otro.pdf',
            'mime_type' => 'application/pdf', 'size' => 8,
        ]);

        $this->get($this->ruta('portal.adjunto', ['attachment' => $ajeno->id]))->assertNotFound();
    }

    public function test_sin_pago_aprobado_el_html_no_trae_qr_ni_codigo_de_respaldo(): void
    {
        $this->get($this->ruta('portal.credenciales'))
            ->assertOk()
            ->assertSee('Tu credencial aún no está disponible')
            ->assertDontSee('<svg', escape: false)
            ->assertDontSee('Código de respaldo');
    }

    public function test_con_pago_aprobado_las_credenciales_muestran_el_qr(): void
    {
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($this->orden->fresh());

        $this->get($this->ruta('portal.credenciales'))
            ->assertOk()
            ->assertSee('<svg', escape: false)
            ->assertSee('Código de respaldo')
            ->assertSee('Descargar QR');
    }

    public function test_los_enlaces_viejos_redirigen_a_la_pestana_que_corresponde(): void
    {
        // Reservada sin pago aprobado → Pago.
        $this->get($this->ruta('estado'))->assertRedirect($this->ruta('portal.pago'));
        $this->get($this->ruta('credenciales'))->assertRedirect($this->ruta('portal.credenciales'));

        // Pago aprobado → Credenciales.
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        $this->get($this->ruta('estado'))->assertRedirect($this->ruta('portal.credenciales'));

        // Vencida → Pago, que ya muestra el aviso.
        $this->orden->forceFill(['status' => OrderStatus::Vencida, 'payment_status' => PaymentStatus::Pendiente])->save();
        $this->get($this->ruta('estado'))->assertRedirect($this->ruta('portal.pago'));
    }

    public function test_el_acceso_de_una_orden_confirmada_cae_en_su_pestana(): void
    {
        $this->get(route('inscripcion.acceso', ['token' => $this->token]))
            ->assertRedirect($this->ruta('portal.pago'));
    }

    public function test_un_borrador_ve_las_pestanas_y_la_de_inscripcion_trae_los_cuatro_bloques(): void
    {
        $this->orden->forceFill(['status' => OrderStatus::Borrador])->save();

        foreach (['programa', 'pago', 'credenciales'] as $pestana) {
            $this->get($this->ruta("portal.{$pestana}"))->assertOk();
        }

        $this->get($this->ruta('portal.inscripcion'))
            ->assertOk()
            ->assertSee('id="responsable"', escape: false)
            ->assertSee('id="establecimientos"', escape: false)
            ->assertSee('id="participantes"', escape: false)
            ->assertSee('id="pagador"', escape: false);
    }

    public function test_los_pasos_viejos_redirigen_a_su_bloque(): void
    {
        $this->orden->forceFill(['status' => OrderStatus::Borrador])->save();

        foreach (['responsable', 'establecimientos', 'participantes', 'pagador'] as $paso) {
            $this->get($this->ruta($paso))
                ->assertRedirect($this->ruta('portal.inscripcion').'#'.$paso);
        }

        $this->get($this->ruta('resumen'))->assertRedirect($this->ruta('portal.pago'));
    }

    public function test_el_pago_de_un_borrador_dice_que_falta_y_el_servidor_rechaza_confirmar(): void
    {
        $this->orden->forceFill([
            'status' => OrderStatus::Borrador, 'responsible_name' => '', 'payer_entity_id' => null,
        ])->save();

        $this->get($this->ruta('portal.pago'))
            ->assertOk()
            ->assertSee('Debes completar lo requerido en')
            ->assertSee($this->ruta('portal.inscripcion').'#responsable', escape: false)
            // Una sola cosa a la vez: la primera que falta.
            ->assertDontSee($this->ruta('portal.inscripcion').'#pagador', escape: false)
            ->assertSee('disabled', escape: false);

        // Aunque editen la petición, el servidor no confirma incompleto.
        $this->post($this->ruta('confirmar'))
            ->assertOk()
            ->assertSee('Falta registrar al responsable de la inscripción.');

        $this->assertSame(OrderStatus::Borrador, $this->orden->fresh()->status);
    }

    public function test_un_error_en_un_bloque_no_borra_lo_escrito_en_los_otros(): void
    {
        $this->orden->forceFill(['status' => OrderStatus::Borrador])->save();

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Luisa', 'last_name' => 'Soto', 'rut' => '1-9', 'email' => 'luisa@colegio.cl',
        ])
            ->assertOk()
            ->assertSee('value="Luisa"', escape: false)
            // Los otros bloques siguen mostrando lo que ya estaba guardado.
            ->assertSee('value="Ana"', escape: false);
    }

    public function test_con_todo_completo_la_pestana_pago_confirma_al_hacer_clic(): void
    {
        $borrador = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => 'otra@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $borrador->participants()->create(['first_name' => 'Luis', 'access_type_id' => $this->event->accessTypes()->first()->id]);
        [, $token] = MagicLink::emitir($this->event, 'otra@colegio.cl', $borrador);

        // La pestaña Pago es un botón que envía a confirmar, no un enlace.
        $this->get(route('inscripcion.portal.inscripcion', ['token' => $token]))
            ->assertOk()
            ->assertSee('action="'.route('inscripcion.confirmar', ['token' => $token]).'"', escape: false);

        // Abrir Pago por enlace no confirma nada.
        $this->get(route('inscripcion.portal.pago', ['token' => $token]))->assertOk();
        $this->assertSame(OrderStatus::Borrador, $borrador->fresh()->status);

        $this->post(route('inscripcion.confirmar', ['token' => $token]))
            ->assertRedirect(route('inscripcion.portal.pago', ['token' => $token]));
        $this->assertSame(OrderStatus::Reservada, $borrador->fresh()->status);
    }

    private function conCuentaBancaria(): void
    {
        $this->event->update([
            'bank_name' => 'BancoEstado', 'bank_account_type' => 'Cuenta Corriente', 'bank_account_number' => '123456789',
            'bank_holder_name' => 'Visión Activa SpA', 'bank_holder_rut' => '76.086.428-5', 'bank_email' => 'pagos@visionactiva.cl',
        ]);
    }

    public function test_los_datos_para_transferir_estan_en_el_menu_izquierdo_salvo_en_pago_y_con_pago_aprobado(): void
    {
        $this->conCuentaBancaria();

        foreach (['programa', 'inscripcion', 'credenciales'] as $pestana) {
            $this->get($this->ruta("portal.{$pestana}"))
                ->assertOk()
                ->assertSee('Datos para transferir')
                ->assertSee('123456789')
                ->assertSee('$90.000')
                ->assertSee($this->orden->number);
        }

        // En Pago ya están en el centro, con la tarjeta "Transfiere": no se repiten.
        $this->get($this->ruta('portal.pago'))->assertOk()->assertDontSee('Datos para transferir')->assertSee('123456789');

        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        $this->get($this->ruta('portal.programa'))->assertOk()->assertDontSee('Datos para transferir');
    }

    public function test_en_un_borrador_la_referencia_dice_que_se_genera_al_confirmar(): void
    {
        $this->conCuentaBancaria();
        $this->orden->forceFill(['status' => OrderStatus::Borrador, 'number' => null])->save();

        $this->get($this->ruta('portal.programa'))->assertOk()->assertSee('Se genera al confirmar');
    }

    public function test_un_borrador_incompleto_no_muestra_los_datos_para_transferir(): void
    {
        $this->conCuentaBancaria();
        // Sin participantes todavía: falta lo requerido, aún no hay nada que transferir.
        $this->orden->forceFill(['status' => OrderStatus::Borrador, 'number' => null])->save();
        $this->orden->participants()->delete();

        foreach (['programa', 'inscripcion'] as $pestana) {
            $this->get($this->ruta("portal.{$pestana}"))->assertOk()->assertDontSee('Datos para transferir');
        }
    }

    public function test_tus_comprobantes_lista_los_abonos_y_el_cliente_abre_su_archivo(): void
    {
        Storage::fake('documentos');
        Storage::disk('documentos')->put('comprobantes/1/a.pdf', '%PDF-1.4');
        $pago = $this->orden->payments()->create(['status' => PaymentStatus::EnValidacion, 'amount' => 30000, 'paid_on' => now()->toDateString()]);
        $pago->proofs()->create(['disk' => 'documentos', 'path' => 'comprobantes/1/a.pdf', 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 8, 'hash' => 'x']);
        $this->orden->forceFill(['payment_status' => PaymentStatus::EnValidacion])->save();

        $url = $this->ruta('comprobante.ver', ['payment' => $pago->id]);

        foreach (['programa', 'credenciales'] as $pestana) {
            $this->get($this->ruta("portal.{$pestana}"))
                ->assertOk()
                ->assertSee('Tus comprobantes')
                ->assertSee('$30.000')
                ->assertSee('En revisión')
                ->assertSee($url, escape: false);
        }

        $respuesta = $this->get($url)->assertOk();
        $this->assertStringContainsString('inline', $respuesta->headers->get('Content-Disposition'));
    }

    public function test_el_comprobante_de_otro_conjunto_o_con_otro_token_da_404(): void
    {
        Storage::fake('documentos');
        Storage::disk('documentos')->put('comprobantes/1/a.pdf', '%PDF-1.4');

        $ajena = Order::create([
            'event_id' => $this->event->id, 'responsible_name' => 'Eva', 'responsible_lastname' => 'Rojas', 'responsible_email' => 'eva@colegio.cl',
        ]);
        $pagoAjeno = $ajena->payments()->create(['status' => PaymentStatus::EnValidacion, 'amount' => 1000]);
        $pagoAjeno->proofs()->create(['disk' => 'documentos', 'path' => 'comprobantes/1/a.pdf', 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 8, 'hash' => 'x']);

        // Un pago de una orden que no es de mi conjunto.
        $this->get($this->ruta('comprobante.ver', ['payment' => $pagoAjeno->id]))->assertNotFound();

        // Con el token de la dueña de ese pago sí abre, y con el mío no.
        [, $tokenAjeno] = MagicLink::emitir($this->event, 'eva@colegio.cl', $ajena);
        $this->get(route('inscripcion.comprobante.ver', ['token' => $tokenAjeno, 'payment' => $pagoAjeno->id]))->assertOk();
        $this->get(route('inscripcion.comprobante.ver', ['token' => 'inventado', 'payment' => $pagoAjeno->id]))->assertForbidden();
    }

    public function test_cada_pestana_termina_con_su_boton_de_siguiente_paso(): void
    {
        $this->get($this->ruta('portal.programa'))->assertSee('Siguiente: Inscripción');
        $this->get($this->ruta('portal.inscripcion'))->assertSee('Siguiente: Pago');
        // Pago sin aprobar y Credenciales no llevan botón.
        $this->get($this->ruta('portal.pago'))->assertDontSee('Siguiente: Credenciales');
        $this->get($this->ruta('portal.credenciales'))->assertDontSee('Siguiente:');

        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        $this->get($this->ruta('portal.pago'))->assertSee('Siguiente: Credenciales');
    }

    public function test_siguiente_pago_desde_inscripcion_confirma_un_borrador_completo(): void
    {
        $borrador = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => 'otra@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $borrador->participants()->create(['first_name' => 'Luis', 'access_type_id' => $this->event->accessTypes()->first()->id]);
        [, $token] = MagicLink::emitir($this->event, 'otra@colegio.cl', $borrador);

        $this->get(route('inscripcion.portal.inscripcion', ['token' => $token]))
            ->assertSee('Siguiente: Pago')
            ->assertSee('data-enviando-texto="Confirmando…">Siguiente: Pago', escape: false);

        $this->post(route('inscripcion.confirmar', ['token' => $token]))->assertRedirect();
        $this->assertSame(OrderStatus::Reservada, $borrador->fresh()->status);
    }

    /** Dos inscripciones del MISMO colegio en un conjunto: `?colegio=` no las distingue. */
    private function dosDelMismoColegio(): Order
    {
        $colegio = Establishment::factory()->create(['name' => 'Colegio Norte']);
        $grupo = OrderGroup::paraResponsable($this->event, 'ana@colegio.cl');
        $this->orden->establishments()->attach($colegio);
        $this->orden->forceFill(['group_id' => $grupo->id])->save();

        $segunda = Order::create([
            'event_id' => $this->event->id, 'group_id' => $grupo->id,
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => $this->orden->payer_entity_id,
        ]);
        $segunda->establishments()->attach($colegio);
        $segunda->participants()->create(['first_name' => 'Luis', 'access_type_id' => $this->event->accessTypes()->first()->id]);

        return app(ConfirmarOrden::class)($segunda->fresh());
    }

    public function test_cada_inscripcion_del_conjunto_se_abre_por_su_identificador(): void
    {
        $segunda = $this->dosDelMismoColegio();
        $this->assertNotSame($this->orden->fresh()->public_id, $segunda->public_id);

        $encabezado = fn (Order $o): string => '<p style="margin:2px 0 0;font-weight:600">Inscripción '.$o->number.'</p>';

        $this->get($this->ruta('portal.programa', ['inscripcion' => $segunda->public_id]))
            ->assertOk()->assertSee($encabezado($segunda), false)->assertDontSee($encabezado($this->orden), false);

        $this->get($this->ruta('portal.programa', ['inscripcion' => $this->orden->fresh()->public_id]))
            ->assertOk()->assertSee($encabezado($this->orden), false)->assertDontSee($encabezado($segunda), false)
            // Mismo colegio dos veces: el menú las distingue por su folio.
            ->assertSee('Inscripción '.$segunda->number);
    }

    public function test_un_identificador_ajeno_o_inventado_cae_en_la_inscripcion_del_token(): void
    {
        $this->dosDelMismoColegio();

        $ajena = Order::create(['event_id' => $this->event->id, 'responsible_name' => 'Eva', 'responsible_lastname' => 'Rojas', 'responsible_email' => 'eva@colegio.cl']);

        foreach ([$ajena->public_id, 'inventado'] as $valor) {
            $this->get($this->ruta('portal.programa', ['inscripcion' => $valor]))
                ->assertOk()
                ->assertSee('<p style="margin:2px 0 0;font-weight:600">Inscripción '.$this->orden->number.'</p>', false);
        }
    }

    public function test_los_enlaces_viejos_con_colegio_siguen_funcionando(): void
    {
        $this->dosDelMismoColegio();
        $colegio = $this->orden->fresh()->establishments->first();

        $this->get($this->ruta('portal.programa', ['colegio' => $colegio->id]))->assertOk();
    }
}
