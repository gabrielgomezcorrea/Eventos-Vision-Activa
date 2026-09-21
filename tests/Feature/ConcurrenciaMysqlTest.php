<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\PayerEntity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Sobreventa con procesos realmente concurrentes contra MySQL.
 *
 * SQLite serializa las escrituras, así que no puede demostrar que dos clientes
 * simultáneos no sobrevendan la última vacante. Esta prueba lanza procesos PHP
 * en paralelo contra una base MySQL real.
 *
 * Se omite si no hay MySQL configurado. Para correrla:
 *   MYSQL_TEST_DSN=127.0.0.1:3399 php artisan test --group=mysql
 */
#[Group('mysql')]
class ConcurrenciaMysqlTest extends TestCase
{
    private const CAPACIDAD = 3;

    private const INTENTOS = 8;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $dsn = env('MYSQL_TEST_DSN')) {
            $this->markTestSkipped('Define MYSQL_TEST_DSN=host:puerto para correr la prueba de concurrencia real.');
        }

        [$host, $puerto] = array_pad(explode(':', $dsn), 2, '3306');

        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', array_merge(
            config('database.connections.mysql'),
            [
                'host' => $host,
                'port' => $puerto,
                'database' => env('MYSQL_TEST_DATABASE', 'inscripciones_test'),
                'username' => env('MYSQL_TEST_USERNAME', 'root'),
                'password' => env('MYSQL_TEST_PASSWORD', ''),
            ],
        ));

        DB::purge('mysql');
        Artisan::call('migrate:fresh', ['--force' => true]);
        Mail::fake();
    }

    public function test_confirmaciones_en_paralelo_no_sobrevenden_los_cupos(): void
    {
        $event = Event::create([
            'name' => 'Seminario', 'slug' => 'seminario', 'status' => EventStatus::Publicado,
        ]);
        $jornada = $event->sessions()->create(['name' => 'J1', 'position' => 1, 'capacity' => self::CAPACIDAD]);
        $acceso = $event->accessTypes()->create(['name' => 'J1', 'position' => 1, 'price' => 1000]);
        $acceso->sessions()->sync([$jornada->id => ['seats' => 1]]);

        // Se preparan todos los borradores antes de lanzar la carrera.
        $ids = [];
        for ($i = 0; $i < self::INTENTOS; $i++) {
            $orden = Order::create([
                'event_id' => $event->id,
                'responsible_name' => 'Cliente '.$i,
                'responsible_lastname' => 'Apellido '.$i,
                'responsible_email' => "cliente{$i}@colegio.cl",
                'payer_entity_id' => PayerEntity::create(['name' => 'Entidad '.$i])->id,
            ]);
            $orden->participants()->create(['first_name' => 'P'.$i, 'access_type_id' => $acceso->id]);
            $ids[] = $orden->id;
        }

        $resultados = $this->confirmarEnParalelo($ids);

        $confirmadas = count(array_filter($resultados, fn (string $r): bool => $r === 'ok'));
        $rechazadas = count(array_filter($resultados, fn (string $r): bool => $r === 'sin_cupo'));
        $errores = array_filter($resultados, fn (string $r): bool => ! in_array($r, ['ok', 'sin_cupo'], true));

        $this->assertSame([], $errores, 'Ningun proceso debio fallar por otra razon: '.implode(' | ', $errores));

        $this->assertSame(self::CAPACIDAD, $confirmadas, 'Se confirmaron mas ordenes que la capacidad.');
        $this->assertSame(self::INTENTOS - self::CAPACIDAD, $rechazadas);

        $this->assertSame(self::CAPACIDAD, (int) $jornada->fresh()->reserved_seats);
        $this->assertSame(self::CAPACIDAD, Order::where('status', OrderStatus::Reservada)->count());

        // Y los folios asignados bajo carrera no se repiten.
        $folios = Order::whereNotNull('number')->pluck('number');
        $this->assertCount(self::CAPACIDAD, $folios);
        $this->assertSame($folios->count(), $folios->unique()->count(), 'Hay folios repetidos.');
    }

    /**
     * Lanza un proceso PHP por orden, todos apuntando a la misma base.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function confirmarEnParalelo(array $ids): array
    {
        $script = base_path('tests/scripts/confirmar-orden.php');
        $procesos = [];

        foreach ($ids as $id) {
            $cmd = sprintf(
                'MYSQL_TEST_DSN=%s MYSQL_TEST_DATABASE=%s php %s %d 2>&1',
                escapeshellarg(env('MYSQL_TEST_DSN')),
                escapeshellarg(env('MYSQL_TEST_DATABASE', 'inscripciones_test')),
                escapeshellarg($script),
                $id,
            );

            $procesos[$id] = popen($cmd, 'r');
        }

        $resultados = [];
        foreach ($procesos as $id => $proceso) {
            $resultados[$id] = trim((string) stream_get_contents($proceso));
            pclose($proceso);
        }

        return $resultados;
    }
}
