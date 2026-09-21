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
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_position' => 'Otro',
            'responsible_position_otro' => 'Directora',
            'responsible_phone' => '+56 9 1234 5678',
            'responsible_institution' => 'Colegio San José',
            'kind' => OrderKind::Institucional->value,
        ]);

        // Volver más tarde con el mismo enlace muestra lo ya registrado.
        $this->get($this->ruta('responsable'))
            ->assertOk()
            ->assertSee('value="Ana"', escape: false)
            ->assertSee('value="Pérez"', escape: false)
            ->assertSee('value="Directora"', escape: false)
            ->assertSee('value="56912345678"', escape: false);
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

    public function test_facturacion_exige_lo_que_pide_el_sii_y_normaliza(): void
    {
        $this->post($this->ruta('pagador.guardar'), ['name' => 'Fundación Educar'])
            ->assertOk()
            ->assertSee('El campo RUT es obligatorio')
            ->assertSee('El campo comuna es obligatorio');

        $this->assertNull($this->orden->fresh()->payer_entity_id);

        $this->post($this->ruta('pagador.guardar'), [
            'name' => 'Fundación Educar', 'rut' => '76.086.428-5',
            'address' => 'Av. Grecia 1234', 'commune' => 'nunoa', 'billing_email' => 'Pagos@Fundacion.cl',
            'phone' => '+56 9 1234 5678',
        ])->assertRedirect($this->ruta('resumen'));

        $pagador = $this->orden->fresh()->payerEntity;
        $this->assertSame('Ñuñoa', $pagador->commune);
        $this->assertSame('56912345678', $pagador->phone);
        $this->assertSame('pagos@fundacion.cl', $pagador->billing_email);
    }

    public function test_una_comuna_fuera_de_la_lista_no_bloquea(): void
    {
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio San José', 'address' => 'Calle 1', 'commune' => 'villa  alegre norte',
        ])->assertRedirect($this->ruta('establecimientos'));

        $this->assertSame('Villa Alegre Norte', $this->orden->fresh()->establishments()->sole()->commune);
    }

    public function test_el_responsable_puede_agregarse_como_participante(): void
    {
        $this->post($this->ruta('responsable.guardar'), [
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_position' => 'Otro',
            'responsible_position_otro' => 'Directora',
            'responsible_phone' => '56912345678',
            'kind' => OrderKind::Particular->value,
        ]);

        $this->post($this->ruta('participantes.responsable'), [
            'rut' => '11.111.111-1',
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
            'position_otro' => 'jefa de utp', 'email' => ' Ana@Colegio.CL', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id,
        ])->assertRedirect($this->ruta('participantes'));

        $participante = $this->orden->fresh()->participants()->sole();

        $this->assertSame('Ana María', $participante->first_name);
        $this->assertSame('De la Fuente', $participante->last_name);
        $this->assertSame('Jefa de Utp', $participante->position);
        $this->assertSame('ana@colegio.cl', $participante->email);
    }

    public function test_quitar_un_establecimiento_no_borra_a_sus_participantes(): void
    {
        $this->post($this->ruta('establecimientos.agregar'), ['name' => 'Colegio San José', 'address' => 'Calle 1', 'commune' => 'Talca']);
        $establecimiento = $this->orden->fresh()->establishments()->sole();

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos',
            'last_name' => 'Rojas',
            'position' => 'Docente Enseñanza Básica',
            'email' => 'carlos@colegio.cl',
            'rut' => '11.111.111-1',
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

    public function test_los_participantes_usan_su_propia_lista_de_cargos(): void
    {
        $datos = ['first_name' => 'Carlos', 'last_name' => 'Rojas', 'email' => 'carlos@colegio.cl', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id];

        // "Directivo" is from the buyer list, not the participant one.
        $this->post($this->ruta('participantes.agregar'), [...$datos, 'position' => 'Directivo'])
            ->assertOk()->assertSee('Elige un cargo de la lista');

        $this->post($this->ruta('participantes.agregar'), [...$datos, 'position' => 'Inspector General'])
            ->assertRedirect($this->ruta('participantes'));

        // The responsible keeps their buyer position when joining as participant.
        $this->post($this->ruta('responsable.guardar'), [
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_position' => 'Directivo',
            'responsible_phone' => '56912345678', 'kind' => OrderKind::Particular->value,
        ]);
        $this->post($this->ruta('participantes.responsable'), ['rut' => '22.222.222-2', 'access_type_id' => $this->acceso->id])
            ->assertRedirect($this->ruta('participantes'));

        $this->assertSame(['Inspector General', 'Directivo'], $this->orden->participants()->orderBy('id')->pluck('position')->all());
    }

    public function test_con_un_solo_establecimiento_no_se_pregunta_por_el_colegio(): void
    {
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio San José', 'address' => 'Calle 1', 'commune' => 'Talca',
        ])->assertRedirect($this->ruta('establecimientos'));

        // Each participant belongs to that establishment without being asked.
        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'position' => 'Inspector General',
            'email' => 'carlos@colegio.cl', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id,
        ])->assertRedirect($this->ruta('participantes'));

        $this->assertSame(
            $this->orden->fresh()->establishments()->sole()->id,
            $this->orden->fresh()->participants()->sole()->establishment_id,
        );
    }

    public function test_el_borrador_acepta_mas_de_un_colegio(): void
    {
        $datos = ['address' => 'Calle 1', 'commune' => 'Talca'];

        $this->post($this->ruta('establecimientos.agregar'), [...$datos, 'name' => 'Colegio San José'])
            ->assertRedirect($this->ruta('establecimientos'));

        $this->post($this->ruta('establecimientos.agregar'), [...$datos, 'name' => 'Colegio Los Robles'])
            ->assertRedirect($this->ruta('establecimientos'));

        $this->assertSame(
            ['Colegio San José', 'Colegio los Robles'],
            $this->orden->fresh()->establishments()->pluck('name')->all(),
        );
    }

    public function test_con_dos_colegios_el_participante_exige_elegir_uno(): void
    {
        $sanJose = $this->orden->establishments()->create(['name' => 'Colegio San José', 'address' => 'Calle 1', 'commune' => 'Talca']);
        $losRobles = $this->orden->establishments()->create(['name' => 'Colegio Los Robles', 'address' => 'Calle 2', 'commune' => 'Talca']);

        // Sin elegir colegio, no se agrega: la orden se devuelve renderizada
        // con el error, sin sesión ni mensajes flash.
        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'position' => 'Inspector General',
            'email' => 'carlos@colegio.cl', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id,
        ])->assertOk()->assertSee('Seleccione el establecimiento del participante.');

        $this->assertSame(0, $this->orden->participants()->count());

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'position' => 'Inspector General',
            'email' => 'carlos@colegio.cl', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id,
            'establishment_id' => $losRobles->id,
        ])->assertRedirect($this->ruta('participantes'));

        $this->assertSame($losRobles->id, $this->orden->fresh()->participants()->sole()->establishment_id);
        $this->assertNotSame($sanJose->id, $losRobles->id);
    }
}
