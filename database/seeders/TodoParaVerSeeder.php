<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Llena la base local con todo lo necesario para recorrer el sistema: el demo
 * con volumen, los casos de prueba por estado, el caso del código olvidado,
 * solicitudes en cada estado y eventos borrador y cerrado (que no deben salir
 * en el Escritorio ni en Acreditación).
 *
 *     php artisan db:seed --class=TodoParaVerSeeder
 *
 * Idempotente. Solo local: no usar en producción.
 */
class TodoParaVerSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([DemoSeeder::class, CasosDePruebaSeeder::class, CodigoOlvidadoSeeder::class]);

        Event::withTrashed()->whereIn('slug', ['ver-borrador', 'ver-cerrado'])->each(fn (Event $e) => $e->forceDelete());
        $borrador = Event::create(['name' => 'Evento en borrador', 'slug' => 'ver-borrador', 'status' => EventStatus::Borrador]);
        Event::create(['name' => 'Evento cerrado', 'slug' => 'ver-cerrado', 'status' => EventStatus::Cerrado]);

        $evento = Event::where('slug', 'casos-de-prueba')->firstOrFail();
        $autor = User::where('email', 'coordinacion@test.cl')->first();

        // Una solicitud por estado: Nueva (sin programa), Programa enviado, Contactada.
        ProgramRequest::where('email', 'like', '%@solicitud-demo.cl')->delete();
        $nueva = ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'Nora', 'last_name' => 'Nueva', 'email' => 'nora@solicitud-demo.cl', 'consented_at' => now()]);
        $enviada = ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'Pedro', 'last_name' => 'Programa', 'email' => 'pedro@solicitud-demo.cl', 'consented_at' => now(), 'program_sent_at' => now()]);
        $contactada = ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'Carla', 'last_name' => 'Contactada', 'email' => 'carla@solicitud-demo.cl', 'consented_at' => now(), 'program_sent_at' => now()->subDay()]);
        $contactada->notes()->create(['body' => 'Llamé, quedó de confirmar.', 'user_id' => $autor?->id]);
        ProgramRequest::create(['event_id' => $borrador->id, 'first_name' => 'Bruno', 'last_name' => 'Borrador', 'email' => 'bruno@solicitud-demo.cl', 'consented_at' => now()]);

        $this->command->info('Todo listo para ver: demo, casos de prueba, código olvidado, solicitudes en cada estado y eventos borrador/cerrado.');
    }
}
