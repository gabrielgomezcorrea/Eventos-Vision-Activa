<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentReviewAction;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Exceptions\TicketsNoLiberables;
use App\Mail\CredencialParticipante;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmisionDeTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private AccessType $acceso;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));
        $this->seed(RolesSeeder::class);

        $this->event = Event::create([
            'name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado,
        ]);
        $jornada = $this->event->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 50]);
        $this->acceso = $this->event->accessTypes()->create([
            'name' => 'Ambas jornadas', 'position' => 1, 'price' => 150000,
            'wristband_label' => 'Acceso completo', 'wristband_color' => '#F58A07',
        ]);
        $this->acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);
    }

    private function orden(array $participantes = [['first_name' => 'Carlos', 'email' => 'carlos@colegio.cl']]): Order
    {
        $orden = Order::create([
            'event_id' => $this->event->id,
            'responsible_name' => 'Ana',
            'responsible_lastname' => 'Pérez',
            'responsible_email' => 'ana'.Order::count().'@colegio.cl',
            'payer_entity_id' => PayerEntity::create(['name' => 'Fundación'])->id,
        ]);

        foreach ($participantes as $p) {
            $orden->participants()->create(array_merge(['access_type_id' => $this->acceso->id], $p));
        }

        return app(ConfirmarOrden::class)($orden->fresh());
    }

    private function aprobar(Order $orden): Order
    {
        $pago = app(RegistrarComprobante::class)(
            $orden, ['amount' => $orden->total], UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')
        );

        $contadora = User::factory()->create(['is_active' => true]);
        $contadora->assignRole(Rol::Contabilidad->value);

        app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contadora->fresh());

        return $orden->fresh();
    }

    public function test_no_emite_credenciales_sin_pago_aprobado(): void
    {
        $orden = $this->orden();

        $this->assertSame(PaymentStatus::Pendiente, $orden->payment_status);

        try {
            app(EmitirTickets::class)($orden);
            $this->fail('Debio negarse a emitir.');
        } catch (TicketsNoLiberables $e) {
            $this->assertStringContainsString('pago está aprobado', $e->getMessage());
        }

        $this->assertSame(0, Ticket::count());
    }

    public function test_aprobar_el_pago_emite_una_credencial_por_participante(): void
    {
        $orden = $this->orden([
            ['first_name' => 'Carlos', 'email' => 'carlos@colegio.cl'],
            ['first_name' => 'Luisa', 'email' => 'luisa@colegio.cl'],
        ]);

        $this->aprobar($orden);

        $this->assertSame(2, Ticket::count());

        foreach ($orden->fresh()->participants as $participante) {
            $this->assertNotNull($participante->ticketVigente());
            $this->assertSame(ParticipantStatus::CredencialEmitida, $participante->fresh()->status);
        }
    }

    public function test_el_qr_no_contiene_datos_personales(): void
    {
        $orden = $this->orden([['first_name' => 'Carlos', 'last_name' => 'Rojas', 'rut' => '11111111-1', 'email' => 'carlos@colegio.cl']]);
        $this->aprobar($orden);

        $ticket = Ticket::sole();
        $contenido = $ticket->url();

        // El QR lleva la URL de la credencial: un identificador opaco y nada más.
        foreach (['Carlos', 'Rojas', '11111111', 'carlos@colegio.cl', $orden->number] as $dato) {
            $this->assertStringNotContainsString($dato, $contenido);
        }

        $this->assertStringContainsString('/t/', $contenido);
    }

    public function test_el_token_se_guarda_cifrado_y_su_hash_permite_buscarlo(): void
    {
        $orden = $this->orden();
        $this->aprobar($orden);

        $ticket = Ticket::sole();
        $token = $ticket->token;

        // En la base el token no queda en claro.
        $crudo = DB::table('tickets')->where('id', $ticket->id)->first();

        $this->assertNotSame($token, $crudo->token);
        $this->assertSame(hash('sha256', $token), $crudo->token_hash);
        $this->assertTrue($ticket->is(Ticket::resolver($token)));
        $this->assertNull(Ticket::resolver('token-inventado'));
    }

    public function test_el_alfabeto_del_codigo_no_tiene_caracteres_ambiguos(): void
    {
        // Comprobacion directa sobre el alfabeto. Revisar solo codigos
        // generados es azaroso: un caracter malo aparece unas veces si y
        // otras no, y la prueba pasa por suerte.
        $alfabeto = (new \ReflectionClass(Ticket::class))->getConstant('ALFABETO');

        foreach (['0', 'O', '1', 'I', 'L', '5', 'S', '8', 'B'] as $ambiguo) {
            $this->assertStringNotContainsString(
                $ambiguo,
                $alfabeto,
                "El alfabeto no debe incluir '{$ambiguo}': se confunde al dictarlo o al leerlo.",
            );
        }
    }

    public function test_el_codigo_de_respaldo_evita_caracteres_ambiguos(): void
    {
        $orden = $this->orden([
            ['first_name' => 'A'], ['first_name' => 'B'], ['first_name' => 'C'],
        ]);
        $this->aprobar($orden);

        foreach (Ticket::pluck('code') as $codigo) {
            $this->assertMatchesRegularExpression('/^[ACDEFGHJKMNPQRTUVWXY234679]{4}-[ACDEFGHJKMNPQRTUVWXY234679]{4}$/', $codigo);

            // Sin caracteres que se confundan al dictarlos por teléfono.
            foreach (['0', 'O', '1', 'I', 'L', '5', 'S', '8', 'B'] as $ambiguo) {
                $this->assertStringNotContainsString($ambiguo, $codigo);
            }
        }
    }

    public function test_los_codigos_son_unicos(): void
    {
        $orden = $this->orden(array_map(fn (int $i) => ['first_name' => 'P'.$i], range(1, 10)));
        $this->aprobar($orden);

        $codigos = Ticket::pluck('code');

        $this->assertCount(10, $codigos);
        $this->assertSame(10, $codigos->unique()->count());
    }

    public function test_emitir_dos_veces_no_duplica_credenciales(): void
    {
        $orden = $this->orden();
        $this->aprobar($orden);

        $ticket = Ticket::sole();

        app(EmitirTickets::class)($orden->fresh());
        app(EmitirTickets::class)($orden->fresh());

        $this->assertSame(1, Ticket::count());
        $this->assertSame($ticket->code, Ticket::sole()->code);
    }

    public function test_envia_la_credencial_a_cada_participante_con_correo(): void
    {
        $orden = $this->orden([
            ['first_name' => 'Carlos', 'email' => 'carlos@colegio.cl'],
            ['first_name' => 'Luisa', 'email' => 'luisa@colegio.cl'],
        ]);
        $this->aprobar($orden);

        Mail::assertQueued(CredencialParticipante::class, 2);
        Mail::assertQueued(CredencialParticipante::class, fn ($m) => $m->hasTo('carlos@colegio.cl'));
    }

    public function test_un_correo_invalido_o_ausente_no_frena_la_emision(): void
    {
        // En inscripciones institucionales muchos participantes vienen sin
        // correo o con uno mal escrito. Eso no puede romper la orden.
        $orden = $this->orden([
            ['first_name' => 'Sin correo'],
            ['first_name' => 'Mal escrito', 'email' => 'esto-no-es-un-correo'],
            ['first_name' => 'Correcto', 'email' => 'ok@colegio.cl'],
        ]);

        $this->aprobar($orden);

        // Las tres credenciales se emiten igual.
        $this->assertSame(3, Ticket::count());

        // Solo se envía correo al que tiene una dirección válida.
        Mail::assertQueued(CredencialParticipante::class, 1);
        $this->assertSame(1, Ticket::whereNotNull('emailed_at')->count());
    }

    public function test_queda_registrado_en_auditoria(): void
    {
        $orden = $this->orden([['first_name' => 'A'], ['first_name' => 'B']]);
        $this->aprobar($orden);

        $log = AuditLog::where('action', 'tickets.emitidos')->sole();

        $this->assertSame(2, $log->properties['cantidad']);
    }
}
