<?php

namespace Tests\Feature;

use App\Actions\BuscarParticipantes;
use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La búsqueda manual es el respaldo cuando el QR no se deja leer o el
 * participante llegó sin credencial.
 */
class BusquedaEnAcreditacionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::factory()->conJornadasYAccesos(50)->create();
    }

    private function inscribir(array $datos): Ticket
    {
        $acceso = $this->event->accessTypes()->first();
        $orden = Order::factory()->create(['event_id' => $this->event->id]);
        $establecimiento = Establishment::factory()->create(['name' => $datos['establecimiento'] ?? 'Colegio San José']);
        $orden->establishments()->attach($establecimiento);

        Participant::factory()->create(array_merge([
            'order_id' => $orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $acceso->id,
        ], collect($datos)->except('establecimiento')->all()));

        $orden = app(ConfirmarOrden::class)($orden->fresh());
        $orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($orden->fresh());

        return $orden->fresh()->tickets()->vigentes()->sole();
    }

    private function buscar(string $consulta)
    {
        return app(BuscarParticipantes::class)($this->event, $consulta);
    }

    public function test_encuentra_por_nombre_y_por_apellido(): void
    {
        $ticket = $this->inscribir(['first_name' => 'Ana', 'last_name' => 'Pérez Soto', 'email' => 'ana@colegio.cl']);

        foreach (['Ana', 'Pérez', 'Soto', 'ana pérez'] as $consulta) {
            $this->assertTrue(
                $this->buscar($consulta)->contains(fn (Ticket $t) => $t->is($ticket)),
                "Deberia encontrarlo buscando '{$consulta}'",
            );
        }
    }

    public function test_encuentra_con_el_nombre_completo_en_cualquier_orden(): void
    {
        $ticket = $this->inscribir(['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'a@b.cl']);

        $this->assertTrue($this->buscar('Pérez Ana')->contains(fn (Ticket $t) => $t->is($ticket)));
    }

    public function test_encuentra_por_rut_escrito_de_cualquier_forma(): void
    {
        $ticket = $this->inscribir(['first_name' => 'Luis', 'last_name' => 'Soto', 'rut' => '76086428-5']);

        foreach (['76086428-5', '76.086.428-5', '760864285', '76086428'] as $consulta) {
            $this->assertTrue(
                $this->buscar($consulta)->contains(fn (Ticket $t) => $t->is($ticket)),
                "Deberia encontrarlo buscando '{$consulta}'",
            );
        }
    }

    public function test_encuentra_por_correo_codigo_y_numero_de_inscripcion(): void
    {
        $ticket = $this->inscribir(['first_name' => 'Mario', 'last_name' => 'Díaz', 'email' => 'mario@colegio.cl']);

        foreach ([$ticket->code, $ticket->order->number, 'mario@colegio.cl'] as $consulta) {
            $this->assertTrue(
                $this->buscar($consulta)->contains(fn (Ticket $t) => $t->is($ticket)),
                "Deberia encontrarlo buscando '{$consulta}'",
            );
        }
    }

    public function test_no_devuelve_participantes_de_ordenes_canceladas_o_vencidas(): void
    {
        $ticket = $this->inscribir(['first_name' => 'Elena', 'last_name' => 'Vidal']);

        $ticket->order->forceFill(['status' => OrderStatus::Cancelada])->save();

        $this->assertTrue($this->buscar('Elena')->isEmpty());
    }

    public function test_no_devuelve_credenciales_anuladas(): void
    {
        $ticket = $this->inscribir(['first_name' => 'Jorge', 'last_name' => 'Muñoz']);
        $ticket->revocar('Reemplazo');

        $this->assertTrue($this->buscar('Jorge')->isEmpty());
    }

    public function test_no_mezcla_participantes_de_otro_evento(): void
    {
        $this->inscribir(['first_name' => 'Sofía', 'last_name' => 'Reyes']);

        $otroEvento = Event::factory()->conJornadasYAccesos(10)->create();

        $this->assertTrue(app(BuscarParticipantes::class)($otroEvento, 'Sofía')->isEmpty());
    }

    public function test_ignora_consultas_demasiado_cortas(): void
    {
        $this->inscribir(['first_name' => 'Ana', 'last_name' => 'Pérez']);

        $this->assertTrue($this->buscar('a')->isEmpty());
        $this->assertTrue($this->buscar('')->isEmpty());
    }

    public function test_los_resultados_vienen_ordenados_por_nombre(): void
    {
        foreach ([['Zoe', 'Ruiz'], ['Ana', 'Bravo'], ['Marco', 'Lillo']] as [$n, $a]) {
            $this->inscribir(['first_name' => $n, 'last_name' => $a]);
        }

        $nombres = $this->buscar('o')->map(fn (Ticket $t) => $t->participant->first_name);

        $this->assertSame($nombres->sort()->values()->all(), $nombres->values()->all());
    }
}
