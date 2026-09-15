<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FlujoDeInscripcionTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private AccessType $jornada1;

    private AccessType $ambas;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);

        $j1 = $this->event->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 100]);
        $j2 = $this->event->sessions()->create(['name' => 'Jornada 2', 'position' => 2, 'capacity' => 100]);

        $this->jornada1 = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $this->ambas = $this->event->accessTypes()->create(['name' => 'Ambas jornadas', 'position' => 2, 'price' => 150000]);

        $this->jornada1->sessions()->sync([$j1->id => ['seats' => 1]]);
        $this->ambas->sessions()->sync([$j1->id => ['seats' => 1], $j2->id => ['seats' => 1]]);
    }

    /** Entra con un enlace y devuelve el token. */
    private function entrar(string $email = 'ana@colegio.cl'): string
    {
        $this->post("/i/{$this->event->slug}", ['email' => $email])->assertOk();

        [, $token] = MagicLink::emitir($this->event, $email, MagicLink::latest('id')->first()?->order);

        return $token;
    }

    public function test_la_pantalla_de_inicio_pide_solo_el_correo(): void
    {
        $this->get("/i/{$this->event->slug}")
            ->assertOk()
            ->assertSee('Ingresa tu correo')
            ->assertSee('Te enviaremos un enlace seguro')
            ->assertSee('name="email"', escape: false)
            ->assertDontSee('name="password"', escape: false);
    }

    public function test_no_revela_si_un_correo_ya_tiene_inscripcion(): void
    {
        // Misma respuesta exista o no la inscripción: de lo contrario el
        // formulario serviría para averiguar quién está inscrito.
        $sinOrden = $this->post("/i/{$this->event->slug}", ['email' => 'nueva@colegio.cl']);

        Order::create([
            'event_id' => $this->event->id,
            'responsible_email' => 'ana@colegio.cl',
            'responsible_name' => 'Ana',
        ]);

        $conOrden = $this->post("/i/{$this->event->slug}", ['email' => 'ana@colegio.cl']);

        $sinOrden->assertOk()->assertSee('Revisa tu correo');
        $conOrden->assertOk()->assertSee('Revisa tu correo');
    }

    public function test_el_enlace_crea_el_borrador_y_lleva_al_primer_paso(): void
    {
        [, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');

        $this->get(route('inscripcion.acceso', ['token' => $token]))
            ->assertRedirect(route('inscripcion.responsable', ['token' => $token]));

        $orden = Order::sole();

        $this->assertSame(OrderStatus::Borrador, $orden->status);
        $this->assertSame('ana@colegio.cl', $orden->responsible_email);
        $this->assertNull($orden->number, 'El folio solo se asigna al confirmar.');
    }

    public function test_un_token_invalido_no_da_acceso(): void
    {
        // Se niega el acceso, pero explicando: un 403 crudo deja al cliente sin
        // saber que basta con pedir otro enlace.
        $this->get(route('inscripcion.acceso', ['token' => 'inventado']))
            ->assertForbidden()
            ->assertSee('Este enlace ya no es válido');

        $this->get(route('inscripcion.responsable', ['token' => 'inventado']))
            ->assertForbidden()
            ->assertSee('Este enlace ya no es válido');
    }

    public function test_un_enlace_no_da_acceso_a_la_orden_de_otro(): void
    {
        [, $tokenAna] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $tokenAna]));
        $ordenDeAna = Order::sole();

        [, $tokenBeto] = MagicLink::emitir($this->event, 'beto@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $tokenBeto]));

        // El token de Beto abre su propia orden, nunca la de Ana.
        $this->get(route('inscripcion.responsable', ['token' => $tokenBeto]))
            ->assertOk()
            ->assertDontSee('ana@colegio.cl');

        $this->assertSame(2, Order::count());
        $this->assertNotSame($ordenDeAna->id, MagicLink::where('email', 'beto@colegio.cl')->sole()->order_id);
    }

    public function test_flujo_completo_hasta_el_resumen(): void
    {
        [, $token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $token]));

        // 1. Responsable
        $this->post(route('inscripcion.responsable.guardar', ['token' => $token]), [
            'responsible_name' => 'Ana Pérez',
            'responsible_position' => 'Otro',
            'responsible_position_otro' => 'Directora',
            'responsible_phone' => '+56912345678',
            'kind' => OrderKind::Institucional->value,
        ])->assertRedirect(route('inscripcion.pagador', ['token' => $token]));

        // 2. Entidad pagadora
        $this->post(route('inscripcion.pagador.guardar', ['token' => $token]), [
            'name' => 'Fundación Educar',
            'rut' => '76.086.428-5',
            'billing_email' => 'pagos@fundacion.cl',
        ])->assertRedirect(route('inscripcion.establecimientos', ['token' => $token]));

        // 3. Establecimiento
        $this->post(route('inscripcion.establecimientos.agregar', ['token' => $token]), [
            'name' => 'Colegio San José', 'rbd' => '12345',
        ])->assertRedirect(route('inscripcion.establecimientos', ['token' => $token]));

        $orden = Order::sole();
        $establecimiento = $orden->establishments()->sole();

        // 4. Participantes
        $this->post(route('inscripcion.participantes.agregar', ['token' => $token]), [
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'position' => 'Docente', 'email' => 'carlos@colegio.cl',
            'access_type_id' => $this->ambas->id,
            'establishment_id' => $establecimiento->id,
        ])->assertRedirect(route('inscripcion.participantes', ['token' => $token]));

        $this->post(route('inscripcion.participantes.agregar', ['token' => $token]), [
            'first_name' => 'Luisa', 'last_name' => 'Soto', 'position' => 'Directivo', 'email' => 'luisa@colegio.cl',
            'access_type_id' => $this->jornada1->id,
            'establishment_id' => $establecimiento->id,
        ])->assertRedirect(route('inscripcion.participantes', ['token' => $token]));

        $orden->refresh()->load('participants.accessType');

        $this->assertSame('Ana Pérez', $orden->responsible_name);
        $this->assertSame('76086428-5', $orden->payerEntity->rut, 'El RUT se normaliza al guardar.');
        $this->assertSame(2, $orden->participants->count());
        $this->assertSame(240000, $orden->calcularTotal());

        // Ambas jornadas consume cupo en las dos; Jornada 1 solo en la primera.
        $this->assertSame([1 => 2, 2 => 1], $orden->consumoDeCupos());

        // 5. Resumen
        $this->get(route('inscripcion.resumen', ['token' => $token]))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('Fundación Educar')
            ->assertSee('Colegio San José')
            ->assertSee('Carlos Rojas')
            ->assertSee('240.000');
    }
}
