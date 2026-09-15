<?php

/**
 * Confirma una orden desde un proceso independiente.
 *
 * Lo usa ConcurrenciaMysqlTest para provocar una carrera real por la última
 * vacante. Imprime "ok", "sin_cupo" o el error.
 */

use App\Actions\ConfirmarOrden;
use App\Exceptions\CuposInsuficientes;
use App\Models\Order;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => explode(':', getenv('MYSQL_TEST_DSN'))[0],
    'database.connections.mysql.port' => explode(':', getenv('MYSQL_TEST_DSN'))[1] ?? '3306',
    'database.connections.mysql.database' => getenv('MYSQL_TEST_DATABASE') ?: 'inscripciones_test',
    'database.connections.mysql.username' => getenv('MYSQL_TEST_USERNAME') ?: 'root',
    'database.connections.mysql.password' => getenv('MYSQL_TEST_PASSWORD') ?: '',
    'mail.default' => 'array',
    'queue.default' => 'null',
]);

try {
    $orden = Order::findOrFail((int) $argv[1]);
    app(ConfirmarOrden::class)($orden);
    echo 'ok';
} catch (CuposInsuficientes $e) {
    echo 'sin_cupo';
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage();
}
