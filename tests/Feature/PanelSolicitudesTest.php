<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Seguimiento de solicitudes: quién puede tocarlas y que reenviar el programa
 * le escriba de verdad a la persona.
 */
class PanelSolicitudesTest extends TestCase
{
    use RefreshDatabase;

    private ProgramRequest $solicitud;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesSeeder::class);

        $evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026']);

        $this->solicitud = ProgramRequest::create([
            'event_id' => $evento->id,
            'first_name' => 'Marta',
            'last_name' => 'Rojas',
            'email' => 'marta@colegio.cl',
            'consented_at' => now(),
            'extra' => ['comuna' => 'Ñuñoa'],
        ]);
    }

    private function usuario(Rol $rol): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol->value);

        return $usuario->fresh();
    }

    public function test_las_pantallas_de_solicitudes_cargan(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->get(route('solicitudes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('solicitudes/index')->has('solicitudes.data', 1));

        $this->get(route('solicitudes.show', $this->solicitud))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('solicitudes/show')
                ->where('respuestas.0.respuesta', 'Ñuñoa'));
    }

    public function test_marcar_que_se_inscribio_se_puede_deshacer(): void
    {
        $coordinadora = $this->usuario(Rol::Coordinacion);

        $this->actingAs($coordinadora)->post(route('solicitudes.inscrita', $this->solicitud));
        $this->assertNotNull($this->solicitud->fresh()->converted_at);

        $this->actingAs($coordinadora)->post(route('solicitudes.inscrita', $this->solicitud));
        $this->assertNull($this->solicitud->fresh()->converted_at);
    }

    public function test_reenviar_el_programa_le_escribe_a_la_persona(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->post(route('solicitudes.reenviar', $this->solicitud));

        Mail::assertQueued(ProgramaDelEvento::class, fn (ProgramaDelEvento $correo) => $correo->hasTo('marta@colegio.cl'));
        $this->assertNotNull($this->solicitud->fresh()->program_sent_at);
    }

    public function test_contabilidad_no_ve_solicitudes(): void
    {
        // El seguimiento de prospectos no es trabajo de Contabilidad: las
        // solicitudes tienen permiso propio desde que se separó de las
        // inscripciones, que sí necesita para revisar un pago en contexto.
        $this->actingAs($this->usuario(Rol::Contabilidad));

        $this->get(route('solicitudes.index'))->assertForbidden();
        $this->get(route('solicitudes.show', $this->solicitud))->assertForbidden();
        $this->post(route('solicitudes.reenviar', $this->solicitud))->assertForbidden();
        $this->post(route('solicitudes.inscrita', $this->solicitud))->assertForbidden();
        $this->delete(route('solicitudes.destroy', $this->solicitud))->assertForbidden();

        Mail::assertNothingQueued();
        $this->assertNotNull($this->solicitud->fresh());
    }

    public function test_acreditacion_no_ve_solicitudes(): void
    {
        $this->actingAs($this->usuario(Rol::Acreditacion));

        $this->get(route('solicitudes.index'))->assertForbidden();
        $this->get(route('solicitudes.show', $this->solicitud))->assertForbidden();
    }
}
