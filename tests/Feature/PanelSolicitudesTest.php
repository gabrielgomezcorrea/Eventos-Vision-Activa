<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\EventStatus;
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

    public function test_el_filtro_de_seguimiento_y_el_estado_contactada(): void
    {
        $evento = $this->solicitud->event;
        $contactada = ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'Luis', 'last_name' => 'Soto', 'email' => 'luis@colegio.cl', 'consented_at' => now()]);
        $contactada->notes()->create(['body' => 'Llamé']);
        $inscrita = ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'Eva', 'last_name' => 'Paz', 'email' => 'eva@colegio.cl', 'consented_at' => now(), 'converted_at' => now()]);

        $this->actingAs($this->usuario(Rol::Coordinacion));

        $correos = fn (array $q) => collect($this->get(route('solicitudes.index', $q))->viewData('page')['props']['solicitudes']['data'])->pluck('correo')->sort()->values()->all();

        // Sin seguimiento: sin notas y sin inscribirse (Marta). Eva ya se inscribió.
        $this->assertSame(['marta@colegio.cl'], $correos(['seguimiento' => 'sin']));
        $this->assertSame(['luis@colegio.cl'], $correos(['seguimiento' => 'con']));
        $this->assertCount(3, $correos([]));

        $this->assertSame('Contactada', $contactada->fresh()->estado());
        $this->assertSame('Ya se inscribió', $inscrita->fresh()->estado());
        $this->assertSame('Nueva', $this->solicitud->fresh()->estado());
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
