<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Database\Seeder;

/**
 * Evento limpio para recorrer a mano el portal del cliente (Ciclo 7): dos
 * jornadas con hora, dos accesos con cupos, datos bancarios, contacto y el
 * formulario público con sus campos por defecto. Sin inscripciones.
 * Idempotente: borra y recrea solo su propio evento.
 *
 *     php artisan db:seed --class=PortalV2Seeder
 *
 * No usar en producción. El banner no se siembra: se sube desde la ficha del
 * evento (Administración), igual que en producción.
 */
class PortalV2Seeder extends Seeder
{
    private const SLUG = 'prueba-portal-v2';

    public function run(): void
    {
        $this->call(CuentasDePruebaSeeder::class);

        Event::withTrashed()->where('slug', self::SLUG)->each(fn (Event $anterior) => $anterior->forceDelete());

        $evento = Event::create([
            'name' => 'Prueba portal V2',
            'slug' => self::SLUG,
            'status' => EventStatus::Publicado,
            'starts_on' => now()->addMonth()->toDateString(),
            'description' => 'Evento para recorrer a mano el portal del cliente.',
            'location' => 'Centro de Eventos Vision Activa',
            'city' => 'Santiago',
            'program_email_intro' => 'Gracias por tu interés. Aquí está el programa y tu enlace para inscribirte.',
            ...config('contacto_eventos'),
            'bank_holder_name' => 'Vision Activa SpA',
            'bank_holder_rut' => '76.543.210-K',
            'bank_name' => 'Banco de Chile',
            'bank_account_type' => 'Cuenta Corriente',
            'bank_account_number' => '00112233445',
            'bank_email' => 'pagos@visionactiva.cl',
            'reminder_hours_before' => 48,
        ]);

        $primera = $evento->sessions()->create([
            'name' => 'Jornada 1: Liderazgo directivo',
            'position' => 1,
            'capacity' => 40,
            'starts_at' => now()->addMonth()->setTime(9, 0),
            'ends_at' => now()->addMonth()->setTime(13, 0),
        ]);

        $segunda = $evento->sessions()->create([
            'name' => 'Jornada 2: Convivencia escolar',
            'position' => 2,
            'capacity' => 40,
            'starts_at' => now()->addMonth()->addDay()->setTime(9, 0),
            'ends_at' => now()->addMonth()->addDay()->setTime(13, 0),
        ]);

        $jornada1 = $evento->accessTypes()->create(['name' => 'Jornada 1', 'position' => 1, 'price' => 90000]);
        $jornada1->sessions()->attach($primera->id, ['seats' => 1]);

        $jornada2 = $evento->accessTypes()->create(['name' => 'Jornada 2', 'position' => 2, 'price' => 90000]);
        $jornada2->sessions()->attach($segunda->id, ['seats' => 1]);

        $ambas = $evento->accessTypes()->create([
            'name' => 'Ambas jornadas', 'position' => 3, 'price' => 150000,
            'wristband_label' => 'Pulsera azul', 'wristband_color' => '#084887',
        ]);
        $ambas->sessions()->attach([$primera->id => ['seats' => 1], $segunda->id => ['seats' => 1]]);

        $this->command->info('Evento "'.self::SLUG.'" listo: formulario en /f/'.self::SLUG);
    }
}
