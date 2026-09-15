<?php

namespace Database\Seeders;

use App\Actions\AcreditarParticipante;
use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\OrderStatus;
use App\Enums\PaymentReviewAction;
use App\Enums\Rol;
use App\Models\AccessType;
use App\Models\Accreditation;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\ProgramRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Datos de demostración con un evento realista y órdenes en todos los estados.
 *
 * Sirve para revisar pantallas con volumen de verdad, no con un registro suelto.
 * No usar en producción: `php artisan db:seed --class=DemoSeeder`.
 */
class DemoSeeder extends Seeder
{
    private const SLUG_DEMO = 'seminario-liderazgo-escolar-demo';

    public function run(): void
    {
        // Un seeder no debe mandar correos reales a nadie.
        Mail::fake();

        $this->call(RolesSeeder::class);

        $equipo = $this->crearEquipo();

        // Se puede correr las veces que haga falta: el evento anterior de
        // demostración se borra con todo lo que cuelga de él.
        Event::withTrashed()->where('slug', self::SLUG_DEMO)->each(
            fn (Event $anterior) => $anterior->forceDelete()
        );

        $evento = Event::factory()->conJornadasYAccesos(300)->create([
            'name' => 'Seminario de Liderazgo Escolar '.now()->year,
            'slug' => self::SLUG_DEMO,
        ]);

        $establecimientos = Establishment::factory()->count(12)->create();

        ProgramRequest::factory()->count(35)->create(['event_id' => $evento->id]);
        ProgramRequest::factory()->count(8)->convertida()->create(['event_id' => $evento->id]);
        ProgramRequest::factory()->count(3)->sinConsentimiento()->create(['event_id' => $evento->id]);

        $accesos = $evento->accessTypes;

        // Borradores: gente que empezó y no terminó.
        for ($i = 0; $i < 4; $i++) {
            $this->crearOrden($evento, $establecimientos, $accesos, random_int(1, 3));
        }

        // Reservadas esperando el pago.
        for ($i = 0; $i < 6; $i++) {
            $orden = $this->crearOrden($evento, $establecimientos, $accesos, random_int(2, 8));
            app(ConfirmarOrden::class)($orden);
        }

        // Con comprobante esperando validación.
        for ($i = 0; $i < 4; $i++) {
            $orden = app(ConfirmarOrden::class)(
                $this->crearOrden($evento, $establecimientos, $accesos, random_int(2, 6))
            );
            $this->cargarComprobante($orden, exacto: $i !== 0);
        }

        // Observadas: hay algo que el cliente debe corregir.
        for ($i = 0; $i < 2; $i++) {
            $orden = app(ConfirmarOrden::class)(
                $this->crearOrden($evento, $establecimientos, $accesos, random_int(2, 5))
            );
            $pago = $this->cargarComprobante($orden, exacto: false);
            app(RevisarPago::class)(
                $pago, PaymentReviewAction::Observar, $equipo['contabilidad'],
                'El monto transferido no coincide con el total de la inscripción.'
            );
        }

        // Aprobadas: generan credenciales.
        $aprobadas = collect();
        for ($i = 0; $i < 8; $i++) {
            $orden = app(ConfirmarOrden::class)(
                $this->crearOrden($evento, $establecimientos, $accesos, random_int(2, 10))
            );
            $pago = $this->cargarComprobante($orden);
            app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $equipo['contabilidad']);
            $aprobadas->push($orden->fresh());
        }

        // Vencidas: nunca pagaron.
        for ($i = 0; $i < 3; $i++) {
            $orden = app(ConfirmarOrden::class)(
                $this->crearOrden($evento, $establecimientos, $accesos, random_int(1, 4))
            );
            $orden->forceFill([
                'status' => OrderStatus::Vencida,
                'reserved_until' => now()->subDays(random_int(1, 10)),
            ])->save();
        }

        // Un tercio de los participantes aprobados ya pasó por la puerta.
        $tickets = $aprobadas->flatMap(fn (Order $o) => $o->tickets()->vigentes()->get());
        foreach ($tickets->shuffle()->take((int) floor($tickets->count() / 3)) as $ticket) {
            app(AcreditarParticipante::class)(
                $ticket->load('participant.accessType'),
                $equipo['acreditacion'],
                fake()->randomElement(['qr', 'qr', 'qr', 'manual']),
            );
        }

        $this->command->info(sprintf(
            'Demo lista: evento "%s", %d órdenes, %d participantes, %d credenciales, %d acreditados.',
            $evento->name,
            Order::count(),
            Participant::count(),
            Ticket::count(),
            Accreditation::count(),
        ));

        $this->command->line('Usuarios: '.collect($equipo)->map(
            fn (User $u) => $u->email
        )->implode(', ').' — clave: el mismo correo');
    }

    /** @return array<string, User> */
    private function crearEquipo(): array
    {
        $definiciones = [
            'admin' => ['Rodrigo Administrador', 'admin@test.cl', Rol::Administrador],
            'coordinacion' => ['Carmen Coordinación', 'coordinacion@test.cl', Rol::Coordinacion],
            'contabilidad' => ['Flor Contabilidad', 'contabilidad@test.cl', Rol::Contabilidad],
            'acreditacion' => ['Pablo Acreditación', 'acreditacion@test.cl', Rol::Acreditacion],
        ];

        $equipo = [];

        foreach ($definiciones as $clave => [$nombre, $correo, $rol]) {
            $usuario = User::firstOrCreate(
                ['email' => $correo],
                ['name' => $nombre, 'password' => $correo, 'is_active' => true],
            );

            if (! $usuario->hasRole($rol->value)) {
                $usuario->assignRole($rol->value);
            }

            $equipo[$clave] = $usuario->fresh();
        }

        return $equipo;
    }

    /**
     * @param  Collection<int, Establishment>  $establecimientos
     * @param  Collection<int, AccessType>  $accesos
     */
    private function crearOrden(Event $evento, $establecimientos, $accesos, int $participantes): Order
    {
        $orden = Order::factory()->create(['event_id' => $evento->id]);

        $delaOrden = $establecimientos->random(random_int(1, 2));
        $orden->establishments()->attach($delaOrden->pluck('id'));

        Participant::factory()->count($participantes)->create([
            'order_id' => $orden->id,
            'establishment_id' => fn () => $delaOrden->random()->id,
            'access_type_id' => fn () => $accesos->random()->id,
        ]);

        return $orden->fresh();
    }

    private function cargarComprobante(Order $orden, bool $exacto = true): Payment
    {
        return app(RegistrarComprobante::class)(
            $orden->fresh(),
            [
                'amount' => $exacto ? $orden->total : $orden->total - 10000,
                'paid_on' => now()->subDays(random_int(0, 5))->toDateString(),
                'bank_name' => fake()->randomElement(['BancoEstado', 'Banco de Chile', 'Santander', 'BCI']),
                'payer_name' => $orden->payerEntity?->name,
                'payer_rut' => $orden->payerEntity?->rut,
            ],
            UploadedFile::fake()->create('comprobante.pdf', 180, 'application/pdf'),
            actorLabel: $orden->responsible_email,
        );
    }
}
