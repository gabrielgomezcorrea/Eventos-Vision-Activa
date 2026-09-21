<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Enums\EventStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\CredencialParticipante;
use App\Mail\FacturaEmitida;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Participant;
use App\Models\PayerEntity;
use App\Models\User;
use App\Support\Rut;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lo que el equipo hace sobre una inscripción: quién puede y que no se pierda
 * ni se cobre de más.
 */
class PanelInscripcionesTest extends TestCase
{
    use RefreshDatabase;

    private Order $orden;

    private Participant $participante;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
        $jornada = $evento->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => 50]);
        $acceso = $evento->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $evento->id,
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación Educar'])->id,
        ]);
        $this->participante = $orden->participants()->create(['first_name' => 'Carlos', 'access_type_id' => $acceso->id]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());
    }

    private function usuario(Rol $rol): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol->value);

        return $usuario->fresh();
    }

    private function comprobante(): array
    {
        return [
            'amount' => 90000,
            'paid_on' => now()->toDateString(),
            'bank_name' => 'BancoEstado',
            'payer_name' => 'Fundación Educar',
            'payer_rut' => '76.086.428-5',
            'proof' => UploadedFile::fake()->create('transferencia.pdf', 100, 'application/pdf'),
        ];
    }

    public function test_las_pantallas_de_inscripciones_cargan(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->get(route('inscripciones.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('inscripciones/index')->has('ordenes.data', 1));

        $this->get(route('inscripciones.show', $this->orden))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inscripciones/show')
                ->has('participantes', 1)
                ->where('puede.cargarComprobante', true)
                ->where('puede.registrarFactura', false));
    }

    public function test_solo_administracion_ve_las_credenciales_completas(): void
    {
        $this->aprobarElPago();
        app(EmitirTickets::class)($this->orden->fresh());

        foreach ([Rol::Coordinacion, Rol::Contabilidad] as $rol) {
            $this->actingAs($this->usuario($rol));

            $this->get(route('inscripciones.credenciales', $this->orden))->assertForbidden();
            $this->get(route('inscripciones.show', $this->orden))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('credenciales', 1)
                    ->where('credenciales.0.url', null)
                    ->where('credenciales.0.codigo', null)
                    ->where('puede.verCredenciales', false));
        }

        $this->actingAs($this->usuario(Rol::Administrador));

        $this->get(route('inscripciones.credenciales', $this->orden))->assertOk();
        $this->get(route('inscripciones.show', $this->orden))
            ->assertInertia(fn (Assert $page) => $page
                ->whereNot('credenciales.0.url', null)
                ->where('puede.verCredenciales', true));
    }

    public function test_la_ficha_de_un_colegio_muestra_a_sus_hermanas_del_conjunto(): void
    {
        $grupo = OrderGroup::create([
            'event_id' => $this->orden->event_id,
            'responsible_email' => $this->orden->responsible_email,
        ]);
        $this->orden->forceFill(['group_id' => $grupo->id])->save();
        $this->orden->establishments()->attach(Establishment::factory()->create(['name' => 'Colegio San José']));

        $hermana = Order::create([
            'event_id' => $this->orden->event_id,
            'group_id' => $grupo->id,
            'responsible_name' => 'Ana', 'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana@colegio.cl', 'status' => OrderStatus::Reservada,
            'total' => 50000,
        ]);
        $hermana->establishments()->attach(Establishment::factory()->create(['name' => 'Colegio Los Robles']));

        $this->actingAs($this->usuario(Rol::Contabilidad));

        $this->get(route('inscripciones.show', $this->orden))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('conjunto', 1)
                ->where('conjunto.0.id', $hermana->id)
                ->where('conjunto.0.colegio', 'Colegio Los Robles')
                ->where('conjunto.0.total', 50000));

        // En un clic se llega a la ficha de la hermana.
        $this->get(route('inscripciones.show', $hermana))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('conjunto', 1)
                ->where('conjunto.0.id', $this->orden->id));
    }

    public function test_con_un_solo_colegio_no_hay_conjunto_que_mostrar(): void
    {
        $this->actingAs($this->usuario(Rol::Contabilidad));

        $this->get(route('inscripciones.show', $this->orden))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('conjunto', null));
    }

    public function test_acreditacion_no_entra_a_inscripciones(): void
    {
        $this->actingAs($this->usuario(Rol::Acreditacion));

        $this->get(route('inscripciones.index'))->assertForbidden();
        $this->get(route('inscripciones.show', $this->orden))->assertForbidden();
    }

    public function test_coordinacion_carga_un_comprobante_que_llego_por_correo(): void
    {
        $coordinadora = $this->usuario(Rol::Coordinacion);

        $this->actingAs($coordinadora)
            ->post(route('inscripciones.comprobante', $this->orden), $this->comprobante())
            ->assertSessionHasNoErrors();

        $pago = $this->orden->payments()->firstOrFail();
        $this->assertSame(PaymentStatus::EnValidacion, $pago->status);
        $this->assertSame($coordinadora->id, $pago->submitted_by_user_id);
        $this->assertSame(PaymentStatus::EnValidacion, $this->orden->fresh()->payment_status);
    }

    public function test_acreditacion_no_carga_comprobantes(): void
    {
        $this->actingAs($this->usuario(Rol::Acreditacion))
            ->post(route('inscripciones.comprobante', $this->orden), $this->comprobante())
            ->assertForbidden();

        $this->assertSame(0, $this->orden->payments()->count());
    }

    public function test_el_reemplazo_conserva_acceso_y_valor(): void
    {
        $total = $this->orden->total;

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->post(route('inscripciones.reemplazar', [$this->orden, $this->participante]), [
                'first_name' => 'Daniela',
                'last_name' => 'Soto',
                'position' => 'Docente Enseñanza Básica',
                'email' => 'daniela@colegio.cl',
                'rut' => '11.111.111-1',
            ])
            ->assertSessionHasNoErrors();

        $saliente = $this->participante->fresh();
        $entrante = $saliente->replacedBy;

        $this->assertSame(ParticipantStatus::Reemplazado, $saliente->status);
        $this->assertSame('Daniela', $entrante->first_name);
        $this->assertSame($saliente->access_type_id, $entrante->access_type_id);
        $this->assertSame($saliente->unit_price, $entrante->unit_price);
        $this->assertSame($total, $this->orden->fresh()->total);
    }

    public function test_contabilidad_no_reemplaza_ni_edita_notas(): void
    {
        $this->actingAs($this->usuario(Rol::Contabilidad));

        $this->post(route('inscripciones.reemplazar', [$this->orden, $this->participante]), ['first_name' => 'Daniela'])
            ->assertForbidden();
        $this->patch(route('inscripciones.notas', $this->orden), ['internal_notes' => 'Hola'])
            ->assertForbidden();

        $this->assertSame(ParticipantStatus::Registrado, $this->participante->fresh()->status);
    }

    public function test_cancelar_exige_motivo_y_devuelve_los_cupos(): void
    {
        $jornada = $this->orden->event->sessions()->firstOrFail();
        $this->assertSame(1, $jornada->reserved_seats);

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('inscripciones.cancelar', $this->orden), ['motivo' => 'Se retiró'])
            ->assertForbidden();

        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->post(route('inscripciones.cancelar', $this->orden), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->post(route('inscripciones.cancelar', $this->orden), ['motivo' => 'La institución se retiró'])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Cancelada, $this->orden->fresh()->status);
        $this->assertSame(0, $jornada->fresh()->reserved_seats);
        $this->assertDatabaseHas('audit_logs', ['action' => 'orden.cancelada', 'comment' => 'La institución se retiró']);
    }

    public function test_no_se_cancela_con_plata_de_por_medio(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        foreach ([PaymentStatus::EnValidacion, PaymentStatus::Aprobado] as $pago) {
            $this->orden->forceFill(['payment_status' => $pago])->save();

            $this->post(route('inscripciones.cancelar', $this->orden), ['motivo' => 'Se retiró']);

            $this->assertSame(OrderStatus::Reservada, $this->orden->fresh()->status);
        }

        $this->assertSame(1, $this->orden->event->sessions()->firstOrFail()->reserved_seats);
    }

    public function test_reactivar_una_reserva_vencida_vuelve_a_tomar_los_cupos(): void
    {
        $this->orden->forceFill(['reserved_until' => now()->subDay()])->save();
        $this->artisan('reservas:expirar')->assertSuccessful();

        $jornada = $this->orden->event->sessions()->firstOrFail();
        $this->assertSame(OrderStatus::Vencida, $this->orden->fresh()->status);
        $this->assertSame(0, $jornada->fresh()->reserved_seats);

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('inscripciones.reactivar', $this->orden))
            ->assertForbidden();

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->post(route('inscripciones.reactivar', $this->orden))
            ->assertSessionHasNoErrors();

        $orden = $this->orden->fresh();
        $this->assertSame(OrderStatus::Reservada, $orden->status);
        $this->assertTrue($orden->reserved_until->isFuture());
        $this->assertSame(1, $jornada->fresh()->reserved_seats);
    }

    public function test_no_se_reactiva_si_otro_tomo_el_ultimo_cupo(): void
    {
        $this->orden->forceFill(['reserved_until' => now()->subDay()])->save();
        $this->artisan('reservas:expirar')->assertSuccessful();

        $jornada = $this->orden->event->sessions()->firstOrFail();
        $jornada->forceFill(['capacity' => 1, 'reserved_seats' => 1])->save();

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->post(route('inscripciones.reactivar', $this->orden));

        $this->assertSame(OrderStatus::Vencida, $this->orden->fresh()->status);
        $this->assertSame(1, $jornada->fresh()->reserved_seats);
    }

    public function test_coordinacion_corrige_los_datos_de_un_participante(): void
    {
        $antes = $this->participante->fresh();

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->patch(route('inscripciones.participantes.corregir', [$this->orden, $this->participante]), ['first_name' => 'Otro'])
            ->assertForbidden();

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->patch(route('inscripciones.participantes.corregir', [$this->orden, $this->participante]), [
                'first_name' => 'Carlos',
                'last_name' => 'Muñoz',
                'rut' => '12.345.678-5',
                'position' => 'Docente Enseñanza Básica',
                'email' => 'carlos@colegio.cl',
            ])
            ->assertSessionHasNoErrors();

        $participante = $this->participante->fresh();
        $this->assertSame('Muñoz', $participante->last_name);
        $this->assertSame(Rut::normalizar('12.345.678-5'), $participante->rut);
        $this->assertSame($antes->access_type_id, $participante->access_type_id);
        $this->assertSame($antes->unit_price, $participante->unit_price);
        $this->assertDatabaseHas('audit_logs', ['action' => 'participante.corregido']);
    }

    public function test_corregir_participante_rechaza_basura_y_normaliza(): void
    {
        $ruta = route('inscripciones.participantes.corregir', [$this->orden, $this->participante]);

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->patch($ruta, ['first_name' => '.', 'last_name' => 'Soto', 'position' => 'Docente', 'email' => 'a@colegio.cl'])
            ->assertSessionHasErrors('first_name');

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->patch($ruta, [
                'first_name' => 'PEDRO', 'last_name' => 'soto', 'position' => 'Otro',
                'position_otro' => 'inspector general', 'email' => 'Pedro@Colegio.cl', 'rut' => '11.111.111-1',
            ])
            ->assertSessionHasNoErrors();

        $participante = $this->participante->fresh();
        $this->assertSame('Pedro', $participante->first_name);
        $this->assertSame('Soto', $participante->last_name);
        $this->assertSame('Inspector General', $participante->position);
        $this->assertSame('pedro@colegio.cl', $participante->email);
    }

    public function test_la_factura_se_registra_solo_con_el_pago_aprobado_y_por_contabilidad(): void
    {
        $factura = [
            'document_type' => 'invoice',
            'number' => '1234',
            'issued_on' => now()->toDateString(),
            'amount' => 90000,
        ];

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('inscripciones.factura', $this->orden), $factura)
            ->assertForbidden();

        $this->aprobarElPago();

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->post(route('inscripciones.factura', $this->orden), $factura)
            ->assertForbidden();

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('inscripciones.factura', $this->orden), $factura)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->orden->invoiceRecords()->count());
    }

    public function test_la_factura_se_envia_al_responsable_y_a_la_entidad_pagadora(): void
    {
        config(['facturacion.copia_administracion' => 'administracion@test.cl']);

        $this->aprobarElPago();
        $this->orden->payerEntity->forceFill(['billing_email' => 'pagos@fundacion.cl'])->save();

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('inscripciones.factura', $this->orden), [
                'document_type' => 'invoice',
                'number' => '9001',
                'issued_on' => now()->toDateString(),
                'amount' => 90000,
            ])
            ->assertSessionHasNoErrors();

        // Los dos reciben: puede que la entidad pagadora no lea y el responsable
        // se lo avise, o al revés. Mandarlo a uno solo hace que se pierda.
        Mail::assertQueued(FacturaEmitida::class, function (FacturaEmitida $correo): bool {
            return $correo->hasTo($this->orden->responsible_email)
                && $correo->hasTo('pagos@fundacion.cl')
                && $correo->hasCc('administracion@test.cl');
        });

        $factura = $this->orden->invoiceRecords()->first();

        $this->assertNotNull($factura->sent_at);
        $this->assertStringContainsString('pagos@fundacion.cl', $factura->sent_to);
    }

    public function test_la_factura_puede_registrarse_sin_enviarla(): void
    {
        $this->aprobarElPago();

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('inscripciones.factura', $this->orden), [
                'document_type' => 'invoice',
                'number' => '9002',
                'issued_on' => now()->toDateString(),
                'amount' => 90000,
                'enviar' => false,
            ])
            ->assertSessionHasNoErrors();

        Mail::assertNotQueued(FacturaEmitida::class);
        $this->assertNull($this->orden->invoiceRecords()->first()->sent_at);
    }

    /** Facturar exige un abono aprobado: es lo que el colegio va a rendir. */
    private function aprobarElPago(): void
    {
        $this->orden->payments()->create([
            'status' => PaymentStatus::Aprobado,
            'amount' => $this->orden->total,
            'paid_on' => now()->toDateString(),
        ]);
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        $this->orden->refresh();
    }

    public function test_administracion_registra_un_invitado_sin_costo_que_toma_cupo_y_recibe_credencial(): void
    {
        $datos = [
            'event_id' => $this->orden->event_id,
            'first_name' => 'Patricia', 'last_name' => 'Rojas', 'rut' => '11.111.111-1',
            'email' => 'patricia@invitada.cl', 'position' => 'Sostenedor/a',
            'establecimiento' => 'Corporación Municipal',
            'access_type_id' => $this->orden->participants()->first()->access_type_id,
        ];

        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->post(route('inscripciones.invitado'), $datos)
            ->assertForbidden();

        $cuposAntes = $this->orden->event->sessions()->sum('reserved_seats');

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('inscripciones.invitado'), $datos)
            ->assertSessionHasNoErrors();

        $invitado = Order::where('kind', OrderKind::Invitado)->sole();

        $this->assertSame(0, $invitado->total);
        $this->assertSame(PaymentStatus::Aprobado, $invitado->payment_status);
        $this->assertNotNull($invitado->number);
        $this->assertSame(1, $invitado->tickets()->count(), 'El invitado recibe su credencial.');
        $this->assertGreaterThan($cuposAntes, $this->orden->event->sessions()->sum('reserved_seats'), 'Ocupa cupo.');
        Mail::assertQueued(CredencialParticipante::class);
    }
}
