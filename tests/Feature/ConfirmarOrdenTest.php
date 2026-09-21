<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\OrdenNoConfirmable;
use App\Mail\OrdenConfirmada;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConfirmarOrdenTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventSession $j1;

    private EventSession $j2;

    private AccessType $soloJ1;

    private AccessType $ambas;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026',
            'status' => EventStatus::Publicado, 'order_prefix' => 'SI',
            'reservation_duration_value' => 3,
        ]);

        $this->j1 = $this->event->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 3]);
        $this->j2 = $this->event->sessions()->create(['name' => 'Jornada 2', 'position' => 2, 'capacity' => 2]);

        $this->soloJ1 = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $this->ambas = $this->event->accessTypes()->create(['name' => 'Ambas jornadas', 'position' => 2, 'price' => 150000]);

        $this->soloJ1->sessions()->sync([$this->j1->id => ['seats' => 1]]);
        $this->ambas->sessions()->sync([$this->j1->id => ['seats' => 1], $this->j2->id => ['seats' => 1]]);
    }

    private function borrador(int $cuantos = 1, ?AccessType $acceso = null): Order
    {
        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana'.Order::count().'@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);

        for ($i = 0; $i < $cuantos; $i++) {
            $orden->participants()->create([
                'first_name' => 'Participante '.$i,
                'access_type_id' => ($acceso ?? $this->soloJ1)->id,
            ]);
        }

        return $orden->fresh();
    }

    private function confirmar(Order $orden): Order
    {
        return app(ConfirmarOrden::class)($orden);
    }

    public function test_confirma_asigna_folio_reserva_cupos_y_fija_vencimiento(): void
    {
        $orden = $this->confirmar($this->borrador(2));

        $this->assertSame('SI-'.now()->year.'-0001', $orden->number);
        $this->assertSame(OrderStatus::Reservada, $orden->status);
        $this->assertSame(PaymentStatus::Pendiente, $orden->payment_status);
        $this->assertSame(180000, $orden->total);
        $this->assertNotNull($orden->confirmed_at);
        $this->assertSame(
            now()->addDays(3)->format('Y-m-d'),
            $orden->reserved_until->format('Y-m-d'),
        );

        $this->assertSame(2, $this->j1->fresh()->reserved_seats);
        $this->assertSame(0, $this->j2->fresh()->reserved_seats);
    }

    public function test_un_acceso_de_dos_jornadas_consume_cupo_en_ambas(): void
    {
        $this->confirmar($this->borrador(2, $this->ambas));

        $this->assertSame(2, $this->j1->fresh()->reserved_seats);
        $this->assertSame(2, $this->j2->fresh()->reserved_seats);
    }

    public function test_el_folio_es_correlativo(): void
    {
        $primera = $this->confirmar($this->borrador());
        $segunda = $this->confirmar($this->borrador());

        $this->assertSame('SI-'.now()->year.'-0001', $primera->number);
        $this->assertSame('SI-'.now()->year.'-0002', $segunda->number);
    }

    public function test_congela_el_precio_en_cada_participante(): void
    {
        $orden = $this->confirmar($this->borrador(2));

        $this->assertSame([90000, 90000], $orden->participants->pluck('unit_price')->all());

        // Subir el precio del acceso no debe alterar la orden ya confirmada.
        $this->soloJ1->update(['price' => 120000]);

        $orden->refresh()->load('participants');

        $this->assertSame(180000, $orden->total);
        $this->assertSame([90000, 90000], $orden->participants->pluck('unit_price')->all());
        $this->assertSame(180000, $orden->calcularTotal());
    }

    public function test_bloquea_la_confirmacion_si_no_hay_cupos(): void
    {
        // La jornada 2 tiene capacidad 2 y el acceso "Ambas" la consume.
        $this->confirmar($this->borrador(2, $this->ambas));

        $this->expectException(CuposInsuficientes::class);
        $this->confirmar($this->borrador(1, $this->ambas));
    }

    public function test_si_falla_una_jornada_no_queda_nada_a_medias(): void
    {
        // Deja la jornada 2 llena pero la 1 con cupo.
        $this->confirmar($this->borrador(2, $this->ambas));

        $j1Antes = $this->j1->fresh()->reserved_seats;
        $foliosAntes = Order::whereNotNull('number')->count();

        try {
            $this->confirmar($this->borrador(1, $this->ambas));
            $this->fail('Debio lanzar CuposInsuficientes.');
        } catch (CuposInsuficientes $e) {
            // esperado
        }

        // Ni cupo tomado en la jornada que sí tenía, ni folio consumido.
        $this->assertSame($j1Antes, $this->j1->fresh()->reserved_seats);
        $this->assertSame($foliosAntes, Order::whereNotNull('number')->count());
    }

    public function test_no_confirma_dos_veces(): void
    {
        $orden = $this->confirmar($this->borrador());

        $this->expectException(OrdenNoConfirmable::class);
        $this->confirmar($orden);
    }

    public function test_exige_los_datos_minimos(): void
    {
        $sinParticipantes = $this->borrador(0);
        try {
            $this->confirmar($sinParticipantes);
            $this->fail('Debio exigir participantes.');
        } catch (OrdenNoConfirmable $e) {
            $this->assertStringContainsString('participante', $e->getMessage());
        }

        $sinPagador = $this->borrador();
        $sinPagador->update(['payer_entity_id' => null]);
        try {
            $this->confirmar($sinPagador->fresh());
            $this->fail('Debio exigir entidad pagadora.');
        } catch (OrdenNoConfirmable $e) {
            $this->assertStringContainsString('entidad pagadora', $e->getMessage());
        }
    }

    public function test_envia_el_correo_de_confirmacion_y_registra_auditoria(): void
    {
        $orden = $this->confirmar($this->borrador());

        Mail::assertQueued(OrdenConfirmada::class, fn ($m) => $m->hasTo($orden->responsible_email));

        $log = AuditLog::where('action', 'orden.confirmada')->sole();

        $this->assertSame('draft', $log->old_status);
        $this->assertSame('reserved', $log->new_status);
        $this->assertSame($orden->number, $log->properties['numero']);
    }

    public function test_una_jornada_sin_capacidad_no_limita(): void
    {
        $this->j1->update(['capacity' => null]);

        $orden = $this->confirmar($this->borrador(50));

        $this->assertSame(OrderStatus::Reservada, $orden->status);
        $this->assertSame(50, $this->j1->fresh()->reserved_seats);
    }
}
