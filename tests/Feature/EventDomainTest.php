<?php

namespace Tests\Feature;

use App\Enums\EventModality;
use App\Enums\EventStatus;
use App\Enums\ReservationDurationUnit;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventDomainTest extends TestCase
{
    use RefreshDatabase;

    private function evento(array $attrs = []): Event
    {
        return Event::create(array_merge([
            'name' => 'Seminario de prueba',
            'slug' => 'seminario-de-prueba',
            'status' => EventStatus::Publicado,
            'modality' => EventModality::Presencial,
            'reservation_duration_value' => 3,
            'reservation_duration_unit' => ReservationDurationUnit::DiasCorridos,
        ], $attrs));
    }

    public function test_solo_un_evento_publicado_admite_inscripciones(): void
    {
        $this->assertTrue($this->evento()->admiteInscripciones());

        $this->assertFalse($this->evento(['slug' => 'b', 'status' => EventStatus::Borrador])->admiteInscripciones());
        $this->assertFalse($this->evento(['slug' => 'c', 'status' => EventStatus::Cerrado])->admiteInscripciones());
    }

    public function test_un_evento_online_no_usa_pulseras(): void
    {
        $this->assertFalse($this->evento(['modality' => EventModality::Online])->usaPulseras());
        $this->assertTrue($this->evento(['slug' => 'b', 'modality' => EventModality::Mixta])->usaPulseras());
    }

    public function test_el_vencimiento_usa_el_plazo_configurado_en_el_evento(): void
    {
        $evento = $this->evento([
            'reservation_duration_value' => 3,
            'reservation_duration_unit' => ReservationDurationUnit::DiasHabiles,
        ]);

        // Viernes + 3 días hábiles = miércoles.
        $vence = $evento->calcularVencimientoReserva(CarbonImmutable::parse('2026-09-04 10:00'));

        $this->assertSame('2026-09-09', $vence->format('Y-m-d'));
    }

    public function test_capacidad_nula_significa_sin_limite(): void
    {
        $jornada = $this->evento()->sessions()->create([
            'name' => 'Jornada 1',
            'position' => 1,
            'capacity' => null,
        ]);

        $this->assertFalse($jornada->tieneCapacidadLimitada());
        $this->assertNull($jornada->cuposDisponibles());
        $this->assertTrue($jornada->puedeReservar(9999));
    }

    public function test_cupos_disponibles_descuenta_los_reservados(): void
    {
        $jornada = $this->evento()->sessions()->create([
            'name' => 'Jornada 1',
            'position' => 1,
            'capacity' => 10,
            'reserved_seats' => 8,
        ]);

        $this->assertSame(2, $jornada->cuposDisponibles());
        $this->assertTrue($jornada->puedeReservar(2));
        $this->assertFalse($jornada->puedeReservar(3));
    }

    public function test_un_acceso_puede_consumir_cupos_de_varias_jornadas(): void
    {
        $evento = $this->evento();

        $j1 = $evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 100]);
        $j2 = $evento->sessions()->create(['name' => 'Jornada 2', 'position' => 2, 'capacity' => 100]);

        $ambas = $evento->accessTypes()->create(['name' => 'Ambas jornadas', 'position' => 1, 'price' => 150000]);
        $ambas->sessions()->sync([$j1->id => ['seats' => 1], $j2->id => ['seats' => 1]]);

        $this->assertSame(
            [$j1->id => 1, $j2->id => 1],
            $ambas->load('sessions')->consumoDeCupos(),
        );
    }

    public function test_el_precio_se_guarda_como_entero_en_pesos(): void
    {
        $acceso = $this->evento()->accessTypes()->create([
            'name' => 'Jornada 1',
            'position' => 1,
            'price' => 90000,
        ]);

        $this->assertIsInt($acceso->fresh()->price);
        $this->assertSame(90000, $acceso->fresh()->price);
    }

    public function test_el_slug_es_unico(): void
    {
        $this->evento();

        $this->expectException(QueryException::class);
        $this->evento();
    }
}
