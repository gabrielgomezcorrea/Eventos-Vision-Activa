<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ComprobanteNoAceptado;
use App\Mail\ComprobanteRecibido;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComprobanteDePagoTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private AccessType $acceso;

    private Order $orden;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
        $jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 50]);
        $this->acceso = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $this->acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $this->orden = $this->ordenConfirmada();
    }

    private function ordenConfirmada(): Order
    {
        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana Pérez',
            'responsible_email' => 'ana'.Order::count().'@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Carlos', 'access_type_id' => $this->acceso->id]);

        return app(ConfirmarOrden::class)($orden->fresh());
    }

    private function archivo(): UploadedFile
    {
        return UploadedFile::fake()->create('transferencia.pdf', 120, 'application/pdf');
    }

    private function registrar(array $extra = []): Payment
    {
        return app(RegistrarComprobante::class)(
            $this->orden,
            array_merge(['amount' => 90000, 'paid_on' => now()->toDateString()], $extra),
            $this->archivo(),
            actorLabel: $this->orden->responsible_email,
        );
    }

    public function test_registra_el_pago_y_deja_la_orden_en_validacion(): void
    {
        $pago = $this->registrar(['bank_name' => 'BancoEstado', 'payer_name' => 'Fundación Educar']);

        $this->assertSame(PaymentStatus::EnValidacion, $pago->status);
        $this->assertSame(90000, $pago->amount);
        $this->assertSame(PaymentStatus::EnValidacion, $this->orden->fresh()->payment_status);

        // El ciclo de vida de la orden no cambia por informar un pago.
        $this->assertSame(OrderStatus::Reservada, $this->orden->fresh()->status);

        Mail::assertQueued(ComprobanteRecibido::class);
    }

    public function test_el_archivo_va_al_disco_privado_y_la_base_solo_guarda_metadata(): void
    {
        $pago = $this->registrar();
        $comprobante = $pago->proofs()->sole();

        Storage::disk(config('filesystems.private_disk'))->assertExists($comprobante->path);

        $this->assertSame('transferencia.pdf', $comprobante->original_name);
        $this->assertSame('application/pdf', $comprobante->mime_type);
        $this->assertGreaterThan(0, $comprobante->size);
        $this->assertNotNull($comprobante->hash);

        // Ninguna columna guarda el contenido del archivo.
        $this->assertArrayNotHasKey('contents', $comprobante->getAttributes());
        $this->assertArrayNotHasKey('file', $comprobante->getAttributes());
    }

    public function test_congela_el_vencimiento_de_la_reserva(): void
    {
        // Regla del documento: si el comprobante llegó antes del vencimiento,
        // la reserva no expira mientras Contabilidad revisa.
        $this->registrar();

        $this->orden->fresh()->forceFill(['reserved_until' => now()->subDay()])->save();

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Reservada, $this->orden->fresh()->status);
    }

    public function test_no_acepta_un_segundo_comprobante_mientras_hay_uno_en_validacion(): void
    {
        $this->registrar();

        $this->expectException(ComprobanteNoAceptado::class);
        $this->registrar();
    }

    public function test_no_acepta_comprobante_sobre_un_borrador(): void
    {
        $borrador = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Beto',
            'responsible_email' => 'beto@colegio.cl',
        ]);

        try {
            app(RegistrarComprobante::class)($borrador, ['amount' => 1000], $this->archivo());
            $this->fail('Debio rechazar el comprobante.');
        } catch (ComprobanteNoAceptado $e) {
            $this->assertStringContainsString('Confirma la inscripción', $e->getMessage());
        }
    }

    public function test_no_acepta_comprobante_si_el_pago_ya_fue_aprobado(): void
    {
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();

        try {
            $this->registrar();
            $this->fail('Debio rechazarlo.');
        } catch (ComprobanteNoAceptado $e) {
            $this->assertStringContainsString('ya fue aprobado', $e->getMessage());
        }
    }

    public function test_detecta_diferencia_de_monto(): void
    {
        $pago = $this->registrar(['amount' => 80000]);

        $this->assertTrue($pago->tieneDiferenciaDeMonto());
        $this->assertSame(-10000, $pago->diferencia());

        $exacto = $this->ordenConfirmada();
        $otro = app(RegistrarComprobante::class)($exacto, ['amount' => 90000], $this->archivo());

        $this->assertFalse($otro->tieneDiferenciaDeMonto());
    }

    public function test_registra_quien_lo_carga(): void
    {
        $usuario = User::factory()->create(['name' => 'Flor Coordinación']);

        $pago = app(RegistrarComprobante::class)(
            $this->orden,
            ['amount' => 90000],
            $this->archivo(),
            usuario: $usuario,
        );

        $this->assertSame($usuario->id, $pago->submitted_by_user_id);
        $this->assertSame('Flor Coordinación', $pago->submitted_by_label);
        $this->assertSame('Flor Coordinación', $pago->proofs()->sole()->uploaded_by_label);
    }

    public function test_queda_registrado_en_auditoria(): void
    {
        $this->registrar();

        $log = AuditLog::where('action', 'comprobante.recibido')->sole();

        $this->assertSame('pending', $log->old_status);
        $this->assertSame('in_review', $log->new_status);
        $this->assertSame(90000, $log->properties['monto']);
    }
}
