<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\ProgramRequest;
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
        config(['embed.allowed_origins' => '']);

        $this->get("/f/{$this->event->slug}/embed")
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function test_permite_los_origenes_configurados(): void
    {
        config(['embed.allowed_origins' => 'https://www.liderazgoescolar.cl, https://otro.cl']);

        $this->get("/f/{$this->event->slug}/embed")
            ->assertOk()
            ->assertHeader(
                'Content-Security-Policy',
                "frame-ancestors 'self' https://www.liderazgoescolar.cl https://otro.cl",
            );
    }

    public function test_no_envia_x_frame_options_que_anularia_la_politica(): void
    {
        config(['embed.allowed_origins' => 'https://www.liderazgoescolar.cl']);

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
            'phone' => '+56912345678',
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
