<?php

namespace Tests\Feature;

use App\Actions\AcreditarParticipante;
use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Ticket;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Reporte del día del evento: esperados, llegaron y faltan, sin montos, y
 * quién acreditó a cada uno solo para quien puede ver la auditoría.
 */
class ReporteDeAcreditacionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    /** @var array<string, Ticket> */
    private array $tickets = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesSeeder::class);

        $this->event = Event::factory()->conJornadasYAccesos(50)->create(['status' => EventStatus::Publicado, 'starts_on' => now()->addDays(10)]);

        // 5 personas, 2 jornadas: p5 fue reemplazada, p4 es invitada, p1 y p2 llegaron.
        $this->persona('p1', 'Jornada 1', 'Ana', 'Alvarez', 'Colegio Norte');
        $this->persona('p2', 'Ambas jornadas', 'Beto', 'Bravo', 'Colegio Norte');
        $this->persona('p3', 'Jornada 2', 'Carla', 'Castro', 'Colegio Sur');
        $this->persona('p4', 'Jornada 1', 'Dario', 'Diaz', 'Colegio Sur', OrderKind::Invitado);
        $this->persona('p5', 'Jornada 1', 'Eva', 'Espinoza', 'Colegio Este');
        Participant::where('first_name', 'Eva')->update(['status' => ParticipantStatus::Reemplazado]);

        $acreditador = $this->usuarioConRol(Rol::Acreditacion, ['name' => 'Pablo Puerta']);
        app(AcreditarParticipante::class)($this->tickets['p1'], $acreditador, 'qr');
        app(AcreditarParticipante::class)($this->tickets['p2'], $acreditador, 'manual');
    }

    private function persona(string $clave, string $acceso, string $nombre, string $apellido, string $colegio, OrderKind $tipo = OrderKind::Institucional): void
    {
        $orden = Order::factory()->create(['event_id' => $this->event->id]);
        $establecimiento = Establishment::factory()->create(['name' => $colegio]);
        $orden->establishments()->attach($establecimiento);

        Participant::factory()->create([
            'order_id' => $orden->id, 'establishment_id' => $establecimiento->id,
            'access_type_id' => $this->event->accessTypes()->where('name', $acceso)->value('id'),
            'first_name' => $nombre, 'last_name' => $apellido, 'rut' => null, 'email' => null,
        ]);

        $orden = app(ConfirmarOrden::class)($orden->fresh());
        $orden->forceFill(['payment_status' => PaymentStatus::Aprobado, 'kind' => $tipo])->save();
        app(EmitirTickets::class)($orden->fresh());

        $this->tickets[$clave] = $orden->fresh()->tickets()->vigentes()->first() ?? $orden->fresh()->tickets()->first();
    }

    public function test_los_numeros_por_total_por_jornada_y_por_colegio(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('acreditaciones.index', ['evento' => $this->event->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('acreditaciones/index')
                ->where('reporte.totales.esperados', 4)
                ->where('reporte.totales.llegaron', 2)
                ->where('reporte.totales.faltan', 2)
                ->where('reporte.totales.invitados', 1)
                ->where('reporte.totales.invitados_llegaron', 0)
                // Jornada 1: p1, p2 (ambas) y p4. Jornada 2: p2 (ambas) y p3.
                ->where('reporte.jornadas.0', fn ($j) => $j['esperados'] === 3 && $j['llegaron'] === 2 && $j['faltan'] === 1)
                ->where('reporte.jornadas.1', fn ($j) => $j['esperados'] === 2 && $j['llegaron'] === 1 && $j['faltan'] === 1)
                // La reemplazada no cuenta: su colegio no aparece.
                ->has('reporte.colegios', 4)
                ->has('reporte.llegadas'));
    }

    public function test_acreditacion_entra_y_contabilidad_recibe_403(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Acreditacion))->get(route('acreditaciones.index'))->assertOk();
        $this->actingAs($this->usuarioConRol(Rol::Contabilidad))->get(route('acreditaciones.index'))->assertForbidden();
    }

    public function test_sin_parametro_abre_el_evento_publicado_mas_proximo(): void
    {
        $lejano = Event::factory()->create(['status' => EventStatus::Publicado, 'starts_on' => now()->addDays(60)]);
        $this->event->update(['starts_on' => now()->addDays(20)]);
        $cercano = Event::factory()->create(['status' => EventStatus::Publicado, 'starts_on' => now()->addDays(5)]);

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('acreditaciones.index'))
            ->assertInertia(fn (Assert $p) => $p->where('evento', $cercano->id));

        $this->assertNotSame($lejano->id, $cercano->id);
    }

    public function test_quien_acredito_solo_viaja_para_quien_ve_la_auditoria(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Acreditacion))
            ->get(route('acreditaciones.index', ['evento' => $this->event->id]))
            ->assertInertia(fn (Assert $p) => $p
                ->where('reporte.ver_acreditador', false)
                ->missing('reporte.personas.data.0.acreditado_por'));

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('acreditaciones.index', ['evento' => $this->event->id, 'estado' => 'llegaron']))
            ->assertInertia(fn (Assert $p) => $p
                ->where('reporte.ver_acreditador', true)
                ->where('reporte.personas.data.0.acreditado_por', 'Pablo Puerta')
                ->where('reporte.personas.data.0.metodo', 'QR'));
    }

    public function test_el_filtro_faltan_excluye_a_los_acreditados_y_la_busqueda_encuentra_por_nombre(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));

        $nombres = fn (array $q) => collect($this->get(route('acreditaciones.index', ['evento' => $this->event->id] + $q))
            ->viewData('page')['props']['reporte']['personas']['data'])->pluck('nombre')->sort()->values()->all();

        $this->assertSame(['Carla Castro', 'Dario Diaz'], $nombres(['estado' => 'faltan']));
        $this->assertSame(['Ana Alvarez', 'Beto Bravo'], $nombres(['estado' => 'llegaron']));
        $this->assertSame(['Carla Castro'], $nombres(['buscar' => 'carla cas']));
        $this->assertCount(4, $nombres([]));
    }

    public function test_el_reporte_no_incluye_montos(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Coordinacion));

        $json = (string) json_encode($this->get(route('acreditaciones.index', ['evento' => $this->event->id]))->viewData('page')['props']['reporte']);

        foreach (['price', 'amount', 'monto', 'saldo', '"total":'.$this->orden_total()] as $palabra) {
            $this->assertStringNotContainsString($palabra, $json);
        }
    }

    private function orden_total(): int
    {
        return (int) Order::first()->total;
    }
}
