<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\Rol;
use App\Models\Event;
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
                ->has('tarjetas', 0)
                ->has('puerta.eventos')
                ->has('puerta.ultimas'));

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('actividad', 90)->where('puerta', null));
    }

    public function test_los_eventos_que_no_estan_publicados_no_salen_en_el_escritorio_ni_en_acreditacion(): void
    {
        $this->seed(RolesSeeder::class);
        Event::create(['name' => 'Publicado', 'slug' => 'pub', 'status' => EventStatus::Publicado]);
        Event::create(['name' => 'Borrador', 'slug' => 'bor', 'status' => EventStatus::Borrador]);
        Event::create(['name' => 'Cerrado', 'slug' => 'cer', 'status' => EventStatus::Cerrado]);

        $this->actingAs($this->usuarioConRol(Rol::Acreditacion))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('puerta.eventos', 1)->where('puerta.eventos.0.nombre', 'Publicado'));

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->get(route('acreditaciones.index'))
            ->assertInertia(fn (Assert $page) => $page->has('eventos', 1));
    }
}
