<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Support\Forms\ProgramFormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FormularioDeCaptacionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026',
            'slug' => 'seminario-2026',
            'status' => EventStatus::Publicado,
        ]);
    }

    /** La jornada no viene entre los campos base: hay que agregarla. */
    private function conCampoDeJornada(): void
    {
        $this->event->update(['program_form_fields' => array_merge(
            ProgramFormField::porDefectoComoArray(),
            [['key' => 'access_type_id', 'label' => 'Jornada de interés', 'type' => 'select',
                'required' => false, 'enabled' => true, 'options' => []]],
        )]);
    }

    private function datosValidos(array $extra = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@colegio.cl',
            'position' => 'Directivo',
            'institution' => 'Colegio San José',
            'phone' => '56912345678',
            'marketing' => '1',
        ], $extra);
    }

    public function test_el_formulario_carga_con_los_campos_base_y_sin_texto_de_relleno(): void
    {
        // Va embebido en una landing que ya tiene su titular y su bajada: aquí
        // solo los campos.
        $this->get("/f/{$this->event->slug}")
            ->assertOk()
            ->assertDontSee('Recibe el programa en tu correo')
            ->assertDontSee('Completa tus datos')
            ->assertSee('Nombre')
            ->assertSee('Apellidos')
            ->assertSee('Correo electrónico')
            ->assertSee('Cargo')
            ->assertSee('Establecimiento')
            ->assertSee('Teléfono');
    }

    public function test_un_evento_no_publicado_no_expone_formulario(): void
    {
        $this->event->update(['status' => EventStatus::Borrador]);

        $this->get("/f/{$this->event->slug}")->assertNotFound();
    }

    public function test_guarda_la_solicitud_y_encola_el_correo(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datosValidos())
            ->assertOk()
            ->assertSee('Revisa tu correo');

        $solicitud = ProgramRequest::sole();

        $this->assertSame('Ana', $solicitud->first_name);
        $this->assertSame('ana@colegio.cl', $solicitud->email);
        $this->assertSame($this->event->id, $solicitud->event_id);
        $this->assertNotNull($solicitud->consented_at);
        // Coordinación distingue en el listado a quién ya se le respondió.
        $this->assertNotNull($solicitud->program_sent_at);

        Mail::assertQueued(ProgramaDelEvento::class, fn ($m) => $m->hasTo('ana@colegio.cl'));
    }

    public function test_conserva_los_datos_ingresados_cuando_hay_error(): void
    {
        // Sin sesión no hay old(), así que los valores deben volver desde el request.
        $respuesta = $this->post("/f/{$this->event->slug}", $this->datosValidos([
            'email' => 'no-es-un-correo',
        ]));

        $respuesta->assertOk()
            ->assertSee('value="Ana"', escape: false)
            ->assertSee('value="Colegio San José"', escape: false);

        $this->assertSame(0, ProgramRequest::count());
    }

    public function test_el_correo_es_obligatorio_y_valido(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datosValidos(['email' => '']))
            ->assertOk()
            ->assertSee('correo electrónico');

        $this->assertSame(0, ProgramRequest::count());
    }

    public function test_el_honeypot_no_guarda_y_avisa_que_no_se_envio(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datosValidos(['website' => 'http://spam.cl']))
            ->assertOk()
            ->assertSee('No pudimos procesar tu envío')
            ->assertDontSee('Revisa tu correo');

        $this->assertSame(0, ProgramRequest::count());
        Mail::assertNothingQueued();
    }

    public function test_un_envio_demasiado_rapido_no_se_guarda_y_lo_dice_conservando_lo_escrito(): void
    {
        $sello = Crypt::encryptString((string) now()->getTimestamp());

        $this->post("/f/{$this->event->slug}", $this->datosValidos(['sello' => $sello]))
            ->assertOk()
            ->assertSee('demasiado rápido y no se envió')
            ->assertSee('value="Ana"', escape: false)
            ->assertDontSee('Revisa tu correo');

        $this->assertSame(0, ProgramRequest::count());
        Mail::assertNothingQueued();

        // Pasados los segundos mínimos, el mismo envío sí sale.
        $antiguo = Crypt::encryptString((string) now()->subSeconds(10)->getTimestamp());
        $this->post("/f/{$this->event->slug}", $this->datosValidos(['sello' => $antiguo]))
            ->assertSee('Revisa tu correo');
    }

    public function test_solo_una_rafaga_de_mas_de_veinte_envios_con_el_mismo_correo_se_frena(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post("/f/{$this->event->slug}", $this->datosValidos())->assertSee('Revisa tu correo');
        }

        $this->post("/f/{$this->event->slug}", $this->datosValidos())
            ->assertOk()
            ->assertSee('demasiados envíos seguidos')
            ->assertDontSee('Revisa tu correo');

        $this->assertSame(20, ProgramRequest::count());
    }

    public function test_no_requiere_token_csrf_ni_sesion(): void
    {
        // Se envía sin token y sin cookies: debe funcionar igual, porque el
        // formulario vive embebido en sitios de terceros.
        $this->post("/f/{$this->event->slug}", $this->datosValidos())
            ->assertOk()
            ->assertSee('Revisa tu correo');

        $this->assertSame(1, ProgramRequest::count());
    }

    public function test_la_jornada_de_interes_guarda_id_y_etiqueta(): void
    {
        $this->conCampoDeJornada();

        $acceso = $this->event->accessTypes()->create([
            'name' => 'Ambas jornadas', 'position' => 1, 'price' => 150000,
        ]);

        $this->post("/f/{$this->event->slug}", $this->datosValidos([
            'access_type_id' => $acceso->id,
        ]))->assertOk();

        $solicitud = ProgramRequest::sole();

        $this->assertSame($acceso->id, $solicitud->access_type_id);
        $this->assertSame('Ambas jornadas', $solicitud->interest_label);
    }

    public function test_rechaza_una_jornada_que_no_pertenece_al_evento(): void
    {
        $this->conCampoDeJornada();

        $otro = Event::create(['name' => 'Otro', 'slug' => 'otro', 'status' => EventStatus::Publicado]);
        $ajeno = $otro->accessTypes()->create(['name' => 'Ajeno', 'position' => 1, 'price' => 1000]);

        $this->event->accessTypes()->create(['name' => 'Propio', 'position' => 1, 'price' => 1000]);

        $this->post("/f/{$this->event->slug}", $this->datosValidos(['access_type_id' => $ajeno->id]))
            ->assertOk()
            ->assertSee('Elige una de las opciones de la lista');

        $this->assertSame(0, ProgramRequest::count());
    }

    public function test_sin_autorizar_el_uso_de_los_datos_no_se_envia_el_programa(): void
    {
        $datos = $this->datosValidos();
        unset($datos['marketing']);

        $this->post("/f/{$this->event->slug}", $datos)
            ->assertOk()
            ->assertSee('Marca la casilla para autorizar')
            ->assertDontSee('Revisa tu correo');

        $this->assertSame(0, ProgramRequest::count());
        Mail::assertNotQueued(ProgramaDelEvento::class);
    }

    public function test_un_celular_con_forma_valida_pero_puesto_al_azar_se_rechaza(): void
    {
        foreach (['56900000000', '56911111111', '56988888888', '56993939393'] as $i => $telefono) {
            $this->post("/f/{$this->event->slug}", $this->datosValidos(['phone' => $telefono, 'email' => "ana{$i}@colegio.cl"]))
                ->assertOk()
                ->assertSee('escribe tu celular real');
        }

        $this->assertSame(0, ProgramRequest::count());

        $this->post("/f/{$this->event->slug}", $this->datosValidos(['phone' => '+56 9 8765 4321']))
            ->assertSee('Revisa tu correo');
    }
}
