<?php

namespace Tests\Feature;

use App\Actions\CambiarCorreoDelResponsable;
use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\Rol;
use App\Mail\OrdenConfirmada;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PayerEntity;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CorreoDelResponsableTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create(['name' => 'Seminario', 'slug' => 'seminario', 'status' => EventStatus::Publicado]);
        [, $this->token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $this->token]));
    }

    private function datos(array $extra = []): array
    {
        return $extra + [
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_position' => 'Directivo',
            'responsible_email' => 'ana@colegio.cl', 'responsible_phone' => '56912345678',
            'responsible_institution' => 'Colegio San José', 'kind' => OrderKind::Institucional->value,
        ];
    }

    public function test_en_borrador_el_correo_nuevo_se_guarda_normalizado_y_el_enlace_viejo_sigue(): void
    {
        $this->post(route('inscripcion.responsable.guardar', ['token' => $this->token]), $this->datos([
            'responsible_email' => ' Bea@Otro.CL ',
        ]))->assertRedirect();

        $this->assertSame('bea@otro.cl', Order::sole()->responsible_email);
        $this->get(route('inscripcion.portal.inscripcion', ['token' => $this->token]))->assertOk();
    }

    public function test_un_correo_invalido_devuelve_el_bloque_con_el_mensaje_y_lo_escrito(): void
    {
        $this->post(route('inscripcion.responsable.guardar', ['token' => $this->token]), $this->datos([
            'responsible_email' => 'esto-no-es-un-correo',
        ]))
            ->assertOk()
            ->assertSee('Escribe un solo correo')
            ->assertSee('esto-no-es-un-correo');

        $this->assertSame('', Order::sole()->responsible_email);
    }

    public function test_en_un_conjunto_cambia_en_todas_las_ordenes_sin_tocar_el_codigo_ni_el_correo_del_grupo(): void
    {
        $orden = Order::sole();
        $grupo = OrderGroup::paraResponsable($this->event, 'ana@colegio.cl');
        $orden->forceFill(['group_id' => $grupo->id, 'discount_code_email' => 'ana@colegio.cl'])->save();
        $hermana = Order::create([
            'event_id' => $this->event->id, 'group_id' => $grupo->id,
            'responsible_email' => 'ana@colegio.cl', 'responsible_name' => 'Ana',
            'discount_code_email' => 'ana@colegio.cl',
        ]);

        app(CambiarCorreoDelResponsable::class)($orden, 'bea@otro.cl');

        $this->assertSame('bea@otro.cl', $orden->fresh()->responsible_email);
        $this->assertSame('bea@otro.cl', $hermana->fresh()->responsible_email);
        $this->assertSame('ana@colegio.cl', $grupo->fresh()->responsible_email);
        $this->assertSame('ana@colegio.cl', $hermana->fresh()->discount_code_email);
        $this->assertSame(2, AuditLog::where('action', 'orden.correo_responsable_cambiado')->count());
    }

    public function test_la_confirmacion_sale_al_correo_nuevo(): void
    {
        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 1000]);
        $orden = Order::sole();
        $orden->forceFill([
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ])->save();
        $orden->participants()->create(['first_name' => 'Luis', 'access_type_id' => $acceso->id]);

        app(CambiarCorreoDelResponsable::class)($orden, 'bea@otro.cl');
        app(ConfirmarOrden::class)($orden->fresh());

        Mail::assertQueued(OrdenConfirmada::class, fn (OrdenConfirmada $correo) => $correo->hasTo('bea@otro.cl'));
    }

    public function test_quien_pide_su_enlace_no_recibe_una_inscripcion_ajena_que_puso_su_correo(): void
    {
        $this->post(route('inscripcion.responsable.guardar', ['token' => $this->token]), $this->datos([
            'responsible_email' => 'bea@otro.cl',
        ]))->assertRedirect();
        $ajena = Order::sole();

        RateLimiter::clear('magic-link:email:'.sha1('bea@otro.cl'));
        $this->post(route('inscripcion.solicitar', ['event' => $this->event->slug]), ['email' => 'bea@otro.cl']);

        $this->assertNull(MagicLink::where('email', 'bea@otro.cl')->where('order_id', $ajena->id)->first());
    }

    public function test_al_confirmar_no_entra_al_conjunto_de_quien_tiene_el_correo_nuevo(): void
    {
        $grupoDeBea = OrderGroup::paraResponsable($this->event, 'bea@otro.cl');
        Order::create(['event_id' => $this->event->id, 'group_id' => $grupoDeBea->id, 'responsible_email' => 'bea@otro.cl', 'responsible_name' => 'Bea']);

        $acceso = $this->event->accessTypes()->create(['name' => 'General', 'position' => 1, 'price' => 1000]);
        $orden = Order::where('responsible_email', '')->sole();
        $orden->forceFill([
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_email' => 'bea@otro.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ])->save();
        $orden->participants()->create(['first_name' => 'Luis', 'access_type_id' => $acceso->id]);

        $this->post(route('inscripcion.confirmar', ['token' => $this->token]));

        $this->assertNotSame($grupoDeBea->id, $orden->fresh()->group_id);
    }

    public function test_coordinacion_corrige_el_correo_desde_el_panel_y_un_rol_sin_permiso_no(): void
    {
        $this->seed(RolesSeeder::class);
        $orden = Order::sole();

        $this->actingAs($this->usuarioConRol(Rol::Contabilidad))
            ->patch(route('inscripciones.responsable.correo', $orden), ['responsible_email' => 'x@otro.cl'])
            ->assertForbidden();

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->patch(route('inscripciones.responsable.correo', $orden), ['responsible_email' => ' Bea@Otro.cl '])
            ->assertRedirect();

        $this->assertSame('bea@otro.cl', $orden->fresh()->responsible_email);
    }

    public function test_sin_correo_de_responsable_no_se_confirma(): void
    {
        $orden = Order::sole();
        $orden->forceFill(['responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez'])->save();

        $faltantes = collect(app(ConfirmarOrden::class)->faltantes($orden->fresh()))->pluck('bloque');

        $this->assertContains('responsable', $faltantes);
    }

    public function test_pedir_el_enlace_retoma_el_borrador_con_el_responsable_aun_vacio(): void
    {
        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));
        $this->post(route('inscripcion.solicitar', ['event' => $this->event->slug]), ['email' => 'ana@colegio.cl']);

        $this->assertSame(1, Order::count());
        $this->assertNotNull(MagicLink::where('email', 'ana@colegio.cl')->where('order_id', Order::sole()->id)->latest('id')->first());
    }

    public function test_un_codigo_puesto_antes_de_llenar_el_responsable_queda_a_nombre_de_quien_abrio_el_enlace(): void
    {
        $codigo = DiscountCode::factory()->create(['event_id' => $this->event->id]);

        $this->post(route('inscripcion.codigo.aplicar', ['token' => $this->token]), ['codigo' => $codigo->code]);

        $orden = Order::sole();
        $this->assertSame($codigo->id, $orden->discount_code_id);
        $this->assertSame('ana@colegio.cl', $orden->discount_code_email);
    }
}
