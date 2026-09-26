<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerDelEventoTest extends TestCase
{
    use RefreshDatabase;

    public function test_administracion_sube_el_banner_y_queda_visible_sin_sesion(): void
    {
        Storage::fake(config('filesystems.private_disk'));

        $evento = Event::factory()->create();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.banner.store', $evento), [
                'banner' => UploadedFile::fake()->image('afiche.jpg', 1200, 400),
            ])
            ->assertRedirect();

        $evento->refresh();
        $this->assertTrue($evento->tieneBanner());

        // El correo lo pide desde el cliente de la persona: sin sesión.
        $this->get($evento->bannerUrl())->assertOk();
    }

    public function test_el_banner_rechaza_formatos_que_no_se_ven_en_correo(): void
    {
        Storage::fake(config('filesystems.private_disk'));

        $evento = Event::factory()->create();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.banner.store', $evento), [
                'banner' => UploadedFile::fake()->create('animado.gif', 100, 'image/gif'),
            ])
            ->assertSessionHasErrors('banner');

        $this->assertFalse($evento->refresh()->tieneBanner());
    }

    public function test_contabilidad_no_puede_cambiar_el_banner(): void
    {
        Storage::fake(config('filesystems.private_disk'));

        $evento = Event::factory()->create();

        $this->actingAs($this->usuarioConRol(Rol::Contabilidad))
            ->post(route('eventos.banner.store', $evento), [
                'banner' => UploadedFile::fake()->image('afiche.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_un_evento_sin_banner_responde_404(): void
    {
        $evento = Event::factory()->create();

        $this->get(route('publico.evento.banner', ['event' => $evento->slug]))->assertNotFound();
    }

    private function eventoConBanner(): Event
    {
        Storage::fake(config('filesystems.private_disk'));

        $evento = Event::factory()->create();
        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.banner.store', $evento), [
                'banner' => UploadedFile::fake()->image('afiche.jpg', 1200, 400),
            ]);

        return $evento->refresh();
    }

    public function test_sin_imagen_no_se_dibuja_nada_arriba(): void
    {
        $evento = Event::factory()->create(['name' => 'Seminario Sin Banner']);

        $correo = view('mail._banner', ['evento' => $evento])->render();
        $pagina = view('publico._banner', ['evento' => $evento, 'ubicacion' => 'form'])->render();

        $this->assertSame('', trim($correo));
        $this->assertSame('', trim($pagina));
    }

    public function test_con_imagen_sale_solo_la_imagen_sin_texto_encima(): void
    {
        $evento = $this->eventoConBanner();

        $pagina = view('publico._banner', ['evento' => $evento, 'ubicacion' => 'form'])->render();

        $this->assertStringContainsString($evento->bannerUrl(), $pagina);
        $this->assertSame('', trim(strip_tags($pagina)));
    }

    public function test_cada_ubicacion_muestra_el_banner_solo_si_esta_marcada(): void
    {
        $evento = $this->eventoConBanner();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->patch(route('eventos.banner.update', $evento), [
                'mail' => '0', 'form' => '1', 'external' => '1',
            ])
            ->assertRedirect();

        $evento->refresh();
        $this->assertTrue($evento->muestraBannerEn('form'));
        $this->assertFalse($evento->muestraBannerEn('mail'));

        $this->get(route('publico.programa', $evento->slug))->assertSee('og:image', false);

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->patch(route('eventos.banner.update', $evento), ['mail' => '1', 'form' => '1', 'external' => '0']);

        $this->get(route('publico.programa', $evento->slug))->assertDontSee('og:image', false);
        $this->get(route('publico.programa.embed', $evento->slug))->assertDontSee('/banner?', false);
    }

    public function test_sin_banner_no_hay_vista_previa_para_redes(): void
    {
        $evento = Event::factory()->create();

        $this->get(route('publico.programa', $evento->slug))
            ->assertDontSee('og:image', false)
            ->assertSee('twitter:card" content="summary"', false);
    }

    public function test_contabilidad_no_puede_cambiar_las_ubicaciones_del_banner(): void
    {
        $evento = $this->eventoConBanner();

        $this->actingAs($this->usuarioConRol(Rol::Contabilidad))
            ->patch(route('eventos.banner.update', $evento), [
                'mail' => '0', 'form' => '0', 'external' => '0',
            ])
            ->assertForbidden();

        $this->assertNull($evento->refresh()->banner_placements);
    }

    public function test_un_evento_sin_publicar_lo_dice_en_vez_de_un_404_mudo(): void
    {
        $evento = Event::factory()->borrador()->create();

        $this->get(route('publico.programa', $evento->slug))
            ->assertNotFound()
            ->assertSee('Evento sin publicar');
        $this->get(route('inscripcion.inicio', $evento->slug))
            ->assertNotFound()
            ->assertSee('Evento sin publicar');
    }
}
