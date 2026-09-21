<?php

namespace Tests\Feature;

use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\InvoiceDocumentType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentReviewAction;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\ConjuntoConfirmado;
use App\Mail\OrdenConfirmada;
use App\Models\AccessType;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecorridoConVariosColegiosTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private AccessType $acceso;

    private string $token;

    private Order $borrador;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
        $this->acceso = $this->event->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);

        [, $this->token] = MagicLink::emitir($this->event, 'ana@colegio.cl');
        $this->get(route('inscripcion.acceso', ['token' => $this->token]));
        $this->borrador = Order::sole();

        $this->post($this->ruta('responsable.guardar'), [
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez', 'responsible_position' => 'Otro',
            'responsible_position_otro' => 'Directora', 'responsible_phone' => '56912345678',
            'responsible_institution' => 'Sostenedor Los Andes', 'kind' => OrderKind::Institucional->value,
        ]);

        $this->post($this->ruta('pagador.guardar'), [
            'name' => 'Sostenedor Los Andes', 'rut' => '76.086.428-5', 'address' => 'Av. Grecia 1234',
            'commune' => 'Ñuñoa', 'billing_email' => 'pagos@sostenedor.cl', 'phone' => '56912345678',
        ]);
    }

    private function ruta(string $nombre, array $extra = []): string
    {
        return route("inscripcion.{$nombre}", array_merge(['token' => $this->token], $extra));
    }

    /** @return array{0: int, 1: int} [idColegio1, idColegio2] */
    private function agregarDosColegiosConUnParticipanteCadaUno(): array
    {
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio San José', 'address' => 'Calle 1', 'commune' => 'Talca',
        ]);
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio Los Robles', 'address' => 'Calle 2', 'commune' => 'Talca',
        ]);

        [$sanJose, $losRobles] = $this->borrador->fresh()->establishments->pluck('id')->all();

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'position' => 'Inspector General',
            'email' => 'carlos@colegio.cl', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id,
            'establishment_id' => $sanJose,
        ]);

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Marta', 'last_name' => 'Soto', 'position' => 'Director/a',
            'email' => 'marta@colegio.cl', 'rut' => '22.222.222-2', 'access_type_id' => $this->acceso->id,
            'establishment_id' => $losRobles,
        ]);

        return [$sanJose, $losRobles];
    }

    /** @return array{0: int, 1: int, 2: int} [idColegio1, idColegio2, idColegio3] */
    private function agregarTresColegiosConUnParticipanteCadaUno(): array
    {
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio San José', 'address' => 'Calle 1', 'commune' => 'Talca',
        ]);
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio Los Robles', 'address' => 'Calle 2', 'commune' => 'Talca',
        ]);
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio Bellavista', 'address' => 'Calle 3', 'commune' => 'Talca',
        ]);

        [$sanJose, $losRobles, $bellavista] = $this->borrador->fresh()->establishments->pluck('id')->all();

        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'position' => 'Inspector General',
            'email' => 'carlos@colegio.cl', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id,
            'establishment_id' => $sanJose,
        ]);
        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Marta', 'last_name' => 'Soto', 'position' => 'Director/a',
            'email' => 'marta@colegio.cl', 'rut' => '22.222.222-2', 'access_type_id' => $this->acceso->id,
            'establishment_id' => $losRobles,
        ]);
        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Pedro', 'last_name' => 'Díaz', 'position' => 'Docente Enseñanza Básica',
            'email' => 'pedro@colegio.cl', 'rut' => '33.333.333-3', 'access_type_id' => $this->acceso->id,
            'establishment_id' => $bellavista,
        ]);

        return [$sanJose, $losRobles, $bellavista];
    }

    public function test_dos_colegios_generan_dos_ordenes_en_el_mismo_conjunto(): void
    {
        $this->agregarDosColegiosConUnParticipanteCadaUno();

        $this->post($this->ruta('confirmar'))->assertRedirect($this->ruta('estado'));

        $this->assertSame(1, OrderGroup::count());
        $grupo = OrderGroup::sole();

        $ordenes = $grupo->orders()->with('establishments', 'participants')->get();
        $this->assertCount(2, $ordenes);

        foreach ($ordenes as $orden) {
            $this->assertSame(OrderStatus::Reservada, $orden->status);
            $this->assertNotNull($orden->number, 'Cada orden confirmada tiene su propio folio.');
            $this->assertSame(1, $orden->establishments->count());
            $this->assertSame(1, $orden->participants->count());
            $this->assertSame(90000, $orden->total);
        }

        $colegios = $ordenes->flatMap(fn ($o) => $o->establishments->pluck('name'))->sort()->values()->all();
        $this->assertSame(['Colegio San José', 'Colegio los Robles'], $colegios);
    }

    public function test_sin_cupo_en_un_colegio_no_confirma_ninguna_del_conjunto(): void
    {
        $jornada = $this->event->sessions()->create(['name' => 'Única', 'position' => 1, 'capacity' => 1]);
        $this->acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $this->agregarDosColegiosConUnParticipanteCadaUno();

        // El único cupo de la jornada ya está tomado cuando llega la confirmación.
        $jornada->update(['reserved_seats' => 1]);

        $this->post($this->ruta('confirmar'))->assertOk();

        // Ninguna orden del conjunto quedó confirmada: todo o nada. El
        // conjunto puede haberse creado (es solo agrupar), pero sigue con una
        // única orden, en borrador, con sus dos colegios intactos.
        $this->assertSame(OrderStatus::Borrador, $this->borrador->fresh()->status);
        $this->assertNull($this->borrador->fresh()->number);
        $this->assertSame(1, Order::count(), 'No se creó la orden hermana.');
        $this->assertSame(2, $this->borrador->fresh()->establishments()->count(), 'Los dos colegios siguen en el borrador.');
    }

    /**
     * El riesgo propio del conjunto: no es que dos clientes compitan (eso ya
     * lo cubre el UPDATE condicional), es que **un mismo conjunto se coma su
     * último cupo** y deje a su colegio hermano sin asiento a media
     * confirmación. Pasa dentro de una sola transacción, así que no necesita
     * MySQL ni procesos en paralelo para demostrarse.
     */
    public function test_un_conjunto_no_sobrevende_gastando_su_propio_ultimo_cupo(): void
    {
        $jornada = $this->event->sessions()->create(['name' => 'Única', 'position' => 1, 'capacity' => 1]);
        $this->acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        // Dos colegios, un participante cada uno, y una sola vacante libre.
        $this->agregarDosColegiosConUnParticipanteCadaUno();

        $this->post($this->ruta('confirmar'))->assertOk();

        // El primer colegio alcanzó a tomar la vacante y el segundo no: se
        // deshace todo. Si el rollback no devolviera el cupo, la jornada
        // quedaría con un asiento tomado por una orden que no existe.
        $this->assertSame(0, $jornada->fresh()->reserved_seats, 'El cupo del colegio que sí alcanzó volvió a quedar libre.');
        $this->assertSame(OrderStatus::Borrador, $this->borrador->fresh()->status);
        $this->assertSame(1, Order::count());
    }

    public function test_un_colegio_vence_solo_sin_arrastrar_al_hermano_que_ya_pago(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $this->agregarDosColegiosConUnParticipanteCadaUno();
        $this->post($this->ruta('confirmar'));

        $grupo = OrderGroup::sole();
        $pagado = $grupo->orders()->whereHas('establishments', fn ($q) => $q->where('name', 'Colegio San José'))->sole();
        $moroso = $grupo->orders()->whereHas('establishments', fn ($q) => $q->where('name', 'Colegio los Robles'))->sole();

        $pago = app(RegistrarComprobante::class)(
            $pagado, ['amount' => $pagado->total], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')
        );
        $contadora = User::factory()->create(['is_active' => true]);
        $contadora->assignRole(Rol::Contabilidad->value);
        app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contadora->fresh());

        // A los dos se les pasó el plazo, pero uno ya puso la plata.
        Order::query()->whereKey([$pagado->id, $moroso->id])->update(['reserved_until' => now()->subDay()]);

        $this->artisan('reservas:expirar')->assertSuccessful();

        $this->assertSame(OrderStatus::Vencida, $moroso->fresh()->status, 'El que no pagó vence.');
        $this->assertSame(OrderStatus::Reservada, $pagado->fresh()->status, 'El que pagó no se cae con su hermano.');
    }

    public function test_las_credenciales_de_un_colegio_son_solo_las_suyas(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $this->agregarDosColegiosConUnParticipanteCadaUno();
        $this->post($this->ruta('confirmar'));

        $grupo = OrderGroup::sole();
        $contadora = User::factory()->create(['is_active' => true]);
        $contadora->assignRole(Rol::Contabilidad->value);

        foreach ($grupo->orders as $orden) {
            $pago = app(RegistrarComprobante::class)(
                $orden, ['amount' => $orden->total], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')
            );
            app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contadora->fresh());
        }

        // Cada orden emite la credencial de su propio participante, no la del
        // colegio hermano: son inscripciones distintas que comparten enlace.
        foreach ($grupo->orders()->with('participants')->get() as $orden) {
            $tickets = $orden->tickets()->vigentes()->with('participant')->get();

            $this->assertCount(1, $tickets);
            $this->assertSame(
                $orden->participants->sole()->id,
                $tickets->sole()->participant->id,
                'La credencial es del participante de ese colegio.',
            );
        }
    }

    public function test_con_un_solo_colegio_no_se_crea_un_conjunto_visible_pero_la_orden_queda_agrupada(): void
    {
        $this->post($this->ruta('establecimientos.agregar'), [
            'name' => 'Colegio San José', 'address' => 'Calle 1', 'commune' => 'Talca',
        ]);
        $this->post($this->ruta('participantes.agregar'), [
            'first_name' => 'Carlos', 'last_name' => 'Rojas', 'position' => 'Inspector General',
            'email' => 'carlos@colegio.cl', 'rut' => '11.111.111-1', 'access_type_id' => $this->acceso->id,
        ]);

        $this->post($this->ruta('confirmar'))->assertRedirect($this->ruta('estado'));

        $orden = $this->borrador->fresh();
        $this->assertSame(OrderStatus::Reservada, $orden->status);
        $this->assertNotNull($orden->group_id);
        $this->assertSame(1, $orden->group->orders()->count());
    }

    public function test_con_un_colegio_pagado_y_otro_pendiente_la_pantalla_muestra_credenciales_y_boton_de_comprobante(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $this->agregarDosColegiosConUnParticipanteCadaUno();
        $this->post($this->ruta('confirmar'));

        $grupo = OrderGroup::sole();
        $sanJose = $grupo->orders()->whereHas('establishments', fn ($q) => $q->where('name', 'Colegio San José'))->sole();
        $losRobles = $grupo->orders()->whereHas('establishments', fn ($q) => $q->where('name', 'Colegio los Robles'))->sole();

        // San José ya pagó y tiene su credencial; Los Robles sigue debiendo.
        $pago = app(RegistrarComprobante::class)(
            $sanJose, ['amount' => $sanJose->total], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')
        );
        $contadora = User::factory()->create(['is_active' => true]);
        $contadora->assignRole(Rol::Contabilidad->value);
        app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contadora->fresh());

        // Se ve un colegio a la vez: el del token, con su credencial lista. El
        // menú lateral nombra a los dos y dice cuál sigue debiendo.
        $respuesta = $this->get($this->ruta('estado'))->assertOk();

        $respuesta->assertSee('Colegio San José')
            ->assertSee('Colegio los Robles')
            ->assertSee('Ver las credenciales')
            ->assertSee('Debe $90.000')
            ->assertDontSee(route('inscripcion.comprobante', ['token' => $this->token, 'establishment' => $losRobles->establishments->sole()->id]), escape: false);

        // Al elegir el otro colegio en el menú, aparece su formulario de pago.
        $this->get($this->ruta('estado', ['colegio' => $losRobles->establishments->sole()->id]))
            ->assertOk()
            ->assertSee(route('inscripcion.comprobante', ['token' => $this->token, 'establishment' => $losRobles->establishments->sole()->id]), escape: false)
            ->assertDontSee('Ver las credenciales');
    }

    public function test_el_menu_no_muestra_colegios_de_otro_conjunto(): void
    {
        $this->agregarDosColegiosConUnParticipanteCadaUno();
        $this->post($this->ruta('confirmar'));

        // Un colegio ajeno en la URL no abre nada: se vuelve al del token.
        $ajeno = Establishment::factory()->create(['name' => 'Colegio Ajeno']);

        $this->get($this->ruta('estado', ['colegio' => $ajeno->id]))
            ->assertOk()
            ->assertSee('Colegio San José')
            ->assertDontSee('Colegio Ajeno');
    }

    public function test_confirmar_un_conjunto_de_dos_colegios_manda_un_solo_correo(): void
    {
        $this->agregarDosColegiosConUnParticipanteCadaUno();

        $this->post($this->ruta('confirmar'));

        Mail::assertQueued(ConjuntoConfirmado::class, function (ConjuntoConfirmado $correo): bool {
            $html = $correo->render();

            return $correo->ordenes->count() === 2
                && str_contains($html, 'Colegio San José')
                && str_contains($html, 'Colegio los Robles')
                && str_contains($html, '90.000')
                && (bool) preg_match('#/i/o/[A-Za-z0-9]{20,}/estado#', $html);
        });

        Mail::assertNotQueued(OrdenConfirmada::class);
    }

    public function test_tres_colegios_suben_comprobantes_distintos_y_cada_uno_saca_su_propia_factura(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        Storage::fake(config('filesystems.private_disk').'_facturas');
        $this->seed(RolesSeeder::class);

        $this->agregarTresColegiosConUnParticipanteCadaUno();
        $this->post($this->ruta('confirmar'))->assertRedirect($this->ruta('estado'));

        $grupo = OrderGroup::sole();
        $sanJose = $grupo->orders()->whereHas('establishments', fn ($q) => $q->where('name', 'Colegio San José'))->sole();
        $losRobles = $grupo->orders()->whereHas('establishments', fn ($q) => $q->where('name', 'Colegio los Robles'))->sole();
        $bellavista = $grupo->orders()->whereHas('establishments', fn ($q) => $q->where('name', 'Colegio Bellavista'))->sole();

        $contadora = User::factory()->create(['is_active' => true]);
        $contadora->assignRole(Rol::Contabilidad->value);

        // San José paga todo de una, Los Robles abona en dos partes, Bellavista sigue debiendo.
        $pagoSanJose = app(RegistrarComprobante::class)(
            $sanJose, ['amount' => $sanJose->total], UploadedFile::fake()->create('san-jose.pdf', 50, 'application/pdf')
        );
        app(RevisarPago::class)($pagoSanJose, PaymentReviewAction::Aprobar, $contadora->fresh());

        $abonoParcial = intdiv($losRobles->total, 2);
        $primerAbono = app(RegistrarComprobante::class)(
            $losRobles, ['amount' => $abonoParcial], UploadedFile::fake()->create('los-robles-1.pdf', 50, 'application/pdf')
        );
        app(RevisarPago::class)($primerAbono, PaymentReviewAction::Aprobar, $contadora->fresh());
        $segundoAbono = app(RegistrarComprobante::class)(
            $losRobles->fresh(), ['amount' => $losRobles->fresh()->saldo()], UploadedFile::fake()->create('los-robles-2.pdf', 50, 'application/pdf')
        );
        app(RevisarPago::class)($segundoAbono, PaymentReviewAction::Aprobar, $contadora->fresh());

        $sanJose->refresh();
        $losRobles->refresh();
        $bellavista->refresh();

        $this->assertSame(PaymentStatus::Aprobado, $sanJose->payment_status);
        $this->assertSame(PaymentStatus::Aprobado, $losRobles->payment_status);
        $this->assertSame(PaymentStatus::Pendiente, $bellavista->payment_status, 'Bellavista no subió comprobante: sigue debiendo.');
        $this->assertSame(0, $sanJose->saldo());
        $this->assertSame(0, $losRobles->saldo());
        $this->assertSame($bellavista->total, $bellavista->saldo());

        // Contabilidad registra una factura por cada orden pagada, con su propio monto y folio.
        $this->actingAs($contadora);
        $this->post(route('inscripciones.factura', $sanJose), [
            'document_type' => InvoiceDocumentType::Factura->value, 'number' => '1001',
            'issued_on' => now()->toDateString(), 'amount' => $sanJose->total, 'enviar' => false,
        ])->assertRedirect();
        $this->post(route('inscripciones.factura', $losRobles), [
            'document_type' => InvoiceDocumentType::Factura->value, 'number' => '1002',
            'issued_on' => now()->toDateString(), 'amount' => $losRobles->total, 'enviar' => false,
        ])->assertRedirect();

        $sanJose->refresh();
        $losRobles->refresh();

        $this->assertSame(1, $sanJose->invoiceRecords()->count());
        $this->assertSame(1, $losRobles->invoiceRecords()->count());
        $this->assertSame(0, $bellavista->invoiceRecords()->count(), 'Bellavista no pagó: no debe tener factura.');
        $this->assertSame('1001', $sanJose->invoiceRecords()->sole()->number);
        $this->assertSame('1002', $losRobles->invoiceRecords()->sole()->number);
        $this->assertSame($sanJose->total, $sanJose->invoiceRecords()->sole()->amount);
        $this->assertSame($losRobles->total, $losRobles->invoiceRecords()->sole()->amount);

        // Registrar la factura de un colegio no toca el pago ni la factura de los otros dos.
        $this->assertSame(0, $bellavista->payments()->count());
        $this->assertSame(2, $losRobles->payments()->count(), 'Los Robles pagó en dos abonos.');
        $this->assertSame(1, $sanJose->payments()->count());
    }
}
