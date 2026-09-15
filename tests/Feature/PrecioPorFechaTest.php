<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\ReactivarReserva;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El valor cambia por fecha, pero nunca para una orden ya confirmada.
 *
 * Es lo que hay que poder mostrarle a quien reclame que "el precio era otro":
 * cada orden guarda qué tarifa se le aplicó y hasta cuándo regía.
 */
class PrecioPorFechaTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    private AccessType $acceso;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->evento = Event::create([
            'name' => 'Seminario 2026',
            'slug' => 'seminario-2026',
            'status' => EventStatus::Publicado,
        ]);

        $jornada = $this->evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 50]);

        $this->acceso = $this->evento->accessTypes()->create([
            'name' => 'Curso completo',
            'position' => 1,
            'price' => 160000,
            'early_price' => 80000,
            'early_until' => now()->addWeek()->toDateString(),
        ]);

        $this->acceso->sessions()->attach($jornada->id, ['seats' => 1]);
    }

    public function test_dentro_del_plazo_paga_el_precio_anticipado(): void
    {
        $orden = $this->confirmar();

        $this->assertSame(80000, $orden->total);
        $this->assertSame('anticipado', $orden->applied_tariff);
        $this->assertNotNull($orden->applied_tariff_until);
    }

    public function test_el_ultimo_dia_todavia_alcanza_el_precio_anticipado(): void
    {
        $this->acceso->forceFill(['early_until' => now()->toDateString()])->save();

        $this->travelTo(now()->endOfDay()->subMinute());

        $this->assertSame(80000, $this->confirmar()->total);
    }

    public function test_quien_confirma_despues_del_alza_paga_el_precio_nuevo(): void
    {
        $orden = $this->borrador();

        // Empieza dentro del plazo y confirma después: se cobra el valor del
        // momento de confirmar, que es lo que estaba anunciado.
        $this->travelTo(now()->addWeeks(2));

        $confirmada = app(ConfirmarOrden::class)($orden);

        $this->assertSame(160000, $confirmada->total);
        $this->assertSame('normal', $confirmada->applied_tariff);
    }

    public function test_cambiar_el_precio_no_altera_una_orden_confirmada(): void
    {
        $orden = $this->confirmar();

        $this->acceso->forceFill(['price' => 500000, 'early_price' => null, 'early_until' => null])->save();

        $this->assertSame(80000, $orden->fresh()->total);
        $this->assertSame(80000, (int) $orden->participantesVigentes()->first()->unit_price);
    }

    public function test_una_reserva_vencida_se_reactiva_al_precio_vigente(): void
    {
        $orden = $this->confirmar();
        $this->assertSame(80000, $orden->total);

        $orden->forceFill(['status' => OrderStatus::Vencida, 'reserved_until' => now()->subDay()])->save();

        // Si venció, perdió la silla: volver a tomarla es un trato nuevo.
        $this->travelTo(now()->addWeeks(2));

        $reactivada = app(ReactivarReserva::class)($orden->fresh());

        $this->assertSame(160000, $reactivada->total);
        $this->assertSame('normal', $reactivada->applied_tariff);
    }

    private function borrador(): Order
    {
        $orden = $this->evento->orders()->create([
            'responsible_name' => 'Ana Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);

        $orden->participants()->create([
            'first_name' => 'Carlos',
            'last_name' => 'Soto',
            'access_type_id' => $this->acceso->id,
        ]);

        return $orden->fresh();
    }

    private function confirmar(): Order
    {
        return app(ConfirmarOrden::class)($this->borrador());
    }
}
