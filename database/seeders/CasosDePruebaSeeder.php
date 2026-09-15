<?php

namespace Database\Seeders;

use App\Actions\AcreditarParticipante;
use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentReviewAction;
use App\Models\AccessType;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PayerEntity;
use App\Models\Payment;
use App\Models\ProgramRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;

/**
 * Un puñado de casos concretos para revisar el sistema a mano.
 *
 * A diferencia de `DemoSeeder`, que llena la base con volumen realista, acá hay
 * pocos registros y cada uno existe para probar algo distinto: aprobado con
 * credenciales, rechazado, observado, esperando pago, vencido y acreditado.
 *
 * Idempotente: borra su propio evento y todo lo que cuelga de él, y lo vuelve a
 * crear. Correrlo dos veces deja exactamente lo mismo.
 *
 *     php artisan db:seed --class=CasosDePruebaSeeder
 *
 * No usar en producción.
 */
class CasosDePruebaSeeder extends Seeder
{
    private const SLUG = 'casos-de-prueba';

    public function run(): void
    {
        // Un seeder no manda correos reales a nadie.
        Mail::fake();

        $this->call(CuentasDePruebaSeeder::class);

        $contabilidad = User::where('email', 'contabilidad@test.cl')->firstOrFail();
        $acreditacion = User::where('email', 'acreditacion@test.cl')->firstOrFail();

        $this->borrarLoAnterior();

        $evento = $this->crearEvento();
        $acceso = $evento->accessTypes->first();
        $establecimiento = Establishment::factory()->create(['name' => 'Liceo Bicentenario A-12']);

        // 2 exitosas: pago aprobado y credenciales emitidas. En la segunda,
        // además, un participante ya pasó por la puerta.
        $aprobadas = collect([
            $this->caso($evento, $acceso, $establecimiento, 'Patricia Riquelme', 3),
            $this->caso($evento, $acceso, $establecimiento, 'Hernán Valdés', 2),
        ])->map(function (Order $orden) use ($contabilidad): Order {
            $pago = $this->comprobante($orden);
            app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contabilidad);

            return $orden->fresh();
        });

        $ticket = $aprobadas->last()->tickets()->vigentes()->first();
        app(AcreditarParticipante::class)($ticket->load('participant.accessType'), $acreditacion, 'qr');

        // 2 rechazadas: el comprobante no acreditó el pago.
        foreach ([
            ['Marcela Ibáñez', 'El comprobante corresponde a otra cuenta de destino.'],
            ['Rodrigo Pinto', 'La transferencia aparece reversada por el banco.'],
        ] as [$nombre, $motivo]) {
            $orden = $this->caso($evento, $acceso, $establecimiento, $nombre, 2);
            $pago = $this->comprobante($orden, exacto: false);
            app(RevisarPago::class)($pago, PaymentReviewAction::Rechazar, $contabilidad, $motivo);
        }

        // 3 pendientes de pago: reservadas, sin comprobante. Una vence mañana,
        // para ver el aviso de reserva por vencer.
        $porVencer = $this->caso($evento, $acceso, $establecimiento, 'Claudia Sepúlveda', 4);
        $porVencer->forceFill(['reserved_until' => now()->addDay()])->save();

        $this->caso($evento, $acceso, $establecimiento, 'Ignacio Fuentes', 1);
        $this->caso($evento, $acceso, $establecimiento, 'Ana María Torres', 6);

        // 1 en validación: contabilidad la tiene sobre la mesa.
        $this->comprobante($this->caso($evento, $acceso, $establecimiento, 'Jorge Bustamante', 2));

        // 1 observada: el monto no cuadra y el cliente debe corregir.
        $observada = $this->caso($evento, $acceso, $establecimiento, 'Verónica Lagos', 3);
        app(RevisarPago::class)(
            $this->comprobante($observada, exacto: false),
            PaymentReviewAction::Observar,
            $contabilidad,
            'El monto transferido no coincide con el total de la inscripción.',
        );

        // 1 vencida: nunca pagó y los cupos se liberaron.
        $vencida = $this->caso($evento, $acceso, $establecimiento, 'Luis Carrasco', 2);
        $vencida->forceFill([
            'status' => OrderStatus::Vencida,
            'reserved_until' => now()->subDays(3),
        ])->save();

        // 1 borrador: empezó y no terminó.
        $this->borrador($evento, $acceso, $establecimiento, 'Sofía Herrera', 2);

        $this->solicitudes($evento);

        $this->resumen($evento);
    }

    /** Borra el evento anterior con sus órdenes, participantes y credenciales. */
    private function borrarLoAnterior(): void
    {
        Event::withTrashed()->where('slug', self::SLUG)->each(
            fn (Event $anterior) => $anterior->forceDelete()
        );

        Establishment::where('name', 'Liceo Bicentenario A-12')->delete();
        PayerEntity::where('name', 'Corporación Municipal de Prueba')->delete();
    }

    private function crearEvento(): Event
    {
        $evento = Event::create([
            'name' => 'Casos de prueba '.now()->year,
            'slug' => self::SLUG,
            'status' => EventStatus::Publicado,
            'starts_on' => now()->addMonth()->toDateString(),
            'description' => 'Evento con inscripciones en todos los estados, para revisar el sistema a mano.',
            'location' => 'Centro de Eventos Vision Activa',
            'city' => 'Santiago',
            'contact_name' => 'Flor Contabilidad',
            'contact_email' => 'contacto@visionactiva.cl',
            'contact_phone' => '+56912345678',
            'bank_holder_name' => 'Vision Activa SpA',
            'bank_holder_rut' => '76.543.210-K',
            'bank_name' => 'Banco de Chile',
            'bank_account_type' => 'Cuenta Corriente',
            'bank_account_number' => '00112233445',
            'bank_email' => 'pagos@visionactiva.cl',
            'reminder_hours_before' => 48,
        ]);

        $jornada = $evento->sessions()->create([
            'name' => 'Jornada única',
            'position' => 1,
            'capacity' => 200,
            'starts_at' => now()->addMonth()->setTime(9, 0),
            'ends_at' => now()->addMonth()->setTime(18, 0),
        ]);

        $acceso = $evento->accessTypes()->create([
            'name' => 'Curso completo',
            'position' => 1,
            'price' => 160000,
            // Precio anticipado vigente, para ver el caso en pantalla.
            'early_price' => 80000,
            'early_until' => now()->addWeeks(2)->toDateString(),
            'wristband_label' => 'Pulsera azul',
            'wristband_color' => '#084887',
        ]);

        $acceso->sessions()->attach($jornada->id, ['seats' => 1]);

        // Un tramo de descuento, para ver una orden con rebaja aplicada.
        $evento->discountTiers()->create([
            'min_participants' => 4,
            'type' => 'percent',
            'value' => 10,
        ]);

        return $evento->fresh()->load('accessTypes', 'discountTiers');
    }

    /** Orden confirmada, con sus participantes y su entidad pagadora. */
    private function caso(Event $evento, AccessType $acceso, Establishment $establecimiento, string $responsable, int $participantes): Order
    {
        return app(ConfirmarOrden::class)(
            $this->borrador($evento, $acceso, $establecimiento, $responsable, $participantes)
        );
    }

    private function borrador(Event $evento, AccessType $acceso, Establishment $establecimiento, string $responsable, int $participantes): Order
    {
        $pagadora = PayerEntity::firstOrCreate(
            ['name' => 'Corporación Municipal de Prueba'],
            ['rut' => '70.123.456-7', 'billing_email' => 'facturacion@corporacion.cl'],
        );

        $orden = $evento->orders()->create([
            'responsible_name' => $responsable,
            'responsible_email' => str(explode(' ', $responsable)[0])->lower()->ascii().'@colegio.cl',
            'responsible_phone' => '+56912345678',
            // Cargos variados para probar el filtro de la exportación.
            'responsible_position' => ['Directivo', 'Sostenedor/a', 'Administrativo', 'Jefa de UTP'][crc32($responsable) % 4],
            'responsible_institution' => $establecimiento->name,
            'payer_entity_id' => $pagadora->id,
        ]);

        $orden->establishments()->attach($establecimiento->id);

        Participant::factory()->count($participantes)->create([
            'order_id' => $orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $acceso->id,
        ]);

        return $orden->fresh();
    }

    /**
     * Solicitudes del formulario público para probar la exportación: con y sin
     * consentimiento, cargos de la lista y escritos a mano, y correos que
     * también son responsables, para ver que no se duplican.
     */
    private function solicitudes(Event $evento): void
    {
        foreach ([
            // Misma persona que una responsable aprobada, con consentimiento.
            ['Patricia', 'Riquelme', 'patricia@colegio.cl', 'Directivo', true],
            // Responsable, pero sin consentimiento: no sale en la lista para campañas.
            ['Hernán', 'Valdés', 'hernan@colegio.cl', 'Sostenedor/a', false],
            ['Camila', 'Rojas Díaz', 'camila.rojas@liceo.cl', 'Docente', true],
            ['Tomás', 'Aravena', 'taravena@colegio.cl', 'Docente', false],
            ['Francisca', 'Muñoz Peña', 'fmunoz@escuela.cl', 'Administrativo', true],
            ['Pedro', "O'Ryan", 'pedro.oryan@gmail.com', 'Particular', true],
            ['Josefina', 'Soto', 'jsoto@colegio.cl', 'Orientadora', true],
            ['Matías', 'Leiva', 'mleiva@colegio.cl', 'Inspector General', false],
            ['Daniela', 'Castro', 'dcastro@corporacion.cl', 'Sostenedor/a', true],
            ['Andrés', 'Palma', 'apalma@liceo.cl', 'Directivo', true],
        ] as [$nombre, $apellidos, $correo, $cargo, $marketing]) {
            ProgramRequest::factory()->create([
                'event_id' => $evento->id,
                'first_name' => $nombre,
                'last_name' => $apellidos,
                'email' => $correo,
                'position' => $cargo,
                'institution' => 'Liceo Bicentenario A-12',
                'phone' => '+56987654321',
                'marketing_consented_at' => $marketing ? now()->subDays(2) : null,
            ]);
        }
    }

    private function comprobante(Order $orden, bool $exacto = true): Payment
    {
        return app(RegistrarComprobante::class)(
            $orden->fresh(),
            [
                'amount' => $exacto ? $orden->total : $orden->total - 15000,
                'paid_on' => now()->subDay()->toDateString(),
                'bank_name' => 'BancoEstado',
                'payer_name' => $orden->payerEntity?->name,
                'payer_rut' => $orden->payerEntity?->rut,
            ],
            UploadedFile::fake()->create('comprobante.pdf', 120, 'application/pdf'),
            actorLabel: $orden->responsible_email,
        );
    }

    private function resumen(Event $evento): void
    {
        $ordenes = $evento->orders()->get();

        $this->command->info("Casos de prueba listos en el evento «{$evento->name}».");

        foreach ($ordenes->groupBy(fn (Order $o) => $o->payment_status->label()) as $estado => $grupo) {
            $this->command->line(sprintf('  %-22s %d', $estado, $grupo->count()));
        }

        $this->command->line('  Formulario público: /f/'.self::SLUG);
    }
}
