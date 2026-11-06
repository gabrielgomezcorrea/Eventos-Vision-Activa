<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EtapaDeSeguimiento;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Mail\ProgramaDelEvento;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PayerEntity;
use App\Models\Payment;
use App\Models\ProgramRequest;
use App\Models\ProgramRequestNote;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Seguimiento de solicitudes: quién puede tocarlas y que reenviar el programa
 * le escriba de verdad a la persona.
 */
class PanelSolicitudesTest extends TestCase
{
    use RefreshDatabase;

    private ProgramRequest $solicitud;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesSeeder::class);

        $evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026']);

        $this->solicitud = ProgramRequest::create([
            'event_id' => $evento->id,
            'first_name' => 'Marta',
            'last_name' => 'Rojas',
            'email' => 'marta@colegio.cl',
            'consented_at' => now(),
            'extra' => ['comuna' => 'Ñuñoa'],
        ]);
    }

    private function usuario(Rol $rol): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol->value);

        return $usuario->fresh();
    }

    public function test_las_pantallas_de_solicitudes_cargan(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->get(route('solicitudes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('solicitudes/index')->has('solicitudes.data', 1));

        $this->get(route('solicitudes.show', $this->solicitud))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('solicitudes/show')
                ->where('respuestas.0.respuesta', 'Ñuñoa'));
    }

    public function test_reenviar_el_programa_le_escribe_a_la_persona(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion))
            ->post(route('solicitudes.reenviar', $this->solicitud));

        Mail::assertQueued(ProgramaDelEvento::class, fn (ProgramaDelEvento $correo) => $correo->hasTo('marta@colegio.cl'));
        $this->assertNotNull($this->solicitud->fresh()->program_sent_at);
    }

    public function test_contabilidad_no_ve_solicitudes(): void
    {
        // El seguimiento de prospectos no es trabajo de Contabilidad: las
        // solicitudes tienen permiso propio desde que se separó de las
        // inscripciones, que sí necesita para revisar un pago en contexto.
        $this->actingAs($this->usuario(Rol::Contabilidad));

        $this->get(route('solicitudes.index'))->assertForbidden();
        $this->get(route('solicitudes.show', $this->solicitud))->assertForbidden();
        $this->post(route('solicitudes.reenviar', $this->solicitud))->assertForbidden();
        $this->post(route('solicitudes.notas.store', $this->solicitud), ['body' => 'Llamé'])->assertForbidden();
        $this->delete(route('solicitudes.destroy', $this->solicitud))->assertForbidden();

        Mail::assertNothingQueued();
        $this->assertNotNull($this->solicitud->fresh());
    }

    public function test_acreditacion_no_ve_solicitudes(): void
    {
        $this->actingAs($this->usuario(Rol::Acreditacion));

        $this->get(route('solicitudes.index'))->assertForbidden();
        $this->get(route('solicitudes.show', $this->solicitud))->assertForbidden();
    }

    public function test_coordinacion_guarda_una_nota_con_su_nombre_y_contabilidad_no(): void
    {
        $flor = $this->usuario(Rol::Coordinacion);

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('solicitudes.notas.store', $this->solicitud), ['body' => 'Llamé'])
            ->assertForbidden();

        $this->actingAs($flor)
            ->post(route('solicitudes.notas.store', $this->solicitud), ['body' => '  Llamé, no contestó.  '])
            ->assertSessionHasNoErrors();

        $nota = ProgramRequestNote::sole();
        $this->assertSame('Llamé, no contestó.', $nota->body);
        $this->assertSame($flor->id, $nota->user_id);

        $this->get(route('solicitudes.show', $this->solicitud))
            ->assertInertia(fn (Assert $p) => $p->has('notas', 1)->where('notas.0.autor', $flor->name));
    }

    public function test_una_nota_vacia_o_muy_corta_da_error_y_no_se_guarda(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        foreach (['', '   ', 'a'] as $texto) {
            $this->post(route('solicitudes.notas.store', $this->solicitud), ['body' => $texto])
                ->assertSessionHasErrors(['body' => 'Escribe la nota (al menos 2 caracteres).']);
        }

        $this->assertSame(0, ProgramRequestNote::count());
    }

    /** Correos del listado en una etapa, con los filtros dados. */
    private function correosEn(string $etapa, array $q = []): array
    {
        return collect($this->get(route('solicitudes.index', ['etapa' => $etapa, ...$q]))->viewData('page')['props']['solicitudes']['data'])
            ->pluck('correo')->sort()->values()->all();
    }

    /** Una orden de esta persona en el evento de la solicitud. */
    private function ordenDe(string $correo, array $datos = []): Order
    {
        return Order::create([
            'event_id' => $this->solicitud->event_id,
            'responsible_name' => '',
            'responsible_email' => $correo,
            ...$datos,
        ]);
    }

    public function test_cada_persona_cae_en_una_sola_etapa_segun_lo_que_hizo(): void
    {
        $evento = $this->solicitud->event;
        $nueva = fn (string $correo) => ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'X', 'email' => $correo, 'consented_at' => now()]);

        // Solicitó: Marta (nada), Luis (contactado) y Ana (solo hizo clic: borrador vacío).
        $luis = $nueva('luis@colegio.cl');
        $luis->notes()->create(['body' => 'Llamé']);
        $nueva('ana@colegio.cl');
        $this->ordenDe('ana@colegio.cl');

        // Pre inscrito: escribió datos, confirmó sin pagar, abonó en revisión, o se le venció.
        $nueva('borrador@colegio.cl');
        $this->ordenDe('borrador@colegio.cl', ['responsible_name' => 'Rosa']);
        $nueva('confirmo@colegio.cl');
        $this->ordenDe('confirmo@colegio.cl', ['status' => OrderStatus::Reservada]);
        $nueva('revision@colegio.cl');
        $this->ordenDe('revision@colegio.cl', ['status' => OrderStatus::Reservada, 'payment_status' => PaymentStatus::EnValidacion]);
        $nueva('vencida@colegio.cl');
        $this->ordenDe('vencida@colegio.cl', ['status' => OrderStatus::Vencida]);
        // Un colegio pagado y otro no: sigue pre inscrito hasta pagar todo.
        $nueva('mitad@colegio.cl');
        $this->ordenDe('mitad@colegio.cl', ['status' => OrderStatus::Reservada, 'payment_status' => PaymentStatus::Aprobado]);
        $this->ordenDe('mitad@colegio.cl', ['status' => OrderStatus::Reservada]);

        // Inscrito: todo pagado, aunque después abrió un borrador para otro colegio.
        $nueva('pagado@colegio.cl');
        $this->ordenDe('pagado@colegio.cl', ['status' => OrderStatus::Reservada, 'payment_status' => PaymentStatus::Aprobado]);
        $this->ordenDe('pagado@colegio.cl', ['responsible_name' => 'Eva']);

        // No participará: marcada y con una reserva vencida; confirmar sin pagar pesa más que la marca.
        $nueva('no@colegio.cl')->forceFill(['declined_at' => now()])->save();
        $this->ordenDe('no@colegio.cl', ['status' => OrderStatus::Vencida]);
        $nueva('volvio@colegio.cl')->forceFill(['declined_at' => now()])->save();
        $this->ordenDe('volvio@colegio.cl', ['status' => OrderStatus::Reservada]);

        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->assertSame(['ana@colegio.cl', 'luis@colegio.cl', 'marta@colegio.cl'], $this->correosEn('solicito'));
        $this->assertSame(['ana@colegio.cl', 'marta@colegio.cl'], $this->correosEn('solicito', ['seguimiento' => 'sin']));
        $this->assertSame(['borrador@colegio.cl', 'confirmo@colegio.cl', 'mitad@colegio.cl', 'revision@colegio.cl', 'vencida@colegio.cl', 'volvio@colegio.cl'], $this->correosEn('pre_inscrito'));
        $this->assertSame(['pagado@colegio.cl'], $this->correosEn('inscrito'));
        $this->assertSame(['no@colegio.cl'], $this->correosEn('no_participara'));
        // Sin etapa en la URL abre en «Solicitó»: es el trabajo del día.
        $this->assertSame(['ana@colegio.cl', 'luis@colegio.cl', 'marta@colegio.cl'], $this->correosEn(''));

        // Las cantidades de los botones suman a todos, sin repetir a nadie.
        $etapas = collect($this->get(route('solicitudes.index'))->viewData('page')['props']['etapas'])->pluck('cantidad', 'value');
        $this->assertSame(['solicito' => 3, 'pre_inscrito' => 6, 'inscrito' => 1, 'no_participara' => 1], $etapas->all());
        $this->assertSame(ProgramRequest::count(), $etapas->sum());

        // La ficha dice lo mismo que el listado.
        $this->assertSame(EtapaDeSeguimiento::PreInscrito, ProgramRequest::firstWhere('email', 'volvio@colegio.cl')->etapa());
        $this->assertSame(EtapaDeSeguimiento::Inscrito, ProgramRequest::firstWhere('email', 'pagado@colegio.cl')->etapa());
        $this->assertSame('Contactada', $luis->fresh()->seguimiento());

        $detalles = collect($this->get(route('solicitudes.index', ['etapa' => 'pre_inscrito']))->viewData('page')['props']['solicitudes']['data'])->pluck('detalle', 'correo');
        $this->assertSame('Dejó la inscripción a medias', $detalles['borrador@colegio.cl']);
        $this->assertSame('Pago en revisión', $detalles['revision@colegio.cl']);
        $this->assertSame('Reserva vencida', $detalles['vencida@colegio.cl']);
    }

    public function test_no_participara_sale_del_seguimiento_con_nota_por_defecto_y_se_deshace(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->post(route('solicitudes.notas.store', $this->solicitud), ['body' => '', 'tipo' => 'declined'])->assertRedirect();

        $marcada = $this->solicitud->fresh();
        $this->assertNotNull($marcada->declined_at);
        $this->assertSame(EtapaDeSeguimiento::NoParticipara, $marcada->etapa());
        $this->assertSame('No participará', $marcada->notes()->first()->body);
        $this->assertSame([], $this->correosEn('solicito'));
        $this->assertSame(['marta@colegio.cl'], $this->correosEn('no_participara'), 'No se borra.');

        $this->post(route('solicitudes.notas.store', $this->solicitud), ['body' => 'Cambió de idea', 'tipo' => 'resumed']);

        $this->assertNull($this->solicitud->fresh()->declined_at);
        $this->assertSame(EtapaDeSeguimiento::Solicito, $this->solicitud->fresh()->etapa());
        $this->assertSame(['marta@colegio.cl'], $this->correosEn('solicito'));
    }

    public function test_si_igual_paga_queda_inscrito_y_un_tipo_inventado_se_rechaza(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $this->post(route('solicitudes.notas.store', $this->solicitud), ['body' => 'Hola', 'tipo' => 'borrar'])->assertSessionHasErrors('tipo');

        $this->solicitud->forceFill(['declined_at' => now()])->save();
        $this->ordenDe('marta@colegio.cl', ['status' => OrderStatus::Reservada, 'payment_status' => PaymentStatus::Aprobado]);
        $this->assertSame(EtapaDeSeguimiento::Inscrito, $this->solicitud->fresh()->etapa());
    }

    public function test_descargar_la_etapa_trae_lo_que_se_ve(): void
    {
        $evento = $this->solicitud->event;
        $this->ordenDe('marta@colegio.cl', ['responsible_name' => 'Marta', 'responsible_phone' => '56912345678', 'status' => OrderStatus::Reservada]);
        // Entró directo: sin teléfono ni nombre propios, igual sale en la lista.
        ProgramRequest::create(['event_id' => $evento->id, 'first_name' => '', 'email' => 'directa@colegio.cl', 'consented_at' => now(), 'origin' => ProgramRequest::ORIGEN_DIRECTO]);
        $this->ordenDe('directa@colegio.cl', ['responsible_name' => 'Pedro']);

        $csv = fn (array $q) => $this->actingAs($this->usuario(Rol::Administrador))
            ->get(route('solicitudes.exportar.etapa', $q))->assertOk()->streamedContent();

        $todos = $csv(['etapa' => 'pre_inscrito']);
        $this->assertStringContainsString('marta@colegio.cl;Marta;Rojas;56912345678', $todos);
        $this->assertStringContainsString('"Pre inscrito";"Confirmó, falta pagar"', $todos);
        $this->assertStringContainsString('directa@colegio.cl;Pedro', $todos);

        $this->assertStringNotContainsString('marta@colegio.cl', $csv(['etapa' => 'solicito']));

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->get(route('solicitudes.exportar.etapa', ['etapa' => 'pre_inscrito']))->assertForbidden();
    }

    public function test_contabilidad_no_marca_no_participara(): void
    {
        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->post(route('solicitudes.notas.store', $this->solicitud), ['body' => '', 'tipo' => 'declined'])
            ->assertForbidden();

        $this->assertNull($this->solicitud->fresh()->declined_at);
    }

    /** Una persona de prueba que llegó hasta el pago: inscripción confirmada con un comprobante en disco. */
    private function conInscripcionYPago(string $correo): Order
    {
        Storage::fake('documentos');
        $evento = $this->solicitud->event;
        $evento->update(['status' => EventStatus::Publicado]);
        $jornada = $evento->sessions()->firstOrCreate(['position' => 1], ['name' => 'J1', 'capacity' => 10]);
        $acceso = $evento->accessTypes()->firstOrCreate(['position' => 1], ['name' => 'General', 'price' => 90000]);
        $acceso->sessions()->syncWithoutDetaching([$jornada->id => ['seats' => 1]]);

        $orden = Order::create([
            'event_id' => $evento->id, 'responsible_name' => 'Marta', 'responsible_lastname' => 'Rojas', 'responsible_email' => $correo,
            'payer_entity_id' => PayerEntity::create(['name' => 'Basura SpA'])->id,
        ]);
        $orden->participants()->create(['first_name' => 'Luis', 'access_type_id' => $acceso->id]);
        $orden = app(ConfirmarOrden::class)($orden->fresh());

        Storage::disk('documentos')->put("comprobantes/{$orden->id}/a.pdf", '%PDF');
        $pago = $orden->payments()->create(['status' => PaymentStatus::EnValidacion, 'amount' => 90000]);
        $pago->proofs()->create(['disk' => 'documentos', 'path' => "comprobantes/{$orden->id}/a.pdf", 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 4, 'hash' => 'x']);

        return $orden->fresh();
    }

    public function test_eliminar_solo_la_solicitud_deja_intacta_la_inscripcion(): void
    {
        $orden = $this->conInscripcionYPago('marta@colegio.cl');

        $this->actingAs($this->usuario(Rol::Administrador))
            ->delete(route('solicitudes.destroy', $this->solicitud))
            ->assertRedirect(route('solicitudes.index'));

        $this->assertNull(ProgramRequest::find($this->solicitud->id));
        $this->assertNotNull($orden->fresh());
        $this->assertSame(1, Participant::where('order_id', $orden->id)->count());
    }

    public function test_limpiar_solicitud_borra_la_solicitud_y_todo_lo_que_genero_y_devuelve_los_cupos(): void
    {
        $orden = $this->conInscripcionYPago('marta@colegio.cl');
        $ajena = $this->conInscripcionYPago('otra@colegio.cl');
        $jornada = $orden->event->sessions()->first();
        $this->assertSame(2, $jornada->fresh()->reserved_seats);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->delete(route('solicitudes.destroy_all', $this->solicitud))
            ->assertRedirect(route('solicitudes.index'));

        // Lo de esta persona, fuera: solicitud, inscripción, participantes, pagos, archivo y pagador.
        $this->assertNull(ProgramRequest::find($this->solicitud->id));
        $this->assertNull(Order::find($orden->id));
        $this->assertSame(0, Participant::where('order_id', $orden->id)->count());
        $this->assertSame(0, Payment::where('order_id', $orden->id)->count());
        Storage::disk('documentos')->assertMissing("comprobantes/{$orden->id}/a.pdf");
        $this->assertSame(1, PayerEntity::count(), 'Solo queda el pagador de la otra persona.');

        // Los cupos vuelven y lo de otra persona sigue igual.
        $this->assertSame(1, $jornada->fresh()->reserved_seats);
        $this->assertNotNull(Order::find($ajena->id));
        Storage::disk('documentos')->assertExists("comprobantes/{$ajena->id}/a.pdf");

        // Queda constancia en el evento de qué se borró.
        $this->assertSame(1, AuditLog::where('action', 'solicitud.eliminada_con_todo')->count());
    }

    public function test_solo_administracion_puede_limpiar_una_solicitud(): void
    {
        $orden = $this->conInscripcionYPago('marta@colegio.cl');

        foreach ([Rol::Coordinacion, Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->delete(route('solicitudes.destroy_all', $this->solicitud))
                ->assertForbidden();
        }

        $this->assertNotNull(ProgramRequest::find($this->solicitud->id));
        $this->assertNotNull(Order::find($orden->id));
    }

    public function test_limpiar_no_borra_la_inscripcion_de_otra_solicitud_que_puso_este_correo(): void
    {
        $evento = $this->solicitud->event;
        $otra = ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'Luis', 'last_name' => 'Soto', 'email' => 'luis@colegio.cl', 'consented_at' => now()]);
        $ajena = Order::create(['event_id' => $evento->id, 'program_request_id' => $otra->id, 'responsible_email' => 'marta@colegio.cl', 'responsible_name' => 'Luis']);
        $propia = Order::create(['event_id' => $evento->id, 'program_request_id' => $this->solicitud->id, 'responsible_email' => 'nuevo@colegio.cl', 'responsible_name' => 'Marta']);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->delete(route('solicitudes.destroy_all', $this->solicitud));

        $this->assertNotNull($ajena->fresh());
        $this->assertNull($propia->fresh());
    }
}
