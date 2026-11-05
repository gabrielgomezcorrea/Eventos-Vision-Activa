<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\Rol;
use App\Models\DiscountCode;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerDescuentosTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinacion_ve_los_codigos_en_el_evento_pero_no_los_gestiona(): void
    {
        $evento = Event::create(['name' => 'Seminario', 'slug' => 'seminario', 'status' => EventStatus::Publicado]);
        DiscountCode::factory()->create(['event_id' => $evento->id, 'code' => 'VISIBLE1']);
        $coord = $this->usuarioConRol(Rol::Coordinacion);

        $this->actingAs($coord)->get(route('eventos.show', $evento))
            ->assertInertia(fn ($p) => $p
                ->where('puede.descuentos', true)
                ->where('puede.gestionar_descuentos', false)
                ->has('codigos', 1));

        $this->actingAs($coord)->get(route('eventos.codigos.index', $evento))->assertForbidden();
    }

    public function test_contabilidad_no_ve_los_codigos(): void
    {
        $evento = Event::create(['name' => 'Seminario', 'slug' => 'seminario', 'status' => EventStatus::Publicado]);

        $this->actingAs($this->usuarioConRol(Rol::Contabilidad))->get(route('eventos.show', $evento))->assertForbidden();
    }
}
