<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\Forms\ProgramFormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargosPorEventoTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_evento_sin_eleccion_acepta_todos_los_cargos(): void
    {
        $evento = Event::factory()->create(['participant_positions' => null]);

        $this->assertSame(ProgramFormField::CARGOS_PARTICIPANTE, $evento->cargosDeParticipante());
    }

    public function test_el_evento_que_elige_conserva_el_orden_de_la_lista_y_la_opcion_otro(): void
    {
        $evento = Event::factory()->create([
            'participant_positions' => ['Docente Enseñanza Básica', 'Director/a'],
        ]);

        $this->assertSame(
            ['Director/a', 'Docente Enseñanza Básica', ProgramFormField::CARGO_OTRO],
            $evento->cargosDeParticipante(),
        );
    }

    public function test_un_cargo_que_ya_no_existe_en_la_lista_base_no_queda_ofrecido(): void
    {
        $evento = Event::factory()->create([
            'participant_positions' => ['Director/a', 'Cargo inventado'],
        ]);

        $this->assertNotContains('Cargo inventado', $evento->cargosDeParticipante());
    }
}
