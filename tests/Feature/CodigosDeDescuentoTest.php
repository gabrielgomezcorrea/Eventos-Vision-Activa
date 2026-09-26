<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Support\CodigoDeDescuento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodigosDeDescuentoTest extends TestCase
{
    use RefreshDatabase;

    private function datos(array $cambios = []): array
    {
        return [
            'code' => 'K7QX-M4PR',
            'type' => 'percent',
            'value' => '20',
            'max_people' => '10',
            'expires_on' => now()->addWeek()->format('Y-m-d'),
            ...$cambios,
        ];
    }

    public function test_el_codigo_generado_siempre_es_valido_y_dificil_de_confundir(): void
    {
        $evento = Event::factory()->create();

        for ($i = 0; $i < 200; $i++) {
            $codigo = CodigoDeDescuento::generar();

            $this->assertMatchesRegularExpression('/^[ACDEFGHJKMNPQRTUVWXY234679]{8}$/', $codigo);
            $this->assertMatchesRegularExpression('/[A-Z]/', $codigo);
            $this->assertMatchesRegularExpression('/\d/', $codigo);
        }
    }

    public function test_administracion_crea_un_codigo_y_se_guarda_normalizado(): void
    {
        $evento = Event::factory()->create();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.codigos.store', $evento), $this->datos(['code' => ' k7qx m4pr ']))
            ->assertSessionHasNoErrors();

        $codigo = DiscountCode::sole();
        $this->assertSame('K7QXM4PR', $codigo->code);
        $this->assertSame('K7QX-M4PR', $codigo->formateado());
        $this->assertSame(0, $codigo->used_people);
    }

    public function test_descuento_completo_se_guarda_como_100_sin_pedir_valor(): void
    {
        $evento = Event::factory()->create();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.codigos.store', $evento), $this->datos(['type' => 'full', 'value' => '']))
            ->assertSessionHasNoErrors();

        $codigo = DiscountCode::sole();
        $this->assertSame(100, $codigo->value);
        $this->assertSame('Descuento completo', $codigo->etiqueta());
    }

    public function test_el_maximo_de_personas_va_de_1_a_99(): void
    {
        $evento = Event::factory()->create();
        $admin = $this->usuarioConRol(Rol::Administrador);

        foreach (['0', '100'] as $malo) {
            $this->actingAs($admin)
                ->post(route('eventos.codigos.store', $evento), $this->datos(['max_people' => $malo]))
                ->assertSessionHasErrors('max_people');
        }
    }

    public function test_rechaza_codigos_faciles_de_adivinar(): void
    {
        $evento = Event::factory()->create();
        $admin = $this->usuarioConRol(Rol::Administrador);

        foreach (['FREE2026', 'gratis123', 'DESCUENTO50', 'ABCDEFGH', '12345678', 'K7Q', 'K7QX!M4PR'] as $malo) {
            $this->actingAs($admin)
                ->post(route('eventos.codigos.store', $evento), $this->datos(['code' => $malo]))
                ->assertSessionHasErrors('code');
        }

        $this->assertSame(0, DiscountCode::count());
    }

    public function test_tope_y_fecha_son_obligatorios(): void
    {
        $evento = Event::factory()->create();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->post(route('eventos.codigos.store', $evento), $this->datos(['max_people' => '', 'expires_on' => '']))
            ->assertSessionHasErrors(['max_people', 'expires_on']);
    }

    public function test_no_se_repite_un_codigo_en_el_mismo_evento_pero_si_en_otro(): void
    {
        $evento = Event::factory()->create();
        $otro = Event::factory()->create();
        $admin = $this->usuarioConRol(Rol::Administrador);

        $this->actingAs($admin)->post(route('eventos.codigos.store', $evento), $this->datos());
        $this->actingAs($admin)->post(route('eventos.codigos.store', $evento), $this->datos(['code' => 'k7qx-m4pr']))
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)->post(route('eventos.codigos.store', $otro), $this->datos())
            ->assertSessionHasNoErrors();
    }

    public function test_administracion_ve_el_listado_con_un_codigo_sugerido(): void
    {
        $evento = Event::factory()->create();
        DiscountCode::factory()->create(['event_id' => $evento->id]);

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->get(route('eventos.codigos.index', $evento))
            ->assertOk()
            ->assertInertia(fn ($pagina) => $pagina
                ->component('eventos/codigos')
                ->has('codigos', 1)
                ->where('sugerido', fn (string $codigo) => preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo) === 1));
    }

    public function test_la_ficha_del_evento_lista_sus_codigos_solo_a_administracion(): void
    {
        $evento = Event::factory()->create();
        $codigo = DiscountCode::factory()->create(['event_id' => $evento->id]);

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->get(route('eventos.show', $evento))
            ->assertInertia(fn ($pagina) => $pagina->has('codigos', 1)->where('codigos.0', fn (string $l) => str_starts_with($l, $codigo->formateado())));

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('eventos.show', $evento))
            ->assertInertia(fn ($pagina) => $pagina->has('codigos', 0));
    }

    public function test_solo_administracion_ve_y_crea_codigos(): void
    {
        $evento = Event::factory()->create();

        foreach ([Rol::Coordinacion, Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $usuario = $this->usuarioConRol($rol);

            $this->actingAs($usuario)->get(route('eventos.codigos.index', $evento))->assertForbidden();
            $this->actingAs($usuario)->post(route('eventos.codigos.store', $evento), $this->datos())->assertForbidden();
        }

        $this->assertSame(0, DiscountCode::count());
    }

    public function test_no_se_puede_bajar_el_tope_por_debajo_de_lo_usado(): void
    {
        $evento = Event::factory()->create();
        $codigo = DiscountCode::factory()->create(['event_id' => $evento->id, 'max_people' => 10, 'used_people' => 6]);
        $admin = $this->usuarioConRol(Rol::Administrador);

        $this->actingAs($admin)
            ->patch(route('eventos.codigos.update', [$evento, $codigo]), ['max_people' => '5'])
            ->assertSessionHasErrors('max_people');

        $this->actingAs($admin)
            ->patch(route('eventos.codigos.update', [$evento, $codigo]), ['is_active' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($codigo->refresh()->is_active);
        $this->assertSame('inactive', $codigo->estado());
    }

    public function test_un_codigo_de_otro_evento_no_se_edita_desde_este(): void
    {
        $evento = Event::factory()->create();
        $ajeno = DiscountCode::factory()->create();

        $this->actingAs($this->usuarioConRol(Rol::Administrador))
            ->patch(route('eventos.codigos.update', [$evento, $ajeno]), ['is_active' => '0'])
            ->assertNotFound();
    }
}
