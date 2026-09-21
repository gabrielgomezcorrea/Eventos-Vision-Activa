<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\EmitirEnlaceDeAcceso;
use App\Actions\EmitirTickets;
use App\Enums\PaymentStatus;
use App\Mail\ComprobanteRecibido;
use App\Mail\CredencialParticipante;
use App\Mail\EnlaceDeAcceso;
use App\Mail\OrdenConfirmada;
use App\Mail\PagoAprobado;
use App\Mail\PagoObservado;
use App\Mail\PagoRechazado;
use App\Mail\ProgramaDelEvento;
use App\Mail\RecordatorioDeReserva;
use App\Mail\ReservaVencida;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\ProgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regla de AGENTS.md: todo correo incluye nombre del evento, número de orden,
 * estado actual, próximo paso y un enlace seguro de seguimiento.
 */
class CorreosDelMvpTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Order $orden;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::factory()->conJornadasYAccesos(50)->create([
            'name' => 'Seminario de Prueba 2026',
        ]);

        $orden = Order::factory()->create(['event_id' => $this->event->id]);
        $establecimiento = Establishment::factory()->create();
        $orden->establishments()->attach($establecimiento);

        Participant::factory()->count(2)->create([
            'order_id' => $orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $this->event->accessTypes()->first()->id,
            'email' => 'participante@colegio.cl',
        ]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());
        $this->orden->forceFill(['reserved_until' => now()->addDays(3)])->save();
        $this->orden->refresh();
    }

    /** @return array<string, string> */
    private function correosDeOrden(): array
    {
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($this->orden->fresh());
        $this->orden->refresh();

        return [
            'orden confirmada' => (new OrdenConfirmada($this->orden))->render(),
            'comprobante recibido' => (new ComprobanteRecibido($this->orden))->render(),
            'pago observado' => (new PagoObservado($this->orden, 'Falta el detalle.'))->render(),
            'pago rechazado' => (new PagoRechazado($this->orden, 'No corresponde.'))->render(),
            'pago aprobado' => (new PagoAprobado($this->orden))->render(),
            'recordatorio de reserva' => (new RecordatorioDeReserva($this->orden))->render(),
            'reserva vencida' => (new ReservaVencida($this->orden))->render(),
        ];
    }

    public function test_estan_los_siete_correos_del_mvp(): void
    {
        foreach ([
            EnlaceDeAcceso::class, OrdenConfirmada::class, ComprobanteRecibido::class,
            PagoObservado::class, PagoAprobado::class, RecordatorioDeReserva::class,
            ReservaVencida::class,
        ] as $clase) {
            $this->assertTrue(class_exists($clase), "Falta el correo {$clase}");
        }
    }

    public function test_todos_llevan_el_nombre_del_evento(): void
    {
        foreach ($this->correosDeOrden() as $nombre => $html) {
            $this->assertStringContainsString(
                'Seminario de Prueba 2026',
                $html,
                "El correo '{$nombre}' no nombra el evento.",
            );
        }
    }

    public function test_todos_dicen_no_responder_y_traen_el_contacto_del_evento(): void
    {
        $this->event->update([
            'contact_name' => 'Flor Vidal Oliva', 'contact_role' => 'Administración y Atención Clientes', 'contact_organization' => 'Corporación Liderazgo y Calidad Educacional',
            'contact_email' => 'flor@visionactiva.cl', 'contact_phone' => '+56911112222', 'contact_whatsapp' => '+56933334444', // legacy "+" still works
        ]);
        $this->orden->refresh();

        foreach ($this->correosDeOrden() as $nombre => $html) {
            $this->assertStringContainsString('no lo respondas', $html, "El correo '{$nombre}' no dice que no se responda.");
            // One line per field, in order: name, position, organization, phone.
            $this->assertMatchesRegularExpression(
                '/Flor Vidal Oliva.*<br>\s*Administración y Atención Clientes<br>\s*Corporación Liderazgo y Calidad Educacional<br>\s*Teléfono: \+56 9 1111 2222/s',
                $html,
                "El correo '{$nombre}' no ordena el contacto por líneas.",
            );
            $this->assertStringContainsString('flor@visionactiva.cl', $html, "El correo '{$nombre}' no trae el correo de contacto.");
            $this->assertStringContainsString('https://wa.me/56933334444', $html, "El correo '{$nombre}' no trae el WhatsApp.");
        }
    }

    public function test_la_reserva_vencida_pone_el_contacto_junto_al_pedido_de_escribir(): void
    {
        $this->event->update(['contact_name' => 'Flor Vidal Oliva', 'contact_phone' => '56951887769', 'contact_email' => 'administracion@visionactiva.cl']);

        $html = (new ReservaVencida($this->orden->fresh()))->render();
        $pedido = mb_strpos($html, 'contáctanos y revisamos la disponibilidad');

        $this->assertNotFalse($pedido);
        // The contact comes right after the request, before the button and the footer.
        $this->assertLessThan(mb_strpos($html, 'Ver mi inscripción'), mb_strpos($html, 'Flor Vidal Oliva', $pedido));
    }

    public function test_el_enlace_saluda_por_su_nombre_a_quien_pidio_el_programa(): void
    {
        ProgramRequest::factory()->for($this->event)->create(['email' => 'ana@colegio.cl', 'first_name' => 'Ana']);

        app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl');

        Mail::assertQueued(EnlaceDeAcceso::class, fn (EnlaceDeAcceso $correo): bool => str_contains($correo->render(), 'Hola Ana,'));
    }

    public function test_todos_llevan_el_numero_de_inscripcion(): void
    {
        $numero = $this->orden->number;

        foreach ($this->correosDeOrden() as $nombre => $html) {
            $this->assertStringContainsString(
                $numero,
                $html,
                "El correo '{$nombre}' no lleva el número de inscripción.",
            );
        }
    }

    public function test_todos_llevan_un_enlace_seguro_de_seguimiento(): void
    {
        foreach ($this->correosDeOrden() as $nombre => $html) {
            $this->assertMatchesRegularExpression(
                // Solo cuenta el enlace con token: la página pública del evento no lleva a la orden.
                '#/i/(o|acceso)/[A-Za-z0-9]{20,}#',
                $html,
                "El correo '{$nombre}' no ofrece un enlace de seguimiento.",
            );
        }
    }

    public function test_ninguno_expone_datos_que_no_corresponden(): void
    {
        foreach ($this->correosDeOrden() as $nombre => $html) {
            // Los datos bancarios de la organización solo van donde hay que pagar.
            $this->assertStringNotContainsString(
                'password',
                mb_strtolower($html),
                "El correo '{$nombre}' menciona contraseñas y este sistema no las usa.",
            );
        }
    }

    public function test_los_correos_de_pago_dicen_el_proximo_paso(): void
    {
        $this->orden->forceFill(['payment_status' => PaymentStatus::Observado])->save();

        $observado = (new PagoObservado($this->orden->fresh(), 'El monto no coincide.'))->render();

        $this->assertStringContainsString('El monto no coincide.', $observado);
        $this->assertStringContainsString('comprobante corregido', $observado);
    }

    public function test_el_correo_del_programa_lleva_el_enlace_a_la_inscripcion(): void
    {
        $solicitud = ProgramRequest::factory()->create(['event_id' => $this->event->id]);

        $html = (new ProgramaDelEvento($solicitud))->render();

        $this->assertStringContainsString($this->event->name, $html);
        $this->assertStringContainsString('/i/'.$this->event->slug, $html);
    }

    public function test_la_credencial_del_participante_lleva_su_codigo_de_respaldo(): void
    {
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($this->orden->fresh());

        $ticket = $this->orden->fresh()->tickets()->vigentes()->first();
        $html = (new CredencialParticipante($ticket))->render();

        $this->assertStringContainsString($ticket->code, $html);
        $this->assertStringContainsString('/t/', $html);
        $this->assertStringContainsString($this->event->name, $html);
    }

    public function test_la_credencial_del_participante_no_trae_los_pasos_de_compra_y_si_el_programa(): void
    {
        Storage::fake('documentos');
        Storage::disk('documentos')->put('programas/programa.pdf', '%PDF-1.4');
        $this->event->attachments()->create([
            'disk' => 'documentos', 'path' => 'programas/programa.pdf', 'original_name' => 'Programa.pdf',
            'mime_type' => 'application/pdf', 'size' => 8,
        ]);
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($this->orden->fresh());

        $correo = new CredencialParticipante($this->orden->fresh()->tickets()->vigentes()->first());
        $html = $correo->render();

        $this->assertStringNotContainsString('Transfieres y subes el comprobante', $html);
        $this->assertStringContainsString('Presenta tu código QR para ingresar', $html);
        $this->assertStringContainsString('no es transferible', $html);
        $this->assertSame(['Programa.pdf'], array_map(fn ($adjunto) => $adjunto->as, $correo->attachments()));
    }
}
