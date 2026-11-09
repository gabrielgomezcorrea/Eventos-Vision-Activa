<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentReviewAction;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\AvisoComprobantePorRevisar;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pago a crédito: la inscripción hace de orden de compra. Sin archivo, por el
 * saldo, solo sin abonos previos, y las credenciales salen recién cuando
 * Contabilidad lo aprueba.
 */
class PagoACreditoTest extends TestCase
{
    use RefreshDatabase;

    private Order $orden;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));

        $event = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
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

    private function contadora(): User
    {
        return $this->usuarioConRol(Rol::Contabilidad, ['notify_payment_proofs' => true]);
    }

    public function test_la_pestana_pago_ofrece_contado_y_credito(): void
    {
        $this->get(route('inscripcion.portal.pago', ['token' => $this->token]))
            ->assertOk()
            ->assertSee('¿Cómo pagarás?')
            ->assertSee('Pago al contado')
            ->assertSee('Pago a crédito')
            ->assertSee('N° de orden de compra');
    }

    public function test_a_credito_se_envia_sin_archivo_y_contabilidad_lo_aprueba_y_salen_las_credenciales(): void
    {
        $contadora = $this->contadora();

        $this->post(route('inscripcion.pago-a-credito', ['token' => $this->token]), ['purchase_order' => '  OC 4500-123  '])
            ->assertRedirect(route('inscripcion.portal.pago', ['token' => $this->token, 'comprobante' => 'credito']));

        $pago = $this->orden->payments()->sole();
        $this->assertSame(PaymentMethod::Credito, $pago->method);
        $this->assertSame(PaymentStatus::EnValidacion, $pago->status);
        $this->assertSame(90000, $pago->amount);
        $this->assertSame('OC 4500-123', $pago->purchase_order);
        $this->assertSame(0, $pago->proofs()->count());
        $this->assertSame(PaymentStatus::EnValidacion, $this->orden->fresh()->payment_status);
        $this->assertSame(0, Ticket::count(), 'Sin aprobar no hay credenciales.');
        Mail::assertQueued(AvisoComprobantePorRevisar::class, fn ($m) => $m->hasTo($contadora->email));

        $this->get(route('inscripcion.portal.pago', ['token' => $this->token]))
            ->assertOk()->assertSee('Pago a crédito en revisión')->assertDontSee('¿Cómo pagarás?');

        app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contadora);

        $this->assertSame(PaymentStatus::Aprobado, $this->orden->fresh()->payment_status);
        $this->assertSame(1, Ticket::count());
    }

    public function test_la_orden_de_compra_es_opcional(): void
    {
        $this->post(route('inscripcion.pago-a-credito', ['token' => $this->token]), [])->assertRedirect();

        $this->assertNull($this->orden->payments()->sole()->purchase_order);
    }

    public function test_con_abonos_aprobados_no_se_puede_pasar_a_credito(): void
    {
        $abono = app(RegistrarComprobante::class)(
            $this->orden, ['amount' => 40000], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        );
        app(RevisarPago::class)($abono, PaymentReviewAction::Aprobar, $this->contadora());

        $this->post(route('inscripcion.pago-a-credito', ['token' => $this->token]), [])
            ->assertOk()->assertSee('Ya tienes abonos al contado');

        $this->assertSame(0, $this->orden->payments()->where('method', PaymentMethod::Credito)->count());
    }

    public function test_no_se_envia_dos_veces_mientras_esta_en_revision(): void
    {
        $this->post(route('inscripcion.pago-a-credito', ['token' => $this->token]), []);
        $this->post(route('inscripcion.pago-a-credito', ['token' => $this->token]), [])->assertOk();

        $this->assertSame(1, $this->orden->payments()->count());
    }

    public function test_un_borrador_no_puede_pagar_a_credito(): void
    {
        $borrador = Order::create(['event_id' => $this->orden->event_id, 'responsible_email' => 'b@colegio.cl', 'responsible_name' => 'B']);
        [, $token] = MagicLink::emitir($this->orden->event, 'b@colegio.cl', $borrador);

        $this->post(route('inscripcion.pago-a-credito', ['token' => $token]), [])->assertOk();

        $this->assertSame(0, $borrador->payments()->count());
    }

    public function test_los_pagos_anteriores_quedan_como_contado(): void
    {
        $abono = app(RegistrarComprobante::class)(
            $this->orden, ['amount' => 40000], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
        );

        $this->assertSame('Pago al contado', $abono->fresh()->method->label());
    }
}
