<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Exceptions\CuposInsuficientes;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sobreventa bajo competencia por la última vacante.
 *
 * La garantía real está en el UPDATE condicional de ReservarCupos: la condición
 * de capacidad viaja dentro de la misma sentencia que incrementa el contador.
 *
 * Estas pruebas verifican la semántica de forma determinista. La prueba con
 * procesos realmente paralelos contra MySQL vive en ConcurrenciaMysqlTest,
 * porque SQLite serializa las escrituras y no puede demostrarla.
 */
class ConcurrenciaDeCuposTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario', 'slug' => 'seminario', 'status' => EventStatus::Publicado,
        ]);
    }

    private function borradorDe(int $participantes, int $accesoId): Order
    {
        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana',
            'responsible_email' => 'ana'.Order::count().'@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);

        for ($i = 0; $i < $participantes; $i++) {
            $orden->participants()->create(['first_name' => 'P'.$i, 'access_type_id' => $accesoId]);
        }

        return $orden->fresh();
    }

    public function test_la_ultima_vacante_la_toma_una_sola_orden(): void
    {
        $jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 1]);
        $acceso = $this->event->accessTypes()->create(['name' => 'J1', 'position' => 1, 'price' => 1000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $primera = $this->borradorDe(1, $acceso->id);
        $segunda = $this->borradorDe(1, $acceso->id);

        app(ConfirmarOrden::class)($primera);

        $this->expectException(CuposInsuficientes::class);
        app(ConfirmarOrden::class)($segunda);
    }

    public function test_el_contador_nunca_supera_la_capacidad(): void
    {
        $jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 10]);
        $acceso = $this->event->accessTypes()->create(['name' => 'J1', 'position' => 1, 'price' => 1000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $confirmadas = 0;

        // Se intenta reservar mucho más de la capacidad.
        for ($i = 0; $i < 25; $i++) {
            try {
                app(ConfirmarOrden::class)($this->borradorDe(1, $acceso->id));
                $confirmadas++;
            } catch (CuposInsuficientes $e) {
                // esperado una vez lleno
            }
        }

        $this->assertSame(10, $confirmadas);
        $this->assertSame(10, $jornada->fresh()->reserved_seats);
        $this->assertSame(0, $jornada->fresh()->cuposDisponibles());
        $this->assertSame(10, Order::where('status', OrderStatus::Reservada)->count());
    }

    public function test_una_orden_que_no_cabe_completa_no_toma_cupos_parciales(): void
    {
        // Quedan 2 vacantes y la orden pide 3: no debe tomar las 2 disponibles.
        $jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 2]);
        $acceso = $this->event->accessTypes()->create(['name' => 'J1', 'position' => 1, 'price' => 1000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        try {
            app(ConfirmarOrden::class)($this->borradorDe(3, $acceso->id));
            $this->fail('Debio rechazar la orden completa.');
        } catch (CuposInsuficientes $e) {
            // esperado
        }

        $this->assertSame(0, $jornada->fresh()->reserved_seats);
        $this->assertSame(2, $jornada->fresh()->cuposDisponibles());
    }

    public function test_un_acceso_que_consume_varios_asientos_respeta_la_capacidad(): void
    {
        $jornada = $this->event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 5]);
        $acceso = $this->event->accessTypes()->create(['name' => 'Doble', 'position' => 1, 'price' => 1000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 2]]);

        app(ConfirmarOrden::class)($this->borradorDe(2, $acceso->id)); // 4 asientos

        $this->assertSame(4, $jornada->fresh()->reserved_seats);

        // Un participante más pediría 2 y solo queda 1.
        $this->expectException(CuposInsuficientes::class);
        app(ConfirmarOrden::class)($this->borradorDe(1, $acceso->id));
    }
}
