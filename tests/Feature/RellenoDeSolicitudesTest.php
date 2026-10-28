<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\ProgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RellenoDeSolicitudesTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_relleno_une_ordenes_a_su_solicitud_y_cuenta_el_envio(): void
    {
        $evento = Event::factory()->create();
        $otro = Event::factory()->create();

        $solicitud = ProgramRequest::factory()->create([
            'event_id' => $evento->id, 'email' => 'ana@colegio.cl', 'program_sent_at' => now(),
        ]);
        $sinEnvio = ProgramRequest::factory()->create(['event_id' => $evento->id, 'email' => 'luis@colegio.cl', 'program_sent_at' => null]);

        $orden = Order::factory()->create(['event_id' => $evento->id, 'responsible_email' => ' Ana@Colegio.cl ']);
        $ajena = Order::factory()->create(['event_id' => $otro->id, 'responsible_email' => 'ana@colegio.cl']);

        (require database_path('migrations/2026_10_27_100000_link_magic_links_to_program_requests.php'))->rellenar();

        $this->assertSame(1, $solicitud->fresh()->program_sent_count);
        $this->assertSame(0, $sinEnvio->fresh()->program_sent_count);
        $this->assertSame($solicitud->id, $orden->fresh()->program_request_id);
        $this->assertNull($ajena->fresh()->program_request_id);
    }
}
