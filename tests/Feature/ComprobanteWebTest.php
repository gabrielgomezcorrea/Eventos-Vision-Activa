<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\AvisoComprobantePorRevisar;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComprobanteWebTest extends TestCase
{
    use RefreshDatabase;

    private Order $orden;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));

        $event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
        $jornada = $event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 10]);
        $acceso = $event->accessTypes()->create(['name' => 'J1', 'position' => 1, 'price' => 90000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $event->id,
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Carlos', 'access_type_id' => $acceso->id]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());
        [, $this->token] = MagicLink::emitir($event, 'ana@colegio.cl', $this->orden);
    }

    private function ruta(string $nombre, array $extra = []): string
    {
        return route("inscripcion.{$nombre}", ['token' => $this->token] + $extra);
    }

    public function test_una_reserva_vencida_muestra_a_quien_contactar_ahi_mismo(): void
    {
        $this->orden->event->update(['contact_name' => 'Flor Vidal Oliva', 'contact_phone' => '56951887769', 'contact_email' => 'administracion@visionactiva.cl']);
        $this->orden->update(['status' => OrderStatus::Vencida]);

        $this->get($this->ruta('portal.pago'))
            ->assertOk()
            ->assertSeeInOrder(['La reserva de cupos venció', 'Flor Vidal Oliva', '+56 9 5188 7769', 'administracion@visionactiva.cl']);
    }

    public function test_la_pantalla_de_estado_ofrece_informar_el_pago(): void
    {
        $html = $this->get($this->ruta('portal.pago'))
            ->assertOk()
            ->assertSee('name="proof"', escape: false)
            ->getContent();

        // El cliente entra a pagar: la subida del comprobante va antes del
        // detalle de la inscripción, no al final de la página.
        $this->assertLessThan(
            strpos($html, 'Detalle'),
            strpos($html, 'name="proof"'),
            'El formulario para informar el pago quedó debajo del detalle.',
        );
    }

    public function test_sube_el_comprobante_desde_la_web(): void
    {
        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
            'bank_name' => 'BancoEstado',
            'payer_name' => 'Fundación Educar',
            'payer_rut' => '76.086.428-5',
            'proof' => UploadedFile::fake()->create('comprobante.pdf', 200, 'application/pdf'),
        ])->assertRedirect($this->ruta('portal.pago', ['comprobante' => 'recibido']));

        $this->assertSame(PaymentStatus::EnValidacion, $this->orden->fresh()->payment_status);

        $this->get($this->ruta('portal.pago', ['comprobante' => 'recibido']))
            ->assertSee('Se ha subido correctamente tu comprobante.');

        $this->get($this->ruta('portal.pago'))
            ->assertOk()
            ->assertSee('Comprobante en validación')
            ->assertDontSee('Informar el pago');
    }

    public function test_el_comprobante_del_cliente_avisa_a_contabilidad_suscrita(): void
    {
        $gloria = $this->usuarioConRol(Rol::Contabilidad, ['notify_payment_proofs' => true]);
        $this->usuarioConRol(Rol::Contabilidad, ['notify_payment_proofs' => false]);

        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
            'bank_name' => 'BancoEstado',
            'payer_name' => 'Fundación Educar',
            'payer_rut' => '76.086.428-5',
            'proof' => UploadedFile::fake()->create('comprobante.pdf', 200, 'application/pdf'),
        ]);

        Mail::assertQueued(AvisoComprobantePorRevisar::class, 1);
        Mail::assertQueued(AvisoComprobantePorRevisar::class, fn (AvisoComprobantePorRevisar $m): bool => $m->hasTo($gloria->email)
            && str_contains($m->envelope()->subject, 'Comprobante por revisar:')
            && str_contains($m->render(), '$90.000'));
    }

    public function test_un_comprobante_rechazado_por_validacion_no_avisa(): void
    {
        $this->usuarioConRol(Rol::Contabilidad, ['notify_payment_proofs' => true]);

        $this->post($this->ruta('comprobante'), ['amount' => 90000, 'paid_on' => now()->toDateString()])->assertOk();

        Mail::assertNotQueued(AvisoComprobantePorRevisar::class);
    }

    public function test_con_un_comprobante_en_revision_se_oculta_el_formulario(): void
    {
        $this->post($this->ruta('comprobante'), [
            'amount' => 50000, 'paid_on' => now()->toDateString(), 'bank_name' => 'BancoEstado',
            'payer_name' => 'Fundación Educar', 'payer_rut' => '76.086.428-5',
            'proof' => UploadedFile::fake()->create('comprobante.pdf', 200, 'application/pdf'),
        ]);

        $this->get($this->ruta('portal.pago'))
            ->assertOk()
            ->assertSee('Ya hay un comprobante en revisión.')
            ->assertSee('Monto enviado: $50.000', false)
            ->assertDontSee('name="proof"', false)
            ->assertDontSee('Enviar comprobante');
    }

    public function test_exige_el_archivo(): void
    {
        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
        ])->assertOk()->assertSee('Adjunta el comprobante');

        $this->assertSame(PaymentStatus::Pendiente, $this->orden->fresh()->payment_status);
    }

    public function test_rechaza_un_archivo_de_tipo_no_permitido(): void
    {
        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
            'proof' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
        ])->assertOk()->assertSee('debe ser un PDF o una imagen');

        $this->assertSame(0, $this->orden->fresh()->payments()->count());
    }

    public function test_rechaza_una_fecha_de_transferencia_futura(): void
    {
        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->addWeek()->toDateString(),
            'proof' => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        ])->assertOk()->assertSee('no puede ser futura');
    }

    public function test_conserva_los_datos_ante_un_error(): void
    {
        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
            'bank_name' => 'Banco de Chile',
            'payer_rut' => '76.086.428-1',  // dígito verificador incorrecto
            'proof' => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        ])
            ->assertOk()
            ->assertSee('dígito verificador')
            ->assertSee('value="Banco de Chile"', escape: false);
    }

    public function test_normaliza_el_rut_del_pagador(): void
    {
        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
            'bank_name' => 'BancoEstado',
            'payer_name' => 'Fundación Educar',
            'payer_rut' => '76.086.428-5',
            'proof' => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        ])->assertRedirect($this->ruta('portal.pago', ['comprobante' => 'recibido']));

        $this->assertSame('76086428-5', $this->orden->fresh()->pagoVigente()->payer_rut);
    }

    public function test_un_token_ajeno_no_permite_subir_comprobantes(): void
    {
        $this->post(route('inscripcion.comprobante', ['token' => 'inventado']), [
            'amount' => 90000,
            'proof' => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_si_falla_otro_campo_el_comprobante_no_se_vuelve_a_pedir(): void
    {
        $html = $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'bank_name' => 'BancoEstado',
            'payer_name' => 'Fundación Educar',
            'payer_rut' => '76.086.428-5',
            // Contenido de PDF real: al reusarlo, el tipo se lee del archivo.
            'proof' => UploadedFile::fake()->createWithContent('comprobante.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
        ])->assertOk()
            ->assertSee('comprobante.pdf')
            ->getContent();

        preg_match('/name="proof_guardado" value="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1] ?? null, 'El archivo no quedó guardado para el siguiente intento.');

        // Segundo intento: solo se agrega la fecha, sin volver a subir el archivo.
        $this->post($this->ruta('comprobante'), [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
            'bank_name' => 'BancoEstado',
            'payer_name' => 'Fundación Educar',
            'payer_rut' => '76.086.428-5',
            'proof_guardado' => html_entity_decode($m[1]),
        ])->assertRedirect($this->ruta('portal.pago', ['comprobante' => 'recibido']));

        $pago = $this->orden->fresh()->pagoVigente();
        $this->assertSame('comprobante.pdf', $pago->proofs->sole()->original_name);
    }
}
