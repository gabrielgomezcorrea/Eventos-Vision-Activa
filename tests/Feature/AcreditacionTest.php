<?php

namespace Tests\Feature;

use App\Actions\AcreditarParticipante;
use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Actions\ResolverCredencial;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResultadoAcreditacion;
use App\Enums\Rol;
use App\Models\Accreditation;
use App\Models\AuditLog;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Acreditacion\Resultado;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AcreditacionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Ticket $ticket;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesSeeder::class);

        $this->event = Event::factory()->conJornadasYAccesos(50)->create();

        $this->ticket = $this->ticketPara('Carlos', 'Rojas');

        $this->operador = User::factory()->create(['name' => 'Pablo Acreditación', 'is_active' => true]);
        $this->operador->assignRole(Rol::Acreditacion->value);
        $this->operador = $this->operador->fresh();
    }

    private function ticketPara(string $nombre, string $apellido, ?string $rut = null): Ticket
    {
        $acceso = $this->event->accessTypes()->where('name', 'Ambas jornadas')->sole();

        $orden = Order::factory()->create(['event_id' => $this->event->id]);
        $establecimiento = Establishment::factory()->create(['name' => 'Colegio San José']);
        $orden->establishments()->attach($establecimiento);

        Participant::factory()->create([
            'order_id' => $orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $acceso->id,
            'first_name' => $nombre,
            'last_name' => $apellido,
            'rut' => $rut,
            'email' => null,
        ]);

        $orden = app(ConfirmarOrden::class)($orden->fresh());
        $orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();

        app(EmitirTickets::class)($orden->fresh());

        return $orden->fresh()->tickets()->vigentes()->sole();
    }

    private function resolver(string $entrada): Resultado
    {
        return app(ResolverCredencial::class)($entrada);
    }

    // ------------------------------------------------------------- resolución

    public function test_resuelve_por_el_token_del_qr(): void
    {
        $resultado = $this->resolver($this->ticket->token);

        $this->assertSame(ResultadoAcreditacion::Valida, $resultado->tipo);
        $this->assertTrue($this->ticket->is($resultado->ticket));
    }

    public function test_resuelve_por_la_url_completa_que_trae_el_qr(): void
    {
        // Muchos lectores entregan la URL entera, no solo el token.
        $resultado = $this->resolver($this->ticket->url());

        $this->assertSame(ResultadoAcreditacion::Valida, $resultado->tipo);
    }

    public function test_resuelve_por_el_codigo_de_respaldo_escrito_de_cualquier_forma(): void
    {
        $codigo = $this->ticket->code;

        foreach ([$codigo, strtolower($codigo), str_replace('-', '', $codigo), ' '.$codigo.' '] as $variante) {
            $this->assertSame(
                ResultadoAcreditacion::Valida,
                $this->resolver($variante)->tipo,
                "Deberia resolver '{$variante}'",
            );
        }
    }

    public function test_un_codigo_inexistente_pide_buscar_a_mano(): void
    {
        $resultado = $this->resolver('ZZZZ-9999');

        $this->assertSame(ResultadoAcreditacion::NoEncontrada, $resultado->tipo);
        $this->assertStringContainsString('busca al participante', $resultado->tipo->mensaje());
    }

    public function test_un_reescaneo_dice_que_ya_fue_acreditado_y_no_qr_invalido(): void
    {
        app(AcreditarParticipante::class)($this->ticket, $this->operador);

        $resultado = $this->resolver($this->ticket->token);

        $this->assertSame(ResultadoAcreditacion::YaAcreditado, $resultado->tipo);
        $this->assertNotNull($resultado->acreditacion);

        // Nunca debe leerse como un error de lectura del código.
        $this->assertStringNotContainsStringIgnoringCase('inválido', $resultado->tipo->titulo());
        $this->assertStringNotContainsStringIgnoringCase('inválido', $resultado->tipo->mensaje());
        $this->assertSame('aviso', $resultado->tipo->color());
    }

    public function test_una_credencial_anulada_se_distingue_de_una_inexistente(): void
    {
        $this->ticket->revocar('Reemplazo de participante');

        $resultado = $this->resolver($this->ticket->token);

        $this->assertSame(ResultadoAcreditacion::Anulada, $resultado->tipo);
        $this->assertFalse($resultado->permiteAcreditar());
    }

    public function test_si_el_pago_dejo_de_estar_aprobado_no_se_acredita(): void
    {
        $this->ticket->order->forceFill(['payment_status' => PaymentStatus::Rechazado])->save();

        $resultado = $this->resolver($this->ticket->fresh()->token);

        $this->assertSame(ResultadoAcreditacion::PagoNoAprobado, $resultado->tipo);
        $this->assertStringContainsString('Contabilidad', $resultado->tipo->mensaje());
    }

    // ------------------------------------------------------------- registro

    public function test_acreditar_registra_la_pulsera_entregada(): void
    {
        $acreditacion = app(AcreditarParticipante::class)($this->ticket, $this->operador);

        $this->assertSame('Acceso completo', $acreditacion->wristband_label);
        $this->assertSame('#F58A07', $acreditacion->wristband_color);
        $this->assertSame($this->operador->id, $acreditacion->user_id);
        $this->assertSame('Pablo Acreditación', $acreditacion->user_label);
        $this->assertSame(ParticipantStatus::Acreditado, $this->ticket->participant->fresh()->status);
    }

    public function test_el_color_de_la_pulsera_queda_congelado(): void
    {
        app(AcreditarParticipante::class)($this->ticket, $this->operador);

        // Cambiar el acceso después no debe alterar lo que consta que se entregó.
        $this->ticket->participant->accessType->update([
            'wristband_label' => 'Otro nombre', 'wristband_color' => '#000000',
        ]);

        $this->assertSame('Acceso completo', Accreditation::sole()->wristband_label);
    }

    public function test_acreditar_dos_veces_no_duplica_el_registro(): void
    {
        $primera = app(AcreditarParticipante::class)($this->ticket, $this->operador);
        $segunda = app(AcreditarParticipante::class)($this->ticket->fresh(), $this->operador);

        $this->assertSame(1, Accreditation::count());
        $this->assertTrue($primera->is($segunda));
    }

    public function test_dos_operadores_en_paralelo_no_generan_dos_acreditaciones(): void
    {
        // El indice unico de la base es la garantia; aqui se comprueba que la
        // colision se resuelve devolviendo el registro existente y no un error,
        // porque un error en la puerta detiene la fila.
        $otro = User::factory()->create(['name' => 'Otra operadora']);
        $otro->assignRole(Rol::Acreditacion->value);

        $a = app(AcreditarParticipante::class)($this->ticket, $this->operador);
        $b = app(AcreditarParticipante::class)($this->ticket->fresh(), $otro->fresh());

        $this->assertSame(1, Accreditation::count());
        $this->assertTrue($a->is($b));
        $this->assertSame($this->operador->id, Accreditation::sole()->user_id);
    }

    public function test_queda_registrado_en_auditoria(): void
    {
        app(AcreditarParticipante::class)($this->ticket, $this->operador, 'manual');

        $log = AuditLog::where('action', 'participante.acreditado')->sole();

        $this->assertSame('Carlos Rojas', $log->properties['participante']);
        $this->assertSame('manual', $log->properties['metodo']);
    }

    public function test_el_indice_unico_impide_dos_acreditaciones_del_mismo_ticket(): void
    {
        Accreditation::create([
            'ticket_id' => $this->ticket->id,
            'event_id' => $this->event->id,
            'participant_id' => $this->ticket->participant_id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Accreditation::create([
            'ticket_id' => $this->ticket->id,
            'event_id' => $this->event->id,
            'participant_id' => $this->ticket->participant_id,
        ]);
    }
}
