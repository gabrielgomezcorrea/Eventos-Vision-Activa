<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Descuento por cantidad sobre la orden completa.
 *
 * Acá hay plata: un descuento mal calculado se transfiere y después hay que
 * devolverlo. Y si el total no cuadra con lo que se le pide transferir,
 * contabilidad marca como diferencia una orden que está bien.
 */
class DescuentoPorCantidadTest extends TestCase
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

        $jornada = $this->evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 100]);

        $this->acceso = $this->evento->accessTypes()->create([
            'name' => 'Curso completo', 'position' => 1, 'price' => 100000,
        ]);

        $this->acceso->sessions()->attach($jornada->id, ['seats' => 1]);

        $this->evento->discountTiers()->create(['min_participants' => 5, 'type' => 'percent', 'value' => 10]);
        $this->evento->discountTiers()->create(['min_participants' => 10, 'type' => 'amount', 'value' => 200000]);
    }

    public function test_bajo_el_tramo_no_hay_descuento(): void
    {
        $orden = $this->confirmar(4);

        $this->assertSame(400000, $orden->subtotal);
        $this->assertSame(0, $orden->discount_amount);
        $this->assertSame(400000, $orden->total);
    }

    public function test_aplica_el_porcentaje_sobre_la_orden_completa(): void
    {
        $orden = $this->confirmar(5);

        $this->assertSame(500000, $orden->subtotal);
        $this->assertSame(50000, $orden->discount_amount);
        $this->assertSame(450000, $orden->total);
        $this->assertSame('10% por 5 o más participantes', $orden->discount_label);
    }

    public function test_gana_el_tramo_mas_alto_que_alcanza(): void
    {
        // Con 5+ y 10+ configurados, doce personas reciben el de diez.
        $orden = $this->confirmar(12);

        $this->assertSame(200000, $orden->discount_amount);
        $this->assertSame(1000000, $orden->total);
    }

    public function test_el_descuento_queda_congelado_al_confirmar(): void
    {
        $orden = $this->confirmar(5);

        $this->evento->discountTiers()->delete();
        $this->acceso->forceFill(['price' => 300000])->save();

        $this->assertSame(50000, $orden->fresh()->discount_amount);
        $this->assertSame(450000, $orden->fresh()->total);
    }

    public function test_contabilidad_compara_contra_el_total_con_descuento(): void
    {
        $orden = $this->confirmar(5);

        $pago = $orden->payments()->create([
            'amount' => $orden->total,
            'method' => 'bank_transfer',
            'status' => 'in_review',
        ]);

        // Transfirió exactamente lo que se le pidió: no es una diferencia.
        $this->assertFalse($pago->tieneDiferenciaDeMonto());
    }

    public function test_un_monto_fijo_no_deja_el_total_negativo(): void
    {
        $this->evento->discountTiers()->delete();
        $this->evento->discountTiers()->create(['min_participants' => 2, 'type' => 'amount', 'value' => 999999999]);
        $this->evento->refresh();

        $orden = $this->confirmar(2);

        $this->assertSame(0, $orden->total);
        $this->assertSame(200000, $orden->discount_amount);
    }

    private function confirmar(int $participantes): Order
    {
        $orden = $this->evento->orders()->create([
            'responsible_name' => 'Ana Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);

        for ($i = 1; $i <= $participantes; $i++) {
            $orden->participants()->create([
                'first_name' => 'Participante',
                'last_name' => (string) $i,
                'access_type_id' => $this->acceso->id,
            ]);
        }

        return app(ConfirmarOrden::class)($orden->fresh());
    }
}
