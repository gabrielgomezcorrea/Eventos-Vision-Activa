<?php

namespace Tests\Feature;

use App\Actions\EmitirEnlaceDeAcceso;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Mail\EnlaceDeAcceso;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ConjuntoDeInscripcionesTest extends TestCase
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

    public function test_el_enlace_de_un_conjunto_no_abre_ordenes_de_otro_responsable(): void
    {
        $grupoAna = OrderGroup::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'ana@colegio.cl',
        ]);

        $ordenDeAna = Order::create([
            'event_id' => $this->event->id,
            'group_id' => $grupoAna->id,
            'responsible_email' => 'ana@colegio.cl',
            'responsible_name' => 'Ana',
            'status' => OrderStatus::Reservada,
        ]);

        $grupoBeto = OrderGroup::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'beto@colegio.cl',
        ]);

        $ordenDeBeto = Order::create([
            'event_id' => $this->event->id,
            'group_id' => $grupoBeto->id,
            'responsible_email' => 'beto@colegio.cl',
            'responsible_name' => 'Beto',
            'status' => OrderStatus::Reservada,
        ]);

        [, $token] = MagicLink::emitirParaGrupo($this->event, 'ana@colegio.cl', $grupoAna);

        $resuelto = MagicLink::resolver($token);
        $idsDelConjunto = $resuelto->group->orders->pluck('id');

        $this->assertTrue($resuelto->group->is($grupoAna));
        $this->assertTrue($idsDelConjunto->contains($ordenDeAna->id));
        $this->assertFalse($idsDelConjunto->contains($ordenDeBeto->id));
        $this->assertSame(1, $resuelto->group->orders()->count());
    }

    public function test_un_enlace_de_conjunto_no_tiene_orden_propia(): void
    {
        $grupo = OrderGroup::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'ana@colegio.cl',
        ]);

        [$link] = MagicLink::emitirParaGrupo($this->event, 'ana@colegio.cl', $grupo);

        $this->assertNull($link->order_id);
        $this->assertSame($grupo->id, $link->order_group_id);
    }

    public function test_al_retomar_una_orden_viva_queda_agrupada(): void
    {
        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));

        $borrador = Order::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'ana@colegio.cl',
            'responsible_name' => 'Ana',
            'status' => OrderStatus::Borrador,
        ]);

        app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl');

        $this->assertNotNull($borrador->fresh()->group_id);
    }

    public function test_con_dos_ordenes_vigentes_del_mismo_conjunto_manda_un_solo_enlace(): void
    {
        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));

        $grupo = OrderGroup::create(['event_id' => $this->event->id, 'responsible_email' => 'ana@colegio.cl']);
        $sanJose = Order::create([
            'event_id' => $this->event->id, 'group_id' => $grupo->id,
            'responsible_email' => 'ana@colegio.cl', 'responsible_name' => 'Ana', 'status' => OrderStatus::Reservada,
        ]);
        $losRobles = Order::create([
            'event_id' => $this->event->id, 'group_id' => $grupo->id,
            'responsible_email' => 'ana@colegio.cl', 'responsible_name' => 'Ana', 'status' => OrderStatus::Reservada,
        ]);

        app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl');

        Mail::assertQueuedCount(1);
        Mail::assertQueued(EnlaceDeAcceso::class, function ($correo) use ($sanJose, $losRobles): bool {
            return $correo->colegios !== null
                && $correo->colegios->count() === 2
                && $correo->colegios->pluck('id')->sort()->values()->all()
                    === collect([$sanJose->id, $losRobles->id])->sort()->values()->all();
        });

        $token = MagicLink::sole();
        $this->assertNull($token->order_id);
        $this->assertSame($grupo->id, $token->order_group_id);
    }

    public function test_un_enlace_de_conjunto_va_directo_al_estado_sin_crear_borrador(): void
    {
        $grupo = OrderGroup::create(['event_id' => $this->event->id, 'responsible_email' => 'ana@colegio.cl']);
        Order::create([
            'event_id' => $this->event->id, 'group_id' => $grupo->id,
            'responsible_email' => 'ana@colegio.cl', 'responsible_name' => 'Ana', 'status' => OrderStatus::Reservada,
        ]);

        [, $token] = MagicLink::emitirParaGrupo($this->event, 'ana@colegio.cl', $grupo);

        $this->get(route('inscripcion.acceso', ['token' => $token]))
            ->assertRedirect(route('inscripcion.estado', ['token' => $token]));

        $this->assertSame(1, Order::count(), 'No se creó un borrador nuevo.');
    }

    public function test_inscribir_otro_establecimiento_no_reusa_el_conjunto_anterior(): void
    {
        RateLimiter::clear('magic-link:email:'.sha1('ana@colegio.cl'));

        $primera = Order::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'ana@colegio.cl',
            'responsible_name' => 'Ana',
            'status' => OrderStatus::Reservada,
        ]);

        app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl', nuevaInscripcion: true);

        $this->assertNull($primera->fresh()->group_id);
        $this->assertSame(0, OrderGroup::count());
    }
}
