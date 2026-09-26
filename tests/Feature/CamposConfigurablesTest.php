<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Support\Forms\ProgramFormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CamposConfigurablesTest extends TestCase
{
    use RefreshDatabase;

    private function evento(?array $campos = null): Event
    {
        Mail::fake();

        return Event::create([
            'name' => 'Seminario 2026',
            'slug' => 'seminario-2026',
            'status' => EventStatus::Publicado,
            'program_form_fields' => $campos,
        ]);
    }

    public function test_sin_configuracion_usa_el_conjunto_base(): void
    {
        $campos = $this->evento()->camposDelFormulario();

        $this->assertSame(
            ['first_name', 'last_name', 'email', 'phone', 'position', 'institution'],
            collect($campos)->pluck('key')->all(),
        );
    }

    public function test_el_campo_de_jornada_se_oculta_si_no_hay_accesos(): void
    {
        // La jornada no viene entre los campos base: se agrega a mano cuando el
        // evento la necesita. Aun así, si el evento se queda sin accesos el
        // campo se oculta solo, para no mostrar un desplegable vacío.
        $evento = $this->evento([
            ['key' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'enabled' => true],
            ['key' => 'access_type_id', 'label' => 'Jornada de interés', 'type' => 'select', 'required' => false, 'enabled' => true],
        ]);

        $this->assertNotContains('access_type_id', collect($evento->camposDelFormulario())->pluck('key'));

        $evento->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);

        $this->assertContains('access_type_id', collect($evento->fresh()->camposDelFormulario())->pluck('key'));
    }

    public function test_la_jornada_de_interes_no_viene_entre_los_campos_base(): void
    {
        // Depende de que el evento tenga accesos y no todos preguntan eso.
        $this->assertNotContains(
            'access_type_id',
            collect(ProgramFormField::porDefecto())->pluck('key'),
        );
    }

    public function test_se_pueden_quitar_campos(): void
    {
        $evento = $this->evento([
            ['key' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'enabled' => true],
            ['key' => 'email', 'label' => 'Correo', 'type' => 'email', 'required' => true, 'enabled' => true],
            ['key' => 'phone', 'label' => 'Teléfono', 'type' => 'tel', 'required' => false, 'enabled' => false],
        ]);

        $this->get("/f/{$evento->slug}")
            ->assertOk()
            ->assertSee('Nombre')
            ->assertDontSee('Teléfono')
            ->assertDontSee('Institución');
    }

    public function test_un_campo_nuevo_se_guarda_en_extra_sin_migracion(): void
    {
        $evento = $this->evento([
            ['key' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'enabled' => true],
            ['key' => 'email', 'label' => 'Correo', 'type' => 'email', 'required' => true, 'enabled' => true],
            ['key' => 'comuna', 'label' => 'Comuna', 'type' => 'text', 'required' => true, 'enabled' => true],
            ['key' => 'rbd', 'label' => 'RBD', 'type' => 'number', 'required' => false, 'enabled' => true],
        ]);

        $this->get("/f/{$evento->slug}")->assertOk()->assertSee('Comuna')->assertSee('RBD');

        $this->post("/f/{$evento->slug}", [
            'first_name' => 'Ana',
            'email' => 'ana@colegio.cl',
            'comuna' => 'Providencia',
            'rbd' => '12345',
        ])->assertOk()->assertSee('Revisa tu correo');

        $solicitud = ProgramRequest::sole();

        $this->assertSame('Ana', $solicitud->first_name);
        $this->assertSame(['comuna' => 'Providencia', 'rbd' => '12345'], $solicitud->extra);
    }

    public function test_un_campo_configurado_como_obligatorio_se_exige(): void
    {
        $evento = $this->evento([
            ['key' => 'email', 'label' => 'Correo', 'type' => 'email', 'required' => true, 'enabled' => true],
            ['key' => 'comuna', 'label' => 'Comuna', 'type' => 'text', 'required' => true, 'enabled' => true],
        ]);

        $this->post("/f/{$evento->slug}", ['email' => 'ana@colegio.cl'])
            ->assertOk()
            ->assertSee('comuna');

        $this->assertSame(0, ProgramRequest::count());
    }

    public function test_los_campos_base_conocidos_no_caen_en_extra(): void
    {
        foreach (['first_name', 'last_name', 'email', 'position', 'institution', 'phone', 'access_type_id'] as $key) {
            $this->assertContains($key, ProgramFormField::CAMPOS_BASE);
        }

        $this->assertNotContains('comuna', ProgramFormField::CAMPOS_BASE);
    }

    public function test_todo_campo_de_telefono_exige_celular_y_lo_guarda_sin_mas(): void
    {
        $evento = $this->evento([
            ['key' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'enabled' => true],
            ['key' => 'email', 'label' => 'Correo', 'type' => 'email', 'required' => true, 'enabled' => true],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'tel', 'required' => true, 'enabled' => true],
        ]);

        $this->post("/f/{$evento->slug}", ['first_name' => 'Ana', 'email' => 'ana@colegio.cl', 'whatsapp' => 'llamar tarde'])
            ->assertOk()
            ->assertSee('56912345678');
        $this->assertSame(0, ProgramRequest::count());

        $this->post("/f/{$evento->slug}", ['first_name' => 'Ana', 'email' => 'ana@colegio.cl', 'whatsapp' => '+56 9 1234 5678'])->assertOk();

        $this->assertSame('56912345678', ProgramRequest::sole()->extra['whatsapp']);
    }
}
