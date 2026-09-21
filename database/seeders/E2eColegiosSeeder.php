<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Database\Seeder;

/**
 * Un evento mínimo para el recorrido de Playwright en local (Fase 6 del
 * ROADMAP): varios colegios en un mismo conjunto, cada uno con su
 * comprobante y su factura. Idempotente: borra y recrea su propio evento.
 *
 *     php artisan db:seed --class=E2eColegiosSeeder
 *
 * No usar en producción.
 */
class E2eColegiosSeeder extends Seeder
{
    private const SLUG = 'e2e-colegios';

    public function run(): void
    {
        $this->call(CuentasDePruebaSeeder::class);

        Event::withTrashed()->where('slug', self::SLUG)->each(fn (Event $anterior) => $anterior->forceDelete());

        $evento = Event::create([
            'name' => 'E2E Playwright - Varios colegios',
            'slug' => self::SLUG,
            'status' => EventStatus::Publicado,
            'starts_on' => now()->addMonth()->toDateString(),
        ]);

        $evento->accessTypes()->create(['name' => 'Jornada única', 'position' => 1, 'price' => 90000]);

        $this->command->info('Evento "'.self::SLUG.'" listo en /i/'.self::SLUG);
    }
}
