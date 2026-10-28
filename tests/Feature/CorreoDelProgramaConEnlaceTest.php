<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Mail\EnlaceDeAcceso;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\ProgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CorreoDelProgramaConEnlaceTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
    }

    private function datos(array $extra = []): array
    {
        return $extra + [
            'first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@colegio.cl',
            'position' => 'Directivo', 'institution' => 'Colegio San José', 'phone' => '56912345678', 'marketing' => '1',
        ];
    }

    public function test_pedir_el_programa_encola_un_solo_correo_con_el_enlace_de_acceso(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datos())->assertOk();

        Mail::assertQueued(ProgramaDelEvento::class, 1);
        Mail::assertNotQueued(EnlaceDeAcceso::class);
        // Pedir el programa no crea ninguna orden: el borrador nace al hacer clic.
        $this->assertSame(0, Order::count());

        $url = null;
        Mail::assertQueued(ProgramaDelEvento::class, function (ProgramaDelEvento $correo) use (&$url): bool {
            $url = $correo->urlDeInscripcion;

            return true;
        });

        $this->assertStringContainsString('/i/acceso/', $url);
        $this->assertStringContainsString($url, (new ProgramaDelEvento(
            ProgramRequest::sole(), $url,
        ))->render());
    }

    public function test_el_enlace_del_correo_abre_la_inscripcion_con_el_responsable_vacio_y_la_solicitud_unida(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datos());

        $url = null;
        Mail::assertQueued(ProgramaDelEvento::class, function (ProgramaDelEvento $correo) use (&$url): bool {
            $url = $correo->urlDeInscripcion;

            return true;
        });

        $this->get($url)->assertRedirect();
        $orden = Order::sole();

        // El solicitante no es necesariamente el responsable: el bloque nace
        // vacío y la solicitud queda unida por detrás.
        $this->assertSame('', $orden->responsible_name);
        $this->assertNull($orden->responsible_lastname);
        $this->assertSame('', $orden->responsible_email);
        $this->assertSame(ProgramRequest::sole()->id, $orden->program_request_id);
    }

    public function test_con_una_inscripcion_viva_el_enlace_la_retoma(): void
    {
        $orden = Order::create([
            'event_id' => $this->event->id, 'responsible_email' => 'ana@colegio.cl', 'responsible_name' => 'Ana',
        ]);

        $this->post("/f/{$this->event->slug}", $this->datos());

        $url = null;
        Mail::assertQueued(ProgramaDelEvento::class, function (ProgramaDelEvento $correo) use (&$url): bool {
            $url = $correo->urlDeInscripcion;

            return true;
        });

        $token = basename($url);
        $this->assertTrue(MagicLink::resolver($token)->order->is($orden));
        $this->assertSame(1, Order::count());
    }

    public function test_un_honeypot_no_emite_enlace(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datos(['website' => 'http://spam.cl']));

        Mail::assertNotQueued(ProgramaDelEvento::class);
        $this->assertSame(0, MagicLink::count());
    }

    public function test_al_confirmar_la_inscripcion_la_solicitud_queda_marcada_sola(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datos());
        $solicitud = ProgramRequest::sole();
        $this->assertNull($solicitud->converted_at);

        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 1000]);
        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Luis', 'access_type_id' => $acceso->id]);

        app(ConfirmarOrden::class)($orden->fresh());

        $this->assertNotNull($solicitud->fresh()->converted_at);
    }

    public function test_el_correo_del_programa_trae_el_boton_y_el_enlace_como_texto(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datos());
        $solicitud = ProgramRequest::sole();

        $url = 'https://eventos.test/i/acceso/abc123';
        $html = (new ProgramaDelEvento($solicitud, $url))->render();

        $this->assertStringContainsString('Continuar con la inscripción', $html);
        $this->assertStringContainsString('O puedes copiar el siguiente enlace a la persona encargada de realizar el proceso:', $html);
        $this->assertSame(2, substr_count($html, $url), 'El botón y el texto plano.');
        $this->assertStringNotContainsString('Este botón te lleva directo', $html);
    }
}
