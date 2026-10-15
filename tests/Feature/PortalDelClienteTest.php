<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
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
}
