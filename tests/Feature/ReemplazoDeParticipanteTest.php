<?php

namespace Tests\Feature;

use App\Actions\AcreditarParticipante;
use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Actions\ReemplazarParticipante;
use App\Actions\ResolverCredencial;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResultadoAcreditacion;
use App\Exceptions\ReemplazoNoPermitido;
use App\Mail\CredencialParticipante;
use App\Models\AuditLog;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReemplazoDeParticipanteTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Order $orden;

    private Participant $saliente;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesSeeder::class);

        $this->event = Event::factory()->conJornadasYAccesos(50)->create([
            'replacement_deadline' => now()->addMonth(),
        ]);

        $acceso = $this->event->accessTypes()->where('name', 'Ambas jornadas')->sole();
        $this->orden = Order::factory()->create(['event_id' => $this->event->id]);
        $establecimiento = Establishment::factory()->create();
        $this->orden->establishments()->attach($establecimiento);

        Participant::factory()->create([
            'order_id' => $this->orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $acceso->id,
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'email' => 'carlos@colegio.cl',
        ]);

        $this->orden = app(ConfirmarOrden::class)($this->orden->fresh());
        $this->saliente = $this->orden->participants()->sole();
    }

    private function aprobarYEmitir(): void
    {
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($this->orden->fresh());
        $this->orden->refresh();
        $this->saliente->refresh();
    }

    private function reemplazar(array $datos = []): Participant
    {
        return app(ReemplazarParticipante::class)(
            $this->saliente->fresh(),
            array_merge(['first_name' => 'Luisa', 'last_name' => 'Soto', 'email' => 'luisa@colegio.cl'], $datos),
            User::factory()->create(['name' => 'Carmen Coordinación']),
            'El titular no puede asistir.',
        );
    }

    public function test_conserva_el_acceso_y_el_valor(): void
    {
        $this->aprobarYEmitir();

        $entrante = $this->reemplazar();

        $this->assertSame($this->saliente->access_type_id, $entrante->access_type_id);
        $this->assertSame($this->saliente->unit_price, $entrante->unit_price);

        // El total de la orden no cambia: no hay nada que cobrar ni devolver.
        $this->assertSame($this->orden->total, $this->orden->fresh()->total);
    }

    public function test_el_saliente_queda_en_el_historial_y_no_se_borra(): void
    {
        $entrante = $this->reemplazar();

        $saliente = $this->saliente->fresh();

        $this->assertSame(ParticipantStatus::Reemplazado, $saliente->status);
        $this->assertSame($entrante->id, $saliente->replaced_by_id);
        $this->assertNotNull($saliente->replaced_at);
        $this->assertSame(2, $this->orden->fresh()->participants()->count());

        // Pero deja de contar como participante vigente.
        $this->assertSame(1, $this->orden->fresh()->participantesVigentes()->count());
    }

    public function test_anula_la_credencial_anterior_y_emite_una_nueva(): void
    {
        $this->aprobarYEmitir();
        $ticketAnterior = $this->saliente->ticketVigente();

        $entrante = $this->reemplazar();

        $this->assertFalse($ticketAnterior->fresh()->estaVigente());
        $this->assertStringContainsString('titular no puede asistir', $ticketAnterior->fresh()->revoked_reason);

        $nuevo = $entrante->ticketVigente();
        $this->assertNotNull($nuevo);
        $this->assertNotSame($ticketAnterior->code, $nuevo->code);
    }

    public function test_el_qr_anulado_no_sirve_en_la_puerta(): void
    {
        $this->aprobarYEmitir();
        $ticketAnterior = $this->saliente->ticketVigente();

        $this->reemplazar();

        $resultado = app(ResolverCredencial::class)($ticketAnterior->token);

        $this->assertSame(ResultadoAcreditacion::Anulada, $resultado->tipo);
        $this->assertFalse($resultado->permiteAcreditar());
    }

    public function test_le_envia_la_credencial_al_entrante(): void
    {
        $this->aprobarYEmitir();

        $this->reemplazar(['email' => 'luisa@colegio.cl']);

        Mail::assertQueued(CredencialParticipante::class, fn ($m) => $m->hasTo('luisa@colegio.cl'));
    }

    public function test_sin_pago_aprobado_no_emite_credencial_todavia(): void
    {
        $entrante = $this->reemplazar();

        $this->assertNull($entrante->ticketVigente());
        $this->assertSame(0, Ticket::count());
    }

    public function test_no_se_reemplaza_a_quien_ya_fue_acreditado(): void
    {
        $this->aprobarYEmitir();
        app(AcreditarParticipante::class)($this->saliente->ticketVigente());

        try {
            $this->reemplazar();
            $this->fail('Debio negarse.');
        } catch (ReemplazoNoPermitido $e) {
            $this->assertStringContainsString('ya fue acreditado', $e->getMessage());
        }
    }

    public function test_no_se_reemplaza_dos_veces_al_mismo(): void
    {
        $this->reemplazar();

        $this->expectException(ReemplazoNoPermitido::class);
        $this->reemplazar();
    }

    public function test_respeta_la_fecha_limite_de_reemplazos(): void
    {
        $this->event->update(['replacement_deadline' => now()->subDay()]);

        try {
            $this->reemplazar();
            $this->fail('Debio negarse.');
        } catch (ReemplazoNoPermitido $e) {
            $this->assertStringContainsString('plazo para reemplazar', $e->getMessage());
        }
    }

    public function test_normaliza_el_rut_del_entrante(): void
    {
        $entrante = $this->reemplazar(['rut' => '76.086.428-5']);

        $this->assertSame('76086428-5', $entrante->rut);
    }

    public function test_queda_registrado_en_auditoria(): void
    {
        $this->reemplazar();

        $log = AuditLog::where('action', 'participante.reemplazado')->sole();

        $this->assertSame('Carlos Rojas', $log->properties['sale']);
        $this->assertSame('Luisa Soto', $log->properties['entra']);
        $this->assertSame('El titular no puede asistir.', $log->comment);
    }

    public function test_el_cupo_no_se_altera(): void
    {
        $jornada = $this->event->sessions()->first();
        $antes = $jornada->fresh()->reserved_seats;

        $this->reemplazar();

        $this->assertSame($antes, $jornada->fresh()->reserved_seats);
    }
}
