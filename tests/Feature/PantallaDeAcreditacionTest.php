<?php

namespace Tests\Feature;

use App\Actions\AcreditarParticipante;
use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Models\Accreditation;
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

class PantallaDeAcreditacionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesSeeder::class);

        $this->event = Event::factory()->conJornadasYAccesos(50)->create(['name' => 'Seminario Demo']);

        $acceso = $this->event->accessTypes()->where('name', 'Ambas jornadas')->sole();
        $orden = Order::factory()->create(['event_id' => $this->event->id]);
        $establecimiento = Establishment::factory()->create(['name' => 'Colegio San José']);
        $orden->establishments()->attach($establecimiento);

        Participant::factory()->create([
            'order_id' => $orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $acceso->id,
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'email' => null,
        ]);

        $orden = app(ConfirmarOrden::class)($orden->fresh());
        $orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($orden->fresh());

        $this->ticket = $orden->fresh()->tickets()->vigentes()->sole();
    }

    private function entrarComo(Rol $rol): User
    {
        $user = User::factory()->create(['is_active' => true, 'name' => 'Operador '.$rol->value]);
        $user->assignRole($rol->value);
        $this->actingAs($user = $user->fresh());

        return $user;
    }

    public function test_acreditacion_entra_al_modulo(): void
    {
        $this->entrarComo(Rol::Acreditacion);

        $this->get(route('acreditacion.inicio'))
            ->assertOk()
            ->assertSee('Escanear credencial')
            ->assertSee('Ingresar el código a mano')
            ->assertSee('Buscar participante');
    }

    public function test_contabilidad_no_entra_al_modulo(): void
    {
        $this->entrarComo(Rol::Contabilidad);

        $this->get(route('acreditacion.inicio'))->assertForbidden();
    }

    public function test_un_invitado_es_redirigido(): void
    {
        $this->get(route('acreditacion.inicio'))->assertRedirect();
    }

    public function test_resolver_muestra_al_participante_y_la_pulsera(): void
    {
        $this->entrarComo(Rol::Acreditacion);

        $this->post(route('acreditacion.resolver'), [
            'event_id' => $this->event->id,
            'credencial' => $this->ticket->code,
        ])
            ->assertOk()
            ->assertSee('Credencial válida')
            ->assertSee('Carlos Rojas')
            ->assertSee('Colegio San José')
            ->assertSee('Ambas jornadas')
            ->assertSee('Acceso completo')
            ->assertSee('Confirmar acreditación');
    }

    public function test_confirmar_registra_la_acreditacion(): void
    {
        $this->entrarComo(Rol::Acreditacion);

        $this->post(route('acreditacion.confirmar'), [
            'ticket_id' => $this->ticket->id,
            'event_id' => $this->event->id,
            'metodo' => 'qr',
        ])
            ->assertOk()
            ->assertSee('Acreditado')
            ->assertSee('Pulsera entregada');

        $this->assertSame(1, Accreditation::count());
    }

    public function test_un_reescaneo_avisa_con_la_fecha_y_la_pulsera(): void
    {
        $operador = $this->entrarComo(Rol::Acreditacion);
        app(AcreditarParticipante::class)($this->ticket, $operador);

        $this->post(route('acreditacion.resolver'), [
            'event_id' => $this->event->id,
            'credencial' => $this->ticket->code,
        ])
            ->assertOk()
            ->assertSee('Participante ya acreditado')
            ->assertSee('Ya recibió la pulsera')
            ->assertSee($operador->name)
            ->assertDontSee('Confirmar acreditación');
    }

    public function test_una_credencial_de_otro_evento_no_se_acredita_aqui(): void
    {
        $this->entrarComo(Rol::Acreditacion);

        $otro = Event::factory()->conJornadasYAccesos(10)->create(['name' => 'Otro seminario']);

        $this->post(route('acreditacion.resolver'), [
            'event_id' => $otro->id,
            'credencial' => $this->ticket->code,
        ])
            ->assertOk()
            // Decir cual es el evento, y no tratarla como inexistente: si no,
            // el operador cree que el codigo esta malo y reintenta en vano.
            ->assertSee('Credencial de otro evento')
            ->assertSee('Seminario Demo')
            ->assertDontSee('Confirmar acreditación');
    }

    public function test_la_busqueda_manual_lista_coincidencias(): void
    {
        $this->entrarComo(Rol::Acreditacion);

        $this->get(route('acreditacion.buscar', ['event_id' => $this->event->id, 'q' => 'Rojas']))
            ->assertOk()
            ->assertSee('Carlos Rojas')
            ->assertSee('Colegio San José');
    }

    public function test_la_busqueda_sin_resultados_sugiere_que_hacer(): void
    {
        $this->entrarComo(Rol::Acreditacion);

        $this->get(route('acreditacion.buscar', ['event_id' => $this->event->id, 'q' => 'Inexistente']))
            ->assertOk()
            ->assertSee('Sin resultados')
            ->assertSee('solo con el apellido');
    }

    public function test_la_busqueda_marca_a_quien_ya_fue_acreditado(): void
    {
        $operador = $this->entrarComo(Rol::Acreditacion);
        app(AcreditarParticipante::class)($this->ticket, $operador);

        $this->get(route('acreditacion.buscar', ['event_id' => $this->event->id, 'q' => 'Rojas']))
            ->assertOk()
            ->assertSee('Ya acreditado');
    }

    public function test_no_se_puede_confirmar_una_credencial_anulada(): void
    {
        $this->entrarComo(Rol::Acreditacion);
        $this->ticket->revocar('Reemplazo');

        $this->post(route('acreditacion.confirmar'), [
            'ticket_id' => $this->ticket->id,
            'event_id' => $this->event->id,
        ])->assertStatus(422);

        $this->assertSame(0, Accreditation::count());
    }

    public function test_no_se_puede_confirmar_si_el_pago_no_esta_aprobado(): void
    {
        $this->entrarComo(Rol::Acreditacion);
        $this->ticket->order->forceFill(['payment_status' => PaymentStatus::Rechazado])->save();

        $this->post(route('acreditacion.confirmar'), [
            'ticket_id' => $this->ticket->id,
            'event_id' => $this->event->id,
        ])->assertStatus(422);

        $this->assertSame(0, Accreditation::count());
    }
}
