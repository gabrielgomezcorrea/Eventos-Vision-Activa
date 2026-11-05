<?php

namespace Tests\Feature;

use App\Actions\ConfirmarConjunto;
use App\Actions\ConfirmarOrden;
use App\Actions\IniciarInscripcionAdicional;
use App\Actions\ModificarInscripcion;
use App\Enums\EventStatus;
use App\Enums\Rol;
use App\Mail\OrdenConfirmada;
use App\Models\AccessType;
use App\Models\DiscountCode;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PayerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Código «Por jornada»: precio final por persona según su acceso. Es plata:
 * el total sale de la suma por persona, nunca de un porcentaje.
 */
class CodigoPorJornadaTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    private AccessType $j1;

    private AccessType $j2;

    private AccessType $ambas;

    private DiscountCode $codigo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->evento = Event::create(['name' => 'CMEI 2026', 'slug' => 'cmei-2026', 'status' => EventStatus::Publicado]);
        $s1 = $this->evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 100]);
        $s2 = $this->evento->sessions()->create(['name' => 'Jornada 2', 'position' => 2, 'capacity' => 100]);
        $this->j1 = $this->acceso('Jornada 1', 120000, [$s1]);
        $this->j2 = $this->acceso('Jornada 2', 120000, [$s2]);
        $this->ambas = $this->acceso('Ambas Jornadas', 180000, [$s1, $s2]);
        $this->evento->discountTiers()->create(['min_participants' => 2, 'type' => 'percent', 'value' => 10]);

        $this->codigo = $this->codigoPorJornada([$this->j1->id => 80000, $this->j2->id => 80000, $this->ambas->id => 140000]);
    }

    /** @param  array<int, mixed>  $sesiones */
    private function acceso(string $nombre, int $precio, array $sesiones, array $extra = []): AccessType
    {
        $acceso = $this->evento->accessTypes()->create(['name' => $nombre, 'position' => 1, 'price' => $precio, ...$extra]);
        foreach ($sesiones as $sesion) {
            $acceso->sessions()->attach($sesion->id, ['seats' => 1]);
        }

        return $acceso;
    }

    /** @param  array<int, int>  $precios  access_type_id => precio */
    private function codigoPorJornada(array $precios, string $codigo = 'CMEI2026'): DiscountCode
    {
        $creado = DiscountCode::factory()->create([
            'event_id' => $this->evento->id, 'code' => $codigo, 'type' => 'per_access', 'value' => 0,
        ]);
        foreach ($precios as $accesoId => $precio) {
            $creado->preciosPorAcceso()->attach($accesoId, ['price' => $precio]);
        }

        return $creado;
    }

    /** @param  array<int, AccessType|null>  $accesos */
    private function borrador(array $accesos, ?DiscountCode $codigo = null, string $correo = 'ana@colegio.cl'): Order
    {
        $codigo ??= $this->codigo;
        $orden = $this->evento->orders()->create([
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => $correo,
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
            'discount_code_id' => $codigo->id, 'discount_code_email' => $correo,
        ]);
        foreach ($accesos as $i => $acceso) {
            $orden->participants()->create(['first_name' => 'Persona', 'last_name' => (string) $i, 'access_type_id' => $acceso?->id]);
        }

        return $orden->fresh();
    }

    public function test_el_ejemplo_de_altazor_da_300000_en_borrador_y_confirmado(): void
    {
        $orden = $this->borrador([$this->j1, $this->j2, $this->ambas]);

        $this->assertSame(420000, $orden->calcularSubtotal());
        $this->assertSame(120000, $orden->calcularDescuento());
        $this->assertSame(300000, $orden->calcularTotal());

        $confirmada = app(ConfirmarOrden::class)($orden);

        $this->assertSame(420000, (int) $confirmada->subtotal);
        $this->assertSame(120000, (int) $confirmada->discount_amount);
        $this->assertSame(300000, (int) $confirmada->total);
        $this->assertSame('Código CMEI-2026 (Precio por jornada)', $confirmada->discount_label);
        $this->assertSame(1, $this->codigo->refresh()->used_people);
    }

    public function test_un_acceso_que_el_codigo_no_lista_paga_su_precio_normal(): void
    {
        $codigo = $this->codigoPorJornada([$this->j1->id => 80000], 'SOLOJ1');
        $orden = $this->borrador([$this->j1, $this->j2], $codigo);

        $confirmada = app(ConfirmarOrden::class)($orden);

        $this->assertSame(40000, (int) $confirmada->discount_amount);
        $this->assertSame(200000, (int) $confirmada->total);
    }

    public function test_un_participante_sin_acceso_no_descuenta_ni_rompe(): void
    {
        $orden = $this->borrador([$this->j1, null]);

        $this->assertSame(40000, $orden->calcularDescuento());
    }

    public function test_el_precio_anticipado_mas_bajo_que_el_especial_se_cobra_y_el_mas_alto_no(): void
    {
        $manana = now()->addDay()->toDateString();
        $this->j1->update(['early_price' => 70000, 'early_until' => $manana]);
        $this->j2->update(['early_price' => 100000, 'early_until' => $manana]);

        $orden = $this->borrador([$this->j1, $this->j2]);
        $confirmada = app(ConfirmarOrden::class)($orden);

        // J1: anticipado 70.000 < especial 80.000, no se reclama. J2: manda el especial.
        $this->assertSame(20000, (int) $confirmada->discount_amount);
        $this->assertSame(150000, (int) $confirmada->total);
    }

    public function test_el_descuento_por_cantidad_no_se_suma_al_codigo(): void
    {
        $orden = $this->borrador([$this->j1, $this->j2, $this->ambas]);

        $this->assertSame('Código CMEI-2026 (Precio por jornada)', app(ConfirmarOrden::class)($orden)->discount_label);
    }

    public function test_dos_colegios_del_mismo_conjunto_pagan_su_precio_por_persona(): void
    {
        $orden = $this->borrador([]);
        $norte = Establishment::factory()->create(['name' => 'Colegio Norte']);
        $sur = Establishment::factory()->create(['name' => 'Colegio Sur']);
        $orden->establishments()->attach([$norte->id, $sur->id]);
        foreach ([[$norte, $this->j1], [$norte, $this->ambas], [$sur, $this->j2]] as $i => [$colegio, $acceso]) {
            $orden->participants()->create(['first_name' => 'P', 'last_name' => (string) $i, 'access_type_id' => $acceso->id, 'establishment_id' => $colegio->id]);
        }
        $orden->forceFill(['group_id' => OrderGroup::paraResponsable($this->evento, 'ana@colegio.cl')->id])->save();
        $a = $orden;

        $ordenes = collect(app(ConfirmarConjunto::class)($a->fresh()));

        $this->assertSame(
            [220000, 80000],
            $ordenes->sortByDesc('total')->pluck('total')->map(fn ($t) => (int) $t)->values()->all(),
        );
        $this->assertSame(1, $this->codigo->refresh()->used_people);
    }

    public function test_con_todos_los_precios_en_cero_confirma_en_cero_y_aprobada(): void
    {
        $gratis = $this->codigoPorJornada([$this->j1->id => 0, $this->j2->id => 0], 'GRATIS');
        $confirmada = app(ConfirmarOrden::class)($this->borrador([$this->j1, $this->j2], $gratis));

        $this->assertSame(0, (int) $confirmada->total);
        $this->assertSame('approved', $confirmada->payment_status->value);
    }

    public function test_cambiar_el_acceso_de_una_persona_sin_abonos_recalcula(): void
    {
        $orden = app(ConfirmarOrden::class)($this->borrador([$this->j1]));
        $this->assertSame(80000, (int) $orden->total);

        $participante = $orden->participants()->first();
        $participante->forceFill(['access_type_id' => $this->ambas->id, 'unit_price' => 180000])->save();
        $this->recalcular($orden);

        $this->assertSame(140000, (int) $orden->fresh()->total);
    }

    public function test_inscribir_mas_participantes_cobra_el_precio_por_persona(): void
    {
        $original = app(ConfirmarOrden::class)($this->borrador([$this->j1]));
        $adicional = app(IniciarInscripcionAdicional::class)($original);
        $this->assertSame($this->codigo->id, $adicional->discount_code_id);
        $adicional->participants()->create(['first_name' => 'Nuevo', 'access_type_id' => $this->ambas->id]);
        $adicional->participants()->create(['first_name' => 'Otro', 'access_type_id' => $this->j2->id]);

        $confirmada = app(ConfirmarOrden::class)($adicional->fresh());

        $this->assertSame(220000, (int) $confirmada->total);
        $this->assertSame(1, $this->codigo->refresh()->used_people, 'Sigue siendo la misma persona.');
    }

    public function test_un_tramo_no_acepta_el_tipo_por_jornada(): void
    {
        $admin = $this->usuarioConRol(Rol::Administrador);

        $this->actingAs($admin)
            ->post(route('eventos.descuentos.store', $this->evento), ['min_participants' => 9, 'type' => 'per_access', 'value' => 10])
            ->assertSessionHasErrors('type');
    }

    public function test_el_portal_tacha_el_precio_normal_y_muestra_el_del_codigo_solo_con_el_codigo_aplicado(): void
    {
        $orden = $this->borrador([$this->j1]);
        [, $token] = MagicLink::emitir($this->evento, 'ana@colegio.cl', $orden);
        $url = route('inscripcion.portal.inscripcion', ['token' => $token]);

        $this->get($url)->assertSee('<s style="color:var(--texto-suave)">$120.000</s>', false)->assertSee('$80.000');

        $orden->forceFill(['discount_code_id' => null, 'discount_code_email' => null])->save();

        $this->get($url)->assertDontSee('<s style="color:var(--texto-suave)">', false);
    }

    public function test_el_correo_de_confirmacion_tacha_el_precio_normal_con_el_codigo(): void
    {
        $orden = app(ConfirmarOrden::class)($this->borrador([$this->j1, $this->ambas]));

        $html = (new OrdenConfirmada($orden))->render();

        $this->assertMatchesRegularExpression('/<s[^>]*>\$120\.000<\/s>/', $html);
        $this->assertMatchesRegularExpression('/<strong[^>]*>\$80\.000<\/strong>/', $html);
        $this->assertMatchesRegularExpression('/<s[^>]*>\$180\.000<\/s>/', $html);
    }

    private function recalcular(Order $orden): void
    {
        $metodo = new \ReflectionMethod(ModificarInscripcion::class, 'recalcular');
        $metodo->invoke(app(ModificarInscripcion::class), $orden->fresh());
    }
}
