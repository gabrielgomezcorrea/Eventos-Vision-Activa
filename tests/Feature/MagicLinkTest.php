<?php

namespace Tests\Feature;

use App\Actions\EmitirEnlaceDeAcceso;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Mail\EnlaceDeAcceso;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class MagicLinkTest extends TestCase
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

    public function test_el_token_se_guarda_hasheado_y_nunca_en_claro(): void
    {
        [$link, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');

        $this->assertNotSame($token, $link->token_hash);
        $this->assertSame(hash('sha256', $token), $link->token_hash);

        // El token en claro no debe aparecer en ninguna columna de la fila.
        foreach ($link->fresh()->getAttributes() as $valor) {
            $this->assertNotSame($token, $valor);
        }
    }

    public function test_resuelve_el_enlace_a_partir_del_token(): void
    {
        [$link, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');

        $this->assertTrue($link->is(MagicLink::resolver($token)));
        $this->assertNull(MagicLink::resolver('token-inventado'));
    }

    public function test_un_enlace_vencido_no_resuelve(): void
    {
        [$link, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $link->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertNull(MagicLink::resolver($token));
    }

    public function test_un_enlace_revocado_no_resuelve(): void
    {
        [$link, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $link->revocar();

        $this->assertNull(MagicLink::resolver($token));
    }

    public function test_el_correo_normaliza_mayusculas_y_espacios(): void
    {
        [$link] = MagicLink::emitir($this->event, '  Ana@Colegio.CL ');

        $this->assertSame('ana@colegio.cl', $link->email);
    }

    public function test_el_enlace_apunta_a_la_orden_viva_del_correo(): void
    {
        $borrador = Order::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'ana@colegio.cl',
            'responsible_name' => 'Ana',
            'status' => OrderStatus::Borrador,
        ]);

        app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl');

        $this->assertSame($borrador->id, MagicLink::sole()->order_id);
    }

    public function test_no_retoma_ordenes_canceladas_ni_vencidas(): void
    {
        Order::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'ana@colegio.cl',
            'responsible_name' => 'Ana',
            'status' => OrderStatus::Cancelada,
        ]);

        app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl');

        $this->assertNull(MagicLink::sole()->order_id);
    }

    public function test_limita_la_cantidad_de_enlaces_por_correo(): void
    {
        $max = config('magic_links.max_per_window');

        for ($i = 0; $i < $max; $i++) {
            $this->assertTrue(app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl'));
        }

        // Superado el límite, deja de enviar. Evita usar el formulario para
        // enviar correo no deseado a direcciones de terceros.
        $this->assertFalse(app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl'));

        Mail::assertQueuedCount($max);
    }

    public function test_envia_el_correo_con_el_enlace(): void
    {
        app(EmitirEnlaceDeAcceso::class)($this->event, 'ana@colegio.cl');

        Mail::assertQueued(EnlaceDeAcceso::class, fn ($m) => $m->hasTo('ana@colegio.cl'));
    }

    public function test_registra_el_uso_del_enlace(): void
    {
        [$link, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');

        $this->assertSame(0, $link->uses);

        $this->get(route('inscripcion.acceso', ['token' => $token]));

        $this->assertSame(1, $link->fresh()->uses);
        $this->assertNotNull($link->fresh()->last_used_at);
    }
}
