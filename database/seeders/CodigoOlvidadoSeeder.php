<?php

namespace Database\Seeders;

use App\Actions\ConfirmarOrden;
use App\Actions\RegistrarComprobante;
use App\Actions\RevisarPago;
use App\Enums\EventStatus;
use App\Enums\PaymentReviewAction;
use App\Models\DiscountCode;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\PayerEntity;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;

/**
 * Reproduce el caso SI-2026-0012: el cliente olvidó el código «Por jornada»,
 * confirmó a precio normal ($360.000) y Contabilidad ya aprobó un abono de
 * $280.000 (el precio con el código). Sirve para probar «Aplicar código».
 *
 *     php artisan db:seed --class=CodigoOlvidadoSeeder
 *
 * Idempotente. Solo local: no usar en producción.
 */
class CodigoOlvidadoSeeder extends Seeder
{
    private const SLUG = 'caso-codigo-olvidado';

    public function run(): void
    {
        Mail::fake();

        $this->call(CuentasDePruebaSeeder::class);
        $contabilidad = User::where('email', 'contabilidad@test.cl')->firstOrFail();

        Event::withTrashed()->where('slug', self::SLUG)->each(fn (Event $e) => $e->forceDelete());

        $evento = Event::create([
            'name' => 'Seminario Liderazgo (código olvidado)',
            'slug' => self::SLUG,
            'status' => EventStatus::Publicado,
            'starts_on' => now()->addMonth()->toDateString(),
            ...config('contacto_eventos'),
            'bank_holder_name' => 'Vision Activa SpA',
            'bank_holder_rut' => '76.543.210-K',
            'bank_name' => 'Banco de Chile',
            'bank_account_type' => 'Cuenta Corriente',
            'bank_account_number' => '00112233445',
            'bank_email' => 'pagos@visionactiva.cl',
        ]);

        $j1 = $evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 100, 'starts_at' => now()->addMonth()->setTime(9, 0), 'ends_at' => now()->addMonth()->setTime(18, 0)]);
        $j2 = $evento->sessions()->create(['name' => 'Jornada 2', 'position' => 2, 'capacity' => 100, 'starts_at' => now()->addMonth()->addDay()->setTime(9, 0), 'ends_at' => now()->addMonth()->addDay()->setTime(18, 0)]);
        $ambas = $evento->accessTypes()->create(['name' => 'Ambas Jornadas', 'position' => 1, 'price' => 180000]);
        $ambas->sessions()->attach([$j1->id => ['seats' => 1], $j2->id => ['seats' => 1]]);

        $codigo = DiscountCode::create([
            'event_id' => $evento->id, 'code' => 'CMEI2026', 'type' => 'per_access', 'value' => 0,
            'max_people' => 50, 'expires_at' => now()->addMonth()->endOfDay(),
        ]);
        $codigo->preciosPorAcceso()->attach($ambas->id, ['price' => 140000]);

        $colegio = Establishment::factory()->create(['name' => 'Colegio Ignacio Carrera Pinto']);
        $orden = $evento->orders()->create([
            'responsible_name' => 'David Miguel', 'responsible_lastname' => 'Caceres Verdugo',
            'responsible_email' => 'david@test.cl', 'responsible_phone' => '56999593735',
            'payer_entity_id' => PayerEntity::factory()->create(['name' => 'David Miguel Caceres Verdugo'])->id,
        ]);
        $orden->establishments()->attach($colegio->id);
        foreach ([['Loreto', 'Del Campo Pineda'], ['Juana', 'Oporto']] as [$nombre, $apellido]) {
            $orden->participants()->create(['first_name' => $nombre, 'last_name' => $apellido, 'access_type_id' => $ambas->id, 'establishment_id' => $colegio->id]);
        }
        $orden = app(ConfirmarOrden::class)($orden->fresh());

        $pago = app(RegistrarComprobante::class)(
            $orden->fresh(),
            ['amount' => 280000, 'paid_on' => now()->toDateString()],
            UploadedFile::fake()->create('comprobante.pdf', 120, 'application/pdf'),
            actorLabel: $orden->responsible_email,
        );
        app(RevisarPago::class)($pago, PaymentReviewAction::Aprobar, $contabilidad, 'Monto abonado y verificado.');

        $orden = $orden->fresh();
        $this->command->info("Listo: {$orden->number} · total \${$orden->total} · pagado \${$orden->pagado()} · {$orden->payment_status->label()}.");
        $this->command->line('  Entra como coordinacion@test.cl y aplica el código CMEI2026.');
    }
}
