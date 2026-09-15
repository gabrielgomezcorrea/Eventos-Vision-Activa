<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Restaura un respaldo de `respaldo:crear`: reemplaza la base de datos y los
 * archivos privados por los del respaldo.
 */
class RestaurarRespaldo extends Command
{
    protected $signature = 'respaldo:restaurar
        {archivo : Ruta del .tar.gz a restaurar}
        {--force : No pedir confirmación}';

    protected $description = 'Reemplaza la base de datos y los archivos privados por los de un respaldo';

    public function handle(): int
    {
        $archivo = $this->argument('archivo');

        if (! File::exists($archivo)) {
            $this->error("No existe el archivo {$archivo}.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Se reemplazarán la base de datos y los archivos actuales. ¿Continuar?')) {
            return self::FAILURE;
        }

        $trabajo = storage_path('app/restauracion-'.now()->format('Ymd-His'));
        File::ensureDirectoryExists($trabajo);

        try {
            Process::run(['tar', '-xzf', $archivo, '-C', $trabajo, '--strip-components=1'])->throw();

            $this->restaurarBase($trabajo);

            $archivos = storage_path('app/private');
            File::deleteDirectory($archivos);
            if (File::isDirectory($trabajo.'/archivos')) {
                File::moveDirectory($trabajo.'/archivos', $archivos);
            } else {
                File::ensureDirectoryExists($archivos);
            }
        } catch (RuntimeException $error) {
            $this->error('No se pudo restaurar: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($trabajo);
        }

        $this->info('Respaldo restaurado.');

        return self::SUCCESS;
    }

    private function restaurarBase(string $trabajo): void
    {
        $conexion = DB::connection();
        $config = $conexion->getConfig();

        match ($conexion->getDriverName()) {
            'sqlite' => $this->restaurarSqlite($trabajo.'/base.sqlite', $config['database']),
            'mysql', 'mariadb' => Process::env(['MYSQL_PWD' => (string) $config['password']])
                ->input(File::get($trabajo.'/base.sql'))
                ->timeout(0)
                ->run([
                    'mysql',
                    '--host='.$config['host'],
                    '--port='.$config['port'],
                    '--user='.$config['username'],
                    $config['database'],
                ])
                ->throw(),
            default => throw new RuntimeException('Motor de base de datos sin restauración: '.$conexion->getDriverName()),
        };
    }

    private function restaurarSqlite(string $respaldo, string $destino): void
    {
        if (! File::exists($respaldo)) {
            throw new RuntimeException('El respaldo no trae la base SQLite.');
        }

        DB::disconnect();
        File::copy($respaldo, $destino);
    }
}
