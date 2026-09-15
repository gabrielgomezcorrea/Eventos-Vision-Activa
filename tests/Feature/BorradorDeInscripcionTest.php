<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BorradorDeInscripcionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private AccessType $acceso;

    private string $token;

    private Order $orden;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
        $this->acceso = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);

        [, $this->token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $this->token]));
        $this->orden = Order::sole();
    }

    private function ruta(string $nombre, array $extra = []): string
    {
        return route("inscripcion.{$nombre}", array_merge(['token' => $this->token], $extra));
    }

    public function test_los_datos_se_guardan_como_borrador_y_se_recuperan(): void
    {
        $this->post($this->ruta('responsable.guardar'), [
            'responsible_name' => 'Ana Pérez',
            'responsible_position' => 'Otro',
            'responsible_position_otro' => 'Directora',
            'kind' => OrderKind::Institucional->value,
        ]);

        // Volver más tarde con el mismo enlace muestra lo ya registrado.
        $this->get($this->ruta('responsable'))
            ->assertOk()
            ->assertSee('value="Ana Pérez"', escape: false)
            ->assertSee('value="Directora"', escape: false);
    }

    public function test_un_error_de_validacion_no_borra_lo_escrito(): void
    {
        $this->post($this->ruta('pagador.guardar'), [
            'name' => 'Fundación Educar',
            'rut' => '76.086.428-1',   // dígito verificador incorrecto
            'address' => 'Av. Siempre Viva 123',
        ])
            ->assertOk()
            ->assertSee('dígito verificador')
            ->assertSee('value="Fundación Educar"', escape: false)
            ->assertSee('value="Av. Siempre Viva 123"', escape: false);

        $this->assertNull($this->orden->fresh()->payer_entity_id);
    }

    public function test_el_rut_es_opcional(): void
    {
        $this->post($this->ruta('pagador.guardar'), ['name' => 'Fundación Educar'])
            ->assertRedirect($this->ruta('establecimientos'));

        $this->assertNull($this->orden->fresh()->payerEntity->rut);
    }

    public function test_el_responsable_puede_agregarse_como_participante(): void
    {
        $this->post($this->ruta('responsable.guardar'), [
            'responsible_name' => 'Ana Pérez',
            'responsible_position' => 'Otro',
            'responsible_position_otro' => 'Directora',
            'kind' => OrderKind::Particular->value,
        ]);

        $this->post($this->ruta('participantes.responsable'), [
            'access_type_id' => $this->acceso->id,
        ])->assertRedirect($this->ruta('participantes'));

        $participante = $this->orden->fresh()->participants()->sole();

        $this->assertSame('Ana', $participante->first_name);
        $this->assertSame('Pérez', $participante->last_name);
        $this->assertSame('Directora', $participante->position);
        $this->assertSame('ana@colegio.cl', $participante->email);
    }

    public function test_el_participante_no_entra_con_basura_y_se_guarda_normalizado(): void
    {
        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'C4rress', 'last_name' => '.', 'position' => 'Directivo',
            'email' => 'ana@colegio.cl', 'access_type_id' => $this->acceso->id,
        ])->assertOk()->assertSee('Escribe solo letras', false);

        $this->assertSame(0, $this->orden->participants()->count());

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'ana  MARÍA', 'last_name' => 'de la fuente', 'position' => 'Otro',
            'position_otro' => 'jefa de utp', 'email' => ' Ana@Colegio.CL', 'access_type_id' => $this->acceso->id,
        ])->assertRedirect($this->ruta('participantes'));

        $participante = $this->orden->fresh()->participants()->sole();

        $this->assertSame('Ana María', $participante->first_name);
        $this->assertSame('De la Fuente', $participante->last_name);
        $this->assertSame('Jefa de Utp', $participante->position);
        $this->assertSame('ana@colegio.cl', $participante->email);
    }

    public function test_quitar_un_establecimiento_no_borra_a_sus_participantes(): void
    {
        $this->post($this->ruta('establecimientos.agregar'), ['name' => 'Colegio San José']);
        $establecimiento = $this->orden->fresh()->establishments()->sole();

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos',
            'last_name' => 'Rojas',
            'position' => 'Docente',
            'email' => 'carlos@colegio.cl',
            'access_type_id' => $this->acceso->id,
            'establishment_id' => $establecimiento->id,
        ]);

        $this->delete($this->ruta('establecimientos.quitar', ['establishment' => $establecimiento->id]));

        $participante = $this->orden->fresh()->participants()->sole();

        $this->assertSame('Carlos', $participante->first_name);
        $this->assertNull($participante->establishment_id);
    }

    public function test_no_acepta_un_acceso_de_otro_evento(): void
    {
        $otro = Event::create(['name' => 'Otro', 'slug' => 'otro', 'status' => EventStatus::Publicado]);
        $ajeno = $otro->accessTypes()->create(['name' => 'Ajeno', 'position' => 1, 'price' => 1000]);

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos',
            'last_name' => 'Rojas',
            'position' => 'Docente',
            'email' => 'carlos@colegio.cl',
            'access_type_id' => $ajeno->id,
        ])->assertOk()->assertSee('Seleccione un tipo de acceso disponible');

        $this->assertSame(0, $this->orden->fresh()->participants()->count());
    }

    public function test_una_orden_confirmada_ya_no_la_edita_el_cliente(): void
    {
        $this->orden->update(['status' => OrderStatus::Reservada]);

        $this->get($this->ruta('responsable'))->assertForbidden();
        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos', 'access_type_id' => $this->acceso->id,
        ])->assertForbidden();
    }
}
