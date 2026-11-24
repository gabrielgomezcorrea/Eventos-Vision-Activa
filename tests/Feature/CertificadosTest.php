<?php

namespace Tests\Feature;

use App\Actions\IniciarEnvioDeCertificados;
use App\Enums\CertificateCampaignStatus;
use App\Enums\CertificateStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Jobs\EnviarCertificado;
use App\Mail\CertificadoDeParticipacion;
use App\Models\Certificate;
use App\Models\CertificateCampaign;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Support\Certificados\EmparejadorDeCertificados;
use App\Support\Certificados\LectorDeCertificados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Certificates made outside the system (24/11/2026): what hurts if it breaks is
 * emailing someone twice, emailing the wrong person, sending in a burst that
 * burns the mail account, or letting a role without permission in.
 */
class CertificadosTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.private_disk'));

        $this->evento = Event::factory()->create(['name' => 'Seminario de Certificados']);
        $orden = Order::factory()->create(['event_id' => $this->evento->id, 'payment_status' => PaymentStatus::Aprobado]);

        foreach ([['Ingrid Marlene', 'Horning Saldías'], ['Paola Andrea', 'Inostroza Muñoz'], ['Paola Andrea', 'Morales Jofré']] as [$nombre, $apellidos]) {
            Participant::factory()->create(['order_id' => $orden->id, 'first_name' => $nombre, 'last_name' => $apellidos, 'email' => strtolower(explode(' ', $nombre)[0]).'.'.strtolower(explode(' ', $apellidos)[0]).'@colegio.cl']);
        }
    }

    /** A minimal one-page PDF with the given lines, like the certificates: same template, only the name changes. */
    private function pdf(string $nombre, string $archivo): UploadedFile
    {
        $lineas = ['CERTIFICADO DE ACREDITACION', 'Es otorgado a Sr(a):', 'Por su destacada participacion en:', 'Pablo Rodriguez Araneda', 'Presidente', $nombre];
        $flujo = 'BT /F1 12 Tf 50 700 Td 14 TL '.implode(' ', array_map(fn ($l) => '('.$l.') Tj T*', $lineas)).' ET';
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($flujo)." >>\nstream\n".$flujo."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $posiciones = [];

        foreach ($objetos as $i => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$objeto."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";

        foreach ($posiciones as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }

        $pdf .= 'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return UploadedFile::fake()->createWithContent($archivo, $pdf);
    }

    private function subir(array $archivos): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->post(route('eventos.certificados.store', $this->evento), ['certificados' => $archivos])
            ->assertRedirect();
    }

    public function test_lee_el_nombre_impreso_y_no_el_del_archivo(): void
    {
        $this->subir([
            $this->pdf('Ingrid Marlene Itha Horning Saldias', '1_Indrid Marlene Horning.pdf'),
            $this->pdf('Paola Andrea Inostroza Munoz', '2_Paola.pdf'),
        ]);

        $ingrid = Certificate::where('original_name', '1_Indrid Marlene Horning.pdf')->firstOrFail();

        $this->assertSame('Ingrid Marlene Itha Horning Saldias', $ingrid->detected_name);
        $this->assertSame('Ingrid Marlene', $ingrid->participant?->first_name);
        $this->assertSame(CertificateStatus::Listo, $ingrid->status);
    }

    public function test_los_apellidos_separan_a_dos_personas_con_el_mismo_nombre(): void
    {
        $emparejador = app(EmparejadorDeCertificados::class);
        $resultado = $emparejador->emparejar('Paola Andrea Morales Jofré', $emparejador->candidatos($this->evento));

        $this->assertSame('Morales Jofré', $resultado['participante']?->last_name);
        $this->assertTrue($resultado['seguro']);
    }

    public function test_un_nombre_que_no_calza_queda_por_revisar_sin_persona(): void
    {
        $this->subir([$this->pdf('Juan Carlos Perez Soto', 'a.pdf'), $this->pdf('Ingrid Marlene Horning Saldias', 'b.pdf')]);

        $juan = Certificate::where('original_name', 'a.pdf')->firstOrFail();

        $this->assertNull($juan->participant_id);
        $this->assertSame(CertificateStatus::PorRevisar, $juan->status);
    }

    public function test_el_mismo_archivo_subido_dos_veces_no_se_duplica(): void
    {
        $archivo = $this->pdf('Ingrid Marlene Horning Saldias', 'ingrid.pdf');

        $this->subir([$archivo, $this->pdf('Paola Andrea Inostroza Munoz', 'paola.pdf')]);
        $this->subir([$archivo]);

        $this->assertSame(2, Certificate::count());
    }

    public function test_el_lector_usa_el_nombre_del_archivo_si_hay_un_solo_pdf(): void
    {
        $this->assertSame('Ricardo Patricio Durán', app(LectorDeCertificados::class)->nombreDesdeArchivo('12_Ricardo Patricio Durán.pdf'));
    }

    public function test_sin_permiso_no_se_entra_ni_se_sube_ni_se_envia(): void
    {
        $contabilidad = $this->usuarioConRol(Rol::Contabilidad);

        $this->actingAs($contabilidad)->get(route('eventos.certificados.index', $this->evento))->assertForbidden();
        $this->actingAs($contabilidad)->post(route('eventos.certificados.store', $this->evento), ['certificados' => [$this->pdf('X Y', 'x.pdf')]])->assertForbidden();
        $this->actingAs($contabilidad)->post(route('eventos.certificados.enviar', $this->evento))->assertForbidden();
    }

    public function test_no_se_asigna_a_alguien_de_otro_evento(): void
    {
        $this->subir([$this->pdf('Juan Perez', 'a.pdf'), $this->pdf('Ingrid Marlene Horning Saldias', 'b.pdf')]);
        $ajeno = Participant::factory()->create(['order_id' => Order::factory()->create(['payment_status' => PaymentStatus::Aprobado])->id]);

        $this->actingAs($this->usuarioConRol())
            ->patch(route('eventos.certificados.asignar', [$this->evento, Certificate::where('original_name', 'a.pdf')->first()]), ['participant_id' => $ajeno->id])
            ->assertSessionHasErrors('participant_id');
    }

    private function certificadoListo(Participant $persona): Certificate
    {
        Storage::disk(config('filesystems.private_disk'))->put('certificados/x-'.$persona->id.'.pdf', '%PDF-1.4');

        return Certificate::create([
            'event_id' => $this->evento->id, 'participant_id' => $persona->id, 'disk' => config('filesystems.private_disk'),
            'path' => 'certificados/x-'.$persona->id.'.pdf', 'original_name' => 'x.pdf', 'file_hash' => hash('sha256', (string) $persona->id),
            'status' => CertificateStatus::Listo,
        ]);
    }

    public function test_el_envio_suelta_uno_por_turno_y_respeta_el_espaciado(): void
    {
        Bus::fake();
        Participant::all()->each(fn (Participant $p) => $this->certificadoListo($p));

        $this->assertSame(3, app(IniciarEnvioDeCertificados::class)($this->evento, null));
        // A second click queues nothing more.
        $this->assertSame(0, app(IniciarEnvioDeCertificados::class)($this->evento, null));

        $this->artisan('certificados:enviar')->assertSuccessful();
        $this->artisan('certificados:enviar')->assertSuccessful();
        Bus::assertDispatchedTimes(EnviarCertificado::class, 1);

        $this->travel(7)->minutes();
        $this->artisan('certificados:enviar')->assertSuccessful();
        Bus::assertDispatchedTimes(EnviarCertificado::class, 2);
    }

    public function test_el_tope_diario_detiene_el_envio_hasta_el_dia_siguiente(): void
    {
        Bus::fake();
        config(['certificados.daily_limit' => 1]);
        $personas = Participant::all();
        $enviado = $this->certificadoListo($personas[0]);
        $enviado->forceFill(['status' => CertificateStatus::Enviado, 'sent_at' => now()])->save();
        $this->certificadoListo($personas[1]);

        app(IniciarEnvioDeCertificados::class)($this->evento, null);
        $this->artisan('certificados:enviar');

        Bus::assertNotDispatched(EnviarCertificado::class);
    }

    public function test_un_certificado_nunca_se_envia_dos_veces(): void
    {
        Mail::fake();
        $certificado = $this->certificadoListo(Participant::first());
        app(IniciarEnvioDeCertificados::class)($this->evento, null);

        (new EnviarCertificado($certificado->id))->handle();
        (new EnviarCertificado($certificado->id))->handle();

        Mail::assertSentCount(1);
        $this->assertSame(CertificateCampaignStatus::Terminada, CertificateCampaign::first()->status);
        $this->assertSame(CertificateStatus::Enviado, $certificado->fresh()->status);
        $this->assertSame(Participant::first()->email, $certificado->fresh()->sent_to);
    }

    public function test_sin_correo_valido_no_entra_al_envio(): void
    {
        $persona = Participant::first();
        $persona->forceFill(['email' => null])->save();
        $this->certificadoListo($persona);

        $this->assertSame(0, app(IniciarEnvioDeCertificados::class)($this->evento, null));
    }

    public function test_tres_fallos_seguidos_pausan_el_envio(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('535 Username and Password not accepted'));
        $certificados = Participant::all()->map(fn (Participant $p) => $this->certificadoListo($p));
        app(IniciarEnvioDeCertificados::class)($this->evento, null);

        $certificados->each(fn (Certificate $c) => (new EnviarCertificado($c->id))->handle());

        $this->assertSame(CertificateCampaignStatus::Pausada, CertificateCampaign::first()->status);
        $this->assertSame(CertificateStatus::Fallido, $certificados[0]->fresh()->status);
    }

    public function test_el_correo_adjunta_el_certificado_y_trae_el_contacto_del_evento(): void
    {
        $this->evento->update(['contact_email' => 'flor@visionactiva.cl']);
        $certificado = $this->certificadoListo(Participant::first());

        $correo = new CertificadoDeParticipacion($certificado->fresh(['event', 'participant']));

        $this->assertStringContainsString('Seminario de Certificados', $correo->render());
        $this->assertStringContainsString('no lo respondas', $correo->render());
        $this->assertStringContainsString('flor@visionactiva.cl', $correo->render());
        $this->assertSame('Certificado - Ingrid Marlene Horning Saldías.pdf', $certificado->fresh('participant')->nombreDelAdjunto());
        $this->assertCount(1, $correo->attachments());
    }
}
