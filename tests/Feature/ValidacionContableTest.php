<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentReviewAction;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Exceptions\RevisionNoValida;
use App\Mail\PagoAprobado;
use App\Mail\PagoObservado;
use App\Mail\PagoRechazado;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ValidacionContableTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private AccessType $acceso;

    private Order $orden;

    private Payment $pago;

    private User $contadora;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
        $jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 50]);
        $this->acceso = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $this->acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Carlos', 'access_type_id' => $this->acceso->id]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());

        $this->pago = app(RegistrarComprobante::class)(
            $this->orden,
            ['amount' => 90000, 'paid_on' => now()->toDateString()],
            UploadedFile::fake()->create('t.pdf', 100, 'application/pdf'),
        );

        $this->contadora = User::factory()->create(['name' => 'Flor Contabilidad', 'is_active' => true]);
        $this->contadora->assignRole(Rol::Contabilidad->value);
        $this->contadora = $this->contadora->fresh();
    }

    private function revisar(PaymentReviewAction $accion, ?string $comentario = null): Payment
    {
        return app(RevisarPago::class)($this->pago->fresh(), $accion, $this->contadora, $comentario);
    }

    public function test_aprobar_deja_el_pago_aprobado_y_notifica(): void
    {
        $pago = $this->revisar(PaymentReviewAction::Aprobar);

        $this->assertSame(PaymentStatus::Aprobado, $pago->status);
        $this->assertNotNull($pago->reviewed_at);
        $this->assertSame(PaymentStatus::Aprobado, $this->orden->fresh()->payment_status);

        Mail::assertQueued(PagoAprobado::class, fn ($m) => $m->hasTo('ana@colegio.cl'));
    }

    public function test_observar_no_altera_el_ciclo_de_vida_de_la_orden(): void
    {
        // Una observación devuelve la pelota al cliente, no cancela ni vence
        // la inscripción.
        $this->revisar(PaymentReviewAction::Observar, 'El monto no coincide con el total.');

        $orden = $this->orden->fresh();

        $this->assertSame(PaymentStatus::Observado, $orden->payment_status);
        $this->assertSame(OrderStatus::Reservada, $orden->status, 'El ciclo de vida no debe cambiar.');
        $this->assertNotNull($orden->reserved_until);

        Mail::assertQueued(PagoObservado::class, fn ($m) => $m->observacion === 'El monto no coincide con el total.');
    }

    public function test_rechazar_notifica_con_el_motivo(): void
    {
        $this->revisar(PaymentReviewAction::Rechazar, 'El documento corresponde a otra transferencia.');

        $this->assertSame(PaymentStatus::Rechazado, $this->orden->fresh()->payment_status);
        $this->assertSame(OrderStatus::Reservada, $this->orden->fresh()->status);

        Mail::assertQueued(PagoRechazado::class);
    }

    public function test_observar_y_rechazar_exigen_comentario(): void
    {
        foreach ([PaymentReviewAction::Observar, PaymentReviewAction::Rechazar] as $accion) {
            try {
                $this->revisar($accion, null);
                $this->fail("{$accion->value} debio exigir comentario.");
            } catch (RevisionNoValida $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        // Aprobar no lo exige.
        $this->assertSame(PaymentStatus::Aprobado, $this->revisar(PaymentReviewAction::Aprobar)->status);
    }

    public function test_no_se_revisa_dos_veces_el_mismo_comprobante(): void
    {
        $this->revisar(PaymentReviewAction::Aprobar);

        $this->expectException(RevisionNoValida::class);
        $this->revisar(PaymentReviewAction::Rechazar, 'Cambio de opinión');
    }

    public function test_guarda_quien_reviso_cuando_y_con_que_comentario(): void
    {
        $this->revisar(PaymentReviewAction::Observar, 'Falta el detalle del banco.');

        $review = $this->pago->fresh()->reviews()->sole();

        $this->assertSame($this->contadora->id, $review->user_id);
        $this->assertSame('Flor Contabilidad', $review->user_label);
        $this->assertSame(PaymentReviewAction::Observar, $review->action);
        $this->assertSame('Falta el detalle del banco.', $review->comment);
        $this->assertNotNull($review->created_at);

        $log = AuditLog::where('action', 'pago.observed')->sole();
        $this->assertSame('in_review', $log->old_status);
        $this->assertSame('observed', $log->new_status);
        $this->assertSame('Falta el detalle del banco.', $log->comment);
    }

    public function test_tras_una_observacion_el_cliente_puede_enviar_otro_comprobante(): void
    {
        $this->revisar(PaymentReviewAction::Observar, 'Monto incorrecto.');

        $this->assertTrue($this->orden->fresh()->admiteComprobante());

        $segundo = app(RegistrarComprobante::class)(
            $this->orden->fresh(),
            ['amount' => 90000],
            UploadedFile::fake()->create('t2.pdf', 100, 'application/pdf'),
        );

        $this->assertSame(PaymentStatus::EnValidacion, $this->orden->fresh()->payment_status);

        // El historial conserva ambos registros.
        $this->assertSame(2, $this->orden->fresh()->payments()->count());
        $this->assertTrue($segundo->is($this->orden->fresh()->pagoVigente()));
    }

    public function test_una_orden_observada_si_puede_vencer(): void
    {
        // Observado significa que la pelota está del lado del cliente.
        $this->revisar(PaymentReviewAction::Observar, 'Corregir monto.');

        $this->orden->fresh()->forceFill(['reserved_until' => now()->subDay()])->save();
        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Vencida, $this->orden->fresh()->status);
    }

    public function test_una_orden_con_pago_aprobado_no_vence(): void
    {
        $this->revisar(PaymentReviewAction::Aprobar);

        $this->orden->fresh()->forceFill(['reserved_until' => now()->subDay()])->save();
        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Reservada, $this->orden->fresh()->status);
    }
}
