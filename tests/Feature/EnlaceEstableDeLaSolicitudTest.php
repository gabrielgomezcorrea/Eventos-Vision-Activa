<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Mail\EnlaceDeAcceso;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\ProgramRequest;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EnlaceEstableDeLaSolicitudTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
    }

    private function datos(): array
    {
        return [
            'first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@colegio.cl',
            'position' => 'Directivo', 'institution' => 'Colegio San José', 'phone' => '56912345678', 'marketing' => '1',
        ];
    }

    /** @return list<string> URLs de los correos del programa encolados. */
    private function urlsEncoladas(): array
    {
        return Mail::queued(ProgramaDelEvento::class)->map->urlDeInscripcion->values()->all();
    }

    private function solicitud(): ProgramRequest
    {
        return ProgramRequest::create([
            'event_id' => $this->event->id, 'first_name' => 'Ana', 'last_name' => 'Pérez',
            'email' => 'ana@colegio.cl', 'consented_at' => now(),
        ]);
    }

    private function coordinacion(): User
    {
        $this->seed(RolesSeeder::class);
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole('coordinacion');

        return $usuario->fresh();
    }

    public function test_dos_reenvios_del_panel_mandan_la_misma_url_y_abrirla_deja_un_solo_borrador(): void
    {
        $solicitud = $this->solicitud();
        $this->actingAs($this->coordinacion());

        $this->post(route('solicitudes.reenviar', $solicitud));
        $this->post(route('solicitudes.reenviar', $solicitud));

        [$primera, $segunda] = $this->urlsEncoladas();
        $this->assertSame($primera, $segunda);
        $this->assertSame(1, MagicLink::count());

        $this->get($primera)->assertRedirect();
        $this->get($segunda)->assertRedirect();

        $this->assertSame(1, Order::count());
        $this->assertSame(2, $solicitud->fresh()->program_sent_count);
    }

    public function test_pedir_el_programa_de_nuevo_con_el_mismo_correo_manda_la_misma_url(): void
    {
        $this->post("/f/{$this->event->slug}", $this->datos());
        RateLimiter::clear('formulario-publico');
        $this->post("/f/{$this->event->slug}", $this->datos());

        $urls = $this->urlsEncoladas();
        $this->assertCount(2, $urls);
        $this->assertSame($urls[0], $urls[1]);
        $this->assertSame(1, MagicLink::count());
    }

    public function test_el_token_se_guarda_cifrado_y_no_en_claro(): void
    {
        $this->actingAs($this->coordinacion());
        $this->post(route('solicitudes.reenviar', $this->solicitud()));

        $token = basename($this->urlsEncoladas()[0]);
        $crudo = \DB::table('magic_links')->value('token_encrypted');

        $this->assertNotNull($crudo);
        $this->assertNotSame($token, $crudo);
        $this->assertSame($token, MagicLink::sole()->token_encrypted);
    }

    public function test_pedir_el_enlace_con_el_correo_de_la_solicitud_manda_la_url_estable(): void
    {
        $this->actingAs($this->coordinacion());
        $this->post(route('solicitudes.reenviar', $this->solicitud()));
        $estable = $this->urlsEncoladas()[0];

        $this->post(route('inscripcion.solicitar', ['event' => $this->event->slug]), ['email' => 'ana@colegio.cl']);

        Mail::assertQueued(EnlaceDeAcceso::class, fn (EnlaceDeAcceso $correo) => $correo->url === $estable);
        $this->assertSame(1, MagicLink::count());
    }

    public function test_con_la_inscripcion_cancelada_se_emite_un_enlace_nuevo_sin_revocar_el_viejo(): void
    {
        $this->actingAs($this->coordinacion());
        $solicitud = $this->solicitud();
        $this->post(route('solicitudes.reenviar', $solicitud));
        $viejo = $this->urlsEncoladas()[0];
        $this->get($viejo);

        Order::sole()->forceFill(['status' => OrderStatus::Cancelada])->save();

        $this->post(route('solicitudes.reenviar', $solicitud));

        $urls = $this->urlsEncoladas();
        $this->assertNotSame($viejo, $urls[1]);
        $this->assertSame(2, MagicLink::count());
        $this->assertSame(0, MagicLink::whereNotNull('revoked_at')->count());
    }

    public function test_el_borrador_creado_desde_el_enlace_guarda_su_solicitud(): void
    {
        $this->actingAs($this->coordinacion());
        $solicitud = $this->solicitud();
        $this->post(route('solicitudes.reenviar', $solicitud));

        $this->get($this->urlsEncoladas()[0])->assertRedirect();

        $this->assertSame($solicitud->id, Order::sole()->program_request_id);
    }

    public function test_confirmar_con_otro_correo_marca_la_solicitud_como_inscrita(): void
    {
        $this->actingAs($this->coordinacion());
        $solicitud = $this->solicitud();
        $this->post(route('solicitudes.reenviar', $solicitud));
        $this->get($this->urlsEncoladas()[0]);

        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 1000]);
        $orden = Order::sole();
        $orden->forceFill([
            'responsible_name' => 'Bea', 'responsible_lastname' => 'Soto', 'responsible_email' => 'bea@otro.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ])->save();
        $orden->participants()->create(['first_name' => 'Luis', 'access_type_id' => $acceso->id]);

        app(ConfirmarOrden::class)($orden->fresh());

        $this->assertNotNull($solicitud->fresh()->converted_at);
    }

    public function test_con_otra_inscripcion_viva_el_reenvio_no_devuelve_el_enlace_de_una_orden_distinta(): void
    {
        $this->actingAs($this->coordinacion());
        $solicitud = $this->solicitud();
        $this->post(route('solicitudes.reenviar', $solicitud));
        $primera = $this->urlsEncoladas()[0];
        $this->get($primera);

        Order::sole()->forceFill(['status' => OrderStatus::Finalizada])->save();
        Order::create(['event_id' => $this->event->id, 'responsible_email' => 'ana@colegio.cl', 'responsible_name' => 'Ana']);

        $this->post(route('solicitudes.reenviar', $solicitud));

        $this->assertNotSame($primera, $this->urlsEncoladas()[1]);
    }
}
