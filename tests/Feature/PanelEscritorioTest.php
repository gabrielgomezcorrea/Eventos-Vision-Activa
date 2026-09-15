<?php

namespace Tests\Feature;

use App\Enums\Rol;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El gráfico de inscripciones cuenta órdenes y pagos: solo lo ve quien ve órdenes.
 */
class PanelEscritorioTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_actividad_solo_llega_a_quien_ve_ordenes(): void
    {
        $this->seed(RolesSeeder::class);

        $this->actingAs($this->usuarioConRol(Rol::Acreditacion))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->where('actividad', null)
                ->where('jornadas', null)
                ->has('tarjetas', 0)
                ->has('puerta.eventos')
                ->has('puerta.ultimas'));

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('actividad', 90)->where('puerta', null));
    }
}
