<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\ReservaVencida;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExpirarReservasTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventSession $jornada;

    private AccessType $acceso;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026',
            'status' => EventStatus::Publicado, 'reservation_duration_value' => 3,
        ]);
        $this->jornada = $this->event->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 10]);
        $this->acceso = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $this->acceso->sessions()->sync([$this->jornada->id => ['seats' => 1]]);
    }

    private function ordenReservada(int $participantes = 2): Order
    {
        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana'.Order::count().'@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);

        for ($i = 0; $i < $participantes; $i++) {
            $orden->participants()->create(['first_name' => 'P'.$i, 'access_type_id' => $this->acceso->id]);
        }

        return app(ConfirmarOrden::class)($orden->fresh());
    }

    public function test_vence_la_reserva_y_devuelve_los_cupos(): void
    {
        $orden = $this->ordenReservada(2);
        $this->assertSame(2, $this->jornada->fresh()->reserved_seats);

        $orden->forceFill(['reserved_until' => now()->subHour()])->save();

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Vencida, $orden->fresh()->status);
        $this->assertSame(0, $this->jornada->fresh()->reserved_seats);
        Mail::assertQueued(ReservaVencida::class);
    }

    public function test_no_vence_una_reserva_dentro_de_plazo(): void
    {
        $orden = $this->ordenReservada();

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Reservada, $orden->fresh()->status);
        $this->assertSame(2, $this->jornada->fresh()->reserved_seats);
    }

    public function test_no_vence_si_el_comprobante_esta_en_validacion(): void
    {
        // Regla del documento funcional: si el comprobante se cargó antes del
        // vencimiento, la reserva no expira mientras Contabilidad revisa.
        $orden = $this->ordenReservada();
        $orden->forceFill([
            'reserved_until' => now()->subDay(),
            'payment_status' => PaymentStatus::EnValidacion,
        ])->save();

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Reservada, $orden->fresh()->status);
        $this->assertSame(2, $this->jornada->fresh()->reserved_seats);
        Mail::assertNotQueued(ReservaVencida::class);
    }

    public function test_no_vence_una_orden_con_pago_aprobado(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill([
            'reserved_until' => now()->subDay(),
            'payment_status' => PaymentStatus::Aprobado,
        ])->save();

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Reservada, $orden->fresh()->status);
    }

    public function test_un_pago_observado_si_deja_vencer(): void
    {
        // Observado significa que la pelota está del lado del cliente.
        $orden = $this->ordenReservada();
        $orden->forceFill([
            'reserved_until' => now()->subDay(),
            'payment_status' => PaymentStatus::Observado,
        ])->save();

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Vencida, $orden->fresh()->status);
    }

    public function test_el_modo_dry_run_no_modifica_nada(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->subHour()])->save();

        $this->artisan('reservas:expirar', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(OrderStatus::Reservada, $orden->fresh()->status);
        $this->assertSame(2, $this->jornada->fresh()->reserved_seats);
        Mail::assertNotQueued(ReservaVencida::class);
    }

    public function test_los_cupos_liberados_quedan_disponibles_de_nuevo(): void
    {
        $this->jornada->update(['capacity' => 2]);

        $orden = $this->ordenReservada(2);
        $this->assertSame(0, $this->jornada->fresh()->cuposDisponibles());

        $orden->forceFill(['reserved_until' => now()->subHour()])->save();
        $this->artisan('reservas:expirar');

        $this->assertSame(2, $this->jornada->fresh()->cuposDisponibles());

        // Y otra orden puede tomarlos.
        $nueva = $this->ordenReservada(2);
        $this->assertSame(OrderStatus::Reservada, $nueva->status);
    }

    public function test_correr_dos_veces_no_devuelve_cupos_dos_veces(): void
    {
        $orden = $this->ordenReservada(2);
        $orden->forceFill(['reserved_until' => now()->subHour()])->save();

        $this->artisan('reservas:expirar');
        $this->artisan('reservas:expirar');

        $this->assertSame(0, $this->jornada->fresh()->reserved_seats);
    }
}
