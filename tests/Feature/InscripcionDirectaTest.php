<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\ExportarContactos;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\Rol;
use App\Mail\EnlaceDeAcceso;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\ProgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InscripcionDirectaTest extends TestCase
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

    private function pedirEnlace(string $email = 'ana@colegio.cl'): void
    {
        $this->post(route('inscripcion.solicitar', ['event' => $this->event->slug]), ['email' => $email])->assertOk();
    }

    /** @return list<string> */
    private function urlsEnviadas(): array
    {
        return Mail::queued(EnlaceDeAcceso::class)->map->url->values()->all();
    }

    public function test_el_clic_crea_la_solicitud_directa_y_el_borrador_unido_a_ella(): void
    {
        $this->pedirEnlace();

        $this->assertSame(0, ProgramRequest::count());
        $this->assertCount(1, $this->urlsEnviadas());

        $this->get($this->urlsEnviadas()[0])->assertRedirect();

        $solicitud = ProgramRequest::sole();
        $this->assertTrue($solicitud->esDirecta());
        $this->assertSame('ana@colegio.cl', $solicitud->email);
        $this->assertNull($solicitud->first_name);
        $this->assertNotNull($solicitud->consented_at);
        $this->assertNull($solicitud->marketing_consented_at);
        $this->assertSame($solicitud->id, Order::sole()->program_request_id);

        $enlace = MagicLink::sole();
        $this->assertSame($solicitud->id, $enlace->program_request_id);
        $this->assertNotNull($enlace->token_encrypted);
        $this->assertSame('ana@colegio.cl', $solicitud->nombreParaMostrar());
    }

    public function test_pedir_el_enlace_otra_vez_manda_la_misma_url(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);

        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));
        $this->pedirEnlace();

        $urls = $this->urlsEnviadas();
        $this->assertCount(2, $urls);
        $this->assertSame($urls[0], $urls[1]);
    }

    public function test_dos_enlaces_sueltos_del_mismo_correo_convergen_en_el_primero(): void
    {
        $this->pedirEnlace();
        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));
        $this->pedirEnlace();

        [$primero, $segundo] = $this->urlsEnviadas();
        $this->assertNotSame($primero, $segundo);

        $this->get($primero)->assertRedirect();
        $this->get($segundo)->assertRedirect($primero);

        $this->assertSame(1, ProgramRequest::count());
        $this->assertSame(1, Order::count());
    }

    public function test_con_una_solicitud_del_formulario_no_nace_otra(): void
    {
        $formulario = ProgramRequest::create([
            'event_id' => $this->event->id, 'first_name' => 'Ana', 'email' => 'ana@colegio.cl', 'consented_at' => now(),
        ]);

        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0])->assertRedirect();

        $this->assertSame(1, ProgramRequest::count());
        $this->assertFalse($formulario->fresh()->esDirecta());
        $this->assertSame($formulario->id, Order::sole()->program_request_id);
    }

    public function test_confirmar_la_orden_marca_la_solicitud_directa_como_inscrita(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);

        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 1000]);
        $orden = Order::sole();
        $orden->forceFill([
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Soto', 'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ])->save();
        $orden->participants()->create(['first_name' => 'Luis', 'access_type_id' => $acceso->id]);

        app(ConfirmarOrden::class)($orden->fresh());

        $this->assertNotNull(ProgramRequest::sole()->converted_at);
    }

    public function test_la_exportacion_de_contactos_deja_fuera_las_solicitudes_directas(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);

        foreach ([false, true] as $marketing) {
            $resultado = app(ExportarContactos::class)(['marketing' => $marketing, 'tipos' => ['solicitud']]);

            $this->assertStringNotContainsString('ana@colegio.cl', json_encode($resultado));
        }
    }

    public function test_con_una_orden_viva_previa_el_clic_la_retoma_sin_crear_otro_borrador(): void
    {
        // El enlace sale sin orden y el panel crea una antes del clic.
        $this->pedirEnlace();
        $orden = Order::factory()->for($this->event)->create([
            'responsible_email' => 'ana@colegio.cl', 'status' => OrderStatus::Borrador,
        ]);

        $this->get($this->urlsEnviadas()[0])->assertRedirect();

        $this->assertSame(1, Order::count());
        $this->assertSame($orden->id, MagicLink::sole()->order_id);
        $this->assertTrue(ProgramRequest::sole()->esDirecta());
    }

    public function test_el_correo_del_enlace_trae_el_enlace_para_copiar_y_la_vigencia_real(): void
    {
        $this->pedirEnlace();
        $html = Mail::queued(EnlaceDeAcceso::class)->first()->render();

        $this->assertStringContainsString('También puedes reenviar este correo a tu encargado de inscribir', $html);
        $this->assertStringContainsString('O puedes copiar el siguiente enlace a la persona encargada', $html);
        $this->assertStringContainsString('El enlace sirve por', $html);

        $this->event->update(['starts_on' => now()->addMonth()->toDateString()]);
        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));
        $this->pedirEnlace();

        $this->assertStringContainsString('El enlace sirve hasta el', Mail::queued(EnlaceDeAcceso::class)->last()->render());
    }

    public function test_la_pagina_del_correo_muestra_el_banner_solo_si_external_esta_marcada(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.banner.store', $this->event), [
                'banner' => UploadedFile::fake()->image('afiche.jpg', 1200, 400),
            ]);
        $this->event->refresh();

        $this->event->update(['banner_placements' => ['external']]);
        $this->get(route('inscripcion.inicio', $this->event->slug))->assertSee($this->event->bannerUrl(), false);

        $this->event->update(['banner_placements' => ['form', 'mail']]);
        $this->get(route('inscripcion.inicio', $this->event->slug))->assertDontSee('/banner', false);
    }

    public function test_el_panel_muestra_el_correo_como_nombre_y_el_enlace_directo(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);
        $admin = $this->usuarioConRol(Rol::Administrador);

        $this->actingAs($admin)
            ->get(route('solicitudes.index'))
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->where('solicitudes.data.0.persona', 'ana@colegio.cl')
                ->where('solicitudes.data.0.directa', true));

        $this->get(route('eventos.show', $this->event))
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->where('evento.enlace_directo', route('inscripcion.inicio', ['event' => $this->event->slug])));
    }

    public function test_la_pagina_muestra_el_contacto_del_evento(): void
    {
        $this->event->update(['contact_name' => 'María Rojas', 'contact_phone' => '56912345678']);

        $this->get(route('inscripcion.inicio', $this->event->slug))
            ->assertSee('María Rojas')
            ->assertSee('+56 9 1234 5678');

        $this->post(route('inscripcion.solicitar', ['event' => $this->event->slug]), ['email' => 'ana@colegio.cl'])
            ->assertSee('María Rojas');
    }

    public function test_el_banner_del_correo_va_centrado_a_todo_el_ancho(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.banner.store', $this->event), [
                'banner' => UploadedFile::fake()->image('afiche.jpg', 1200, 400),
            ]);
        $this->event->refresh();

        $html = (new EnlaceDeAcceso(event: $this->event->refresh(), url: 'http://x/y'))->render();

        $this->assertMatchesRegularExpression('/<td align="center"[^>]*>\s*<img src="[^"]*banner[^"]*"[^>]*width: 100%/s', $html);
        $this->assertStringNotContainsString('<pre', $html);
    }

    public function test_la_ficha_toma_la_institucion_del_responsable_de_su_inscripcion(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);
        Order::sole()->forceFill(['responsible_institution' => 'Colegio San José'])->save();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->get(route('solicitudes.show', ProgramRequest::sole()))
            ->assertInertia(fn (Assert $pagina) => $pagina->where('solicitud.institucion', 'Colegio San José'));
    }

    public function test_el_panel_completa_los_datos_del_solicitante_y_queda_auditado(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);
        $solicitud = ProgramRequest::sole();

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->patch(route('solicitudes.update', $solicitud), [
                'first_name' => 'abigail', 'last_name' => 'fuentes castro', 'phone' => '+56 9 1234 5678',
                'position' => 'Otro', 'position_otro' => 'encargada de compras', 'institution' => 'Colegio Concepción Hualqui',
            ])
            ->assertRedirect();

        $solicitud->refresh();
        $this->assertSame('Abigail', $solicitud->first_name);
        $this->assertSame('56912345678', $solicitud->phone);
        $this->assertSame('Encargada de Compras', $solicitud->position);
        $this->assertSame('ana@colegio.cl', $solicitud->email, 'El correo no se edita.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'solicitud.corregida']);
    }

    public function test_sin_permiso_no_se_editan_los_datos_del_solicitante(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);

        $this->actingAs($this->usuarioConRol(Rol::Contabilidad))
            ->patch(route('solicitudes.update', ProgramRequest::sole()), ['first_name' => 'Intruso'])
            ->assertForbidden();
    }

    public function test_un_telefono_de_relleno_se_rechaza_con_un_mensaje_claro(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->patch(route('solicitudes.update', ProgramRequest::sole()), ['phone' => '900000000'])
            ->assertSessionHasErrors('phone');
    }

    public function test_el_lapiz_viene_precargado_con_los_datos_del_responsable(): void
    {
        $this->pedirEnlace();
        $this->get($this->urlsEnviadas()[0]);
        Order::sole()->forceFill([
            'responsible_name' => 'Abigail', 'responsible_lastname' => 'Fuentes', 'responsible_phone' => '56912345678',
            'responsible_position' => 'Directivo', 'responsible_institution' => 'Colegio Hualqui',
        ])->save();

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('solicitudes.show', ProgramRequest::sole()))
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->where('editar.first_name', 'Abigail')
                ->where('editar.phone', '56912345678')
                ->where('editar.institution', 'Colegio Hualqui')
                ->where('solicitud.telefono', '56912345678'));
    }
}
