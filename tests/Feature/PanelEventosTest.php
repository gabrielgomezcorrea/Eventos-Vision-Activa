<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\Rol;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Eventos en el panel: quién puede tocarlos y lo que protege cupos y dinero.
 */
class PanelEventosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function usuario(Rol $rol): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol->value);

        return $usuario->fresh();
    }

    /** @param  array<string, mixed>  $atributos */
    private function evento(array $atributos = []): Event
    {
        return Event::create([
            'name' => 'Seminario 2026',
            'slug' => 'seminario-2026',
            ...$atributos,
        ]);
    }

    private function jornada(Event $evento, string $nombre, int $posicion, int $tomados = 0): EventSession
    {
        return $evento->sessions()->create([
            'name' => $nombre,
            'position' => $posicion,
            'capacity' => 100,
            'reserved_seats' => $tomados,
        ]);
    }

    public function test_las_pantallas_de_eventos_cargan(): void
    {
        $evento = $this->evento();
        $evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 300]);
        $evento->accessTypes()->create(['name' => 'Ambas jornadas', 'position' => 1, 'price' => 150000]);

        $this->actingAs($this->usuario(Rol::Administrador));

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('eventos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('eventos/index')->has('eventos.data', 1));
        $this->get(route('eventos.show', $evento))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('eventos/show')
                ->where('jornadas.0', fn (string $texto) => str_contains($texto, '300 cupos, quedan 300'))
                ->where('accesos.0', fn (string $texto) => str_contains($texto, 'Ambas jornadas — $150.000')));
        $this->get(route('eventos.cupos', $evento))->assertOk();
        $this->get(route('eventos.formulario', $evento))->assertOk();
    }

    public function test_crear_un_evento_calcula_el_identificador_y_nace_en_borrador(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $datos = ['name' => 'Seminario Ñandú: 2ª versión', 'modality' => 'presencial', 'starts_on' => '2026-11-05'];

        $this->post(route('eventos.store'), $datos)->assertRedirect();
        $this->post(route('eventos.store'), $datos)->assertRedirect();

        $this->assertSame(
            ['seminario-nandu-2a-version', 'seminario-nandu-2a-version-2'],
            Event::orderBy('id')->pluck('slug')->all(),
        );
        $this->assertSame(EventStatus::Borrador, Event::firstOrFail()->status);
    }

    public function test_contabilidad_ve_eventos_pero_no_los_modifica(): void
    {
        $evento = $this->evento();
        $jornada = $this->jornada($evento, 'Jornada 1', 1);

        $this->actingAs($this->usuario(Rol::Contabilidad));

        // Contabilidad no configura eventos: no le aparece en el menú y
        // tampoco entra escribiendo la URL.
        $this->get(route('eventos.index'))->assertForbidden();
        $this->get(route('eventos.show', $evento))->assertForbidden();

        $this->post(route('eventos.store'), ['name' => 'Otro', 'modality' => 'presencial', 'starts_on' => '2026-11-05'])->assertForbidden();
        $this->patch(route('eventos.update', $evento), ['name' => 'Otro nombre'])->assertForbidden();
        $this->patch(route('eventos.estado', $evento), ['status' => 'closed'])->assertForbidden();
        $this->delete(route('eventos.destroy', $evento))->assertForbidden();
        $this->get(route('eventos.cupos', $evento))->assertForbidden();
        $this->get(route('eventos.formulario', $evento))->assertForbidden();
        $this->post(route('eventos.jornadas.store', $evento), ['name' => 'Jornada 2'])->assertForbidden();
        $this->delete(route('eventos.jornadas.destroy', [$evento, $jornada]))->assertForbidden();

        $this->assertSame('Seminario 2026', $evento->fresh()->name);
        $this->assertNotNull($jornada->fresh());
    }

    public function test_acreditacion_no_entra_a_eventos(): void
    {
        $this->actingAs($this->usuario(Rol::Acreditacion));

        $this->get(route('eventos.index'))->assertForbidden();
        $this->get(route('eventos.show', $this->evento()))->assertForbidden();
    }

    public function test_no_se_publica_un_evento_incompleto(): void
    {
        $evento = $this->evento();

        $this->actingAs($this->usuario(Rol::Administrador))
            ->patch(route('eventos.estado', $evento), ['status' => EventStatus::Publicado->value])
            ->assertSessionHasErrors(['status' => 'Para publicar falta cargar las jornadas, los tipos de acceso y la cuenta para las transferencias.']);

        $this->assertSame(EventStatus::Borrador, $evento->fresh()->status);
    }

    public function test_marcar_jornadas_deja_el_acceso_consumiendo_un_cupo_en_cada_una(): void
    {
        $evento = $this->evento();
        $primera = $this->jornada($evento, 'Jornada 1', 1);
        $segunda = $this->jornada($evento, 'Jornada 2', 2);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.accesos.store', $evento), [
                'name' => 'Ambas jornadas',
                'price' => 150000,
                'sessions' => [$primera->id, $segunda->id],
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $acceso = AccessType::where('name', 'Ambas jornadas')->firstOrFail();

        $this->assertSame(
            [$primera->id => 1, $segunda->id => 1],
            $acceso->load('sessions')->consumoDeCupos(),
        );
    }

    public function test_un_acceso_sin_jornadas_no_se_guarda(): void
    {
        $evento = $this->evento();
        $this->jornada($evento, 'Jornada 1', 1);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.accesos.store', $evento), [
                'name' => 'Acceso suelto',
                'price' => 1000,
                'sessions' => [],
            ])
            ->assertSessionHasErrors('sessions');

        $this->assertDatabaseMissing('access_types', ['name' => 'Acceso suelto']);
    }

    public function test_un_acceso_no_puede_incluir_jornadas_de_otro_evento(): void
    {
        $evento = $this->evento();
        $this->jornada($evento, 'Jornada 1', 1);

        $otro = Event::create(['name' => 'Otro seminario', 'slug' => 'otro-seminario']);
        $ajena = $this->jornada($otro, 'Jornada ajena', 1);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.accesos.store', $evento), [
                'name' => 'Acceso cruzado',
                'price' => 1000,
                'sessions' => [$ajena->id],
            ])
            ->assertSessionHasErrors('sessions.0');

        $this->assertDatabaseMissing('access_types', ['name' => 'Acceso cruzado']);
    }

    public function test_una_jornada_con_cupos_tomados_no_se_elimina(): void
    {
        $evento = $this->evento();
        $jornada = $this->jornada($evento, 'Jornada 1', 1, tomados: 3);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->delete(route('eventos.jornadas.destroy', [$evento, $jornada]));

        $this->assertNotNull($jornada->fresh());
    }

    public function test_una_jornada_no_puede_empezar_antes_que_el_evento(): void
    {
        $evento = $this->evento(['starts_on' => '2026-11-05']);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.jornadas.store', $evento), [
                'name' => 'Jornada 1',
                'starts_at' => '2026-11-04 09:00',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.jornadas.store', $evento), [
                'name' => 'Jornada 1',
                'starts_at' => '2026-11-05 09:00',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_la_capacidad_no_baja_de_los_cupos_tomados(): void
    {
        $evento = $this->evento();
        $jornada = $this->jornada($evento, 'Jornada 1', 1, tomados: 40);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->patch(route('eventos.jornadas.update', [$evento, $jornada]), ['name' => 'Jornada 1', 'capacity' => 30])
            ->assertSessionHasErrors('capacity');

        $this->assertSame(100, $jornada->fresh()->capacity);
    }

    public function test_el_formulario_publico_no_permite_quitar_el_correo(): void
    {
        $evento = $this->evento();

        $this->actingAs($this->usuario(Rol::Administrador))
            ->put(route('eventos.formulario.update', $evento), [
                'campos' => [
                    ['key' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'options' => []],
                ],
            ])
            ->assertSessionHasErrors('campos');

        $this->assertNull($evento->fresh()->program_form_fields);
    }
}
