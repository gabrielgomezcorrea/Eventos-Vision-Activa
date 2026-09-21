<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\Rol;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Support\WebsAutorizadas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FormularioEmbebidoTest extends TestCase
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

    public function test_sin_origenes_configurados_no_se_puede_embeber(): void
    {
        $this->get("/f/{$this->event->slug}/embed")
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function test_administracion_autoriza_una_web_y_el_formulario_se_puede_embeber_ahi(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('webs.store'), ['dominio' => ' https://www.LiderazgoEscolar.cl/inscripcion '])
            ->assertSessionHasNoErrors();

        $this->assertSame(['liderazgoescolar.cl'], WebsAutorizadas::lista());

        $this->post(route('webs.store'), ['dominio' => 'liderazgoescolar'])->assertSessionHasErrors('dominio');
        $this->post(route('webs.store'), ['dominio' => 'liderazgoescolar.cl'])->assertSessionHasErrors('dominio');

        $this->get("/f/{$this->event->slug}/embed")
            ->assertOk()
            ->assertHeader(
                'Content-Security-Policy',
                "frame-ancestors 'self' https://liderazgoescolar.cl https://*.liderazgoescolar.cl",
            );
    }

    public function test_normaliza_lo_que_se_pega_y_rechaza_lo_que_no_es_un_website(): void
    {
        $validos = [
            'liderazgoescolar.cl' => 'liderazgoescolar.cl',
            'HTTPS://WWW.LiderazgoEscolar.CL/' => 'liderazgoescolar.cl',
            'http://publicidad.visionactiva.cl:8080/landing?x=1#form' => 'publicidad.visionactiva.cl',
            'www.colegio-san-jose.cl.' => 'colegio-san-jose.cl',
            'ñuñoa.cl' => 'xn--uoa-6mab.cl',
        ];

        foreach ($validos as $escrito => $esperado) {
            $this->assertSame($esperado, WebsAutorizadas::normalizar($escrito), "falló con: {$escrito}");
        }

        foreach (['', '.', '-', 'hola', 'x.c', 'http://', 'correo@colegio.cl', '*.colegio.cl', '127.0.0.1', 'localhost', '-colegio.cl', 'colegio..cl', '😀.cl', str_repeat('a', 64).'.cl'] as $basura) {
            $this->assertNull(WebsAutorizadas::normalizar($basura), "aceptó: {$basura}");
        }

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('webs.store'), ['dominio' => 'liderazgoescolar.cl, visionactiva.cl'])
            ->assertSessionHasErrors(['dominio' => 'Agrega un website a la vez, como liderazgoescolar.cl.']);
    }

    public function test_solo_administracion_gestiona_las_webs(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));

        $this->get(route('webs.index'))->assertForbidden();
        $this->post(route('webs.store'), ['dominio' => 'otro.cl'])->assertForbidden();
        $this->delete(route('webs.destroy'), ['dominio' => 'otro.cl'])->assertForbidden();
    }

    public function test_no_envia_x_frame_options_que_anularia_la_politica(): void
    {
        WebsAutorizadas::guardar(['liderazgoescolar.cl']);

        $this->get("/f/{$this->event->slug}/embed")
            ->assertOk()
            ->assertHeaderMissing('X-Frame-Options');
    }

    public function test_la_vista_embebida_no_establece_cookies(): void
    {
        // Si el formulario dependiera de cookies, se rompería dentro de un
        // iframe de otro dominio en Safari y Chrome.
        $respuesta = $this->get("/f/{$this->event->slug}/embed")->assertOk();

        $this->assertSame([], $respuesta->headers->getCookies());
    }

    public function test_el_envio_embebido_tampoco_establece_cookies(): void
    {
        $respuesta = $this->post("/f/{$this->event->slug}/embed", [
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@colegio.cl',
            'phone' => '56912345678',
            'position' => 'Directivo',
            'institution' => 'Liceo A-12',
        ]);

        $respuesta->assertOk()->assertSee('Revisa tu correo');

        $this->assertSame([], $respuesta->headers->getCookies());
        $this->assertSame(1, ProgramRequest::count());
    }

    public function test_la_confirmacion_ocurre_en_la_misma_pantalla_sin_redirigir(): void
    {
        // Dentro de un iframe una redirección fuera del sitio anfitrión sería
        // desconcertante para el visitante.
        $this->post("/f/{$this->event->slug}/embed", [
            'first_name' => 'Ana',
            'email' => 'ana@colegio.cl',
            'last_name' => 'Pérez',
        ])->assertOk()->assertDontSee('Redirecting');
    }
}
