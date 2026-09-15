<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Respalda la base de datos y los archivos privados en un solo .tar.gz.
 *
 * Los dos van juntos a propósito: una base restaurada sin sus comprobantes
 * deja pagos que apuntan a archivos que no existen.
 */
class CrearRespaldo extends Command
{
    protected $signature = 'respaldo:crear';

    protected $description = 'Respalda la base de datos y los archivos privados';

    public function handle(): int
    {
        $carpeta = config('respaldos.carpeta');
        File::ensureDirectoryExists($carpeta);

        $nombre = 'respaldo-'.now()->format('Ymd-His');
        $trabajo = $carpeta.'/'.$nombre;
        File::ensureDirectoryExists($trabajo);

        try {
            $this->volcarBase($trabajo);

            $archivos = storage_path('app/private');
            if (File::isDirectory($archivos)) {
                File::copyDirectory($archivos, $trabajo.'/archivos');
            }

            Process::path($carpeta)->run(['tar', '-czf', $nombre.'.tar.gz', $nombre])->throw();
        } catch (RuntimeException $error) {
            $this->error('No se pudo crear el respaldo: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($trabajo);
        }

        $this->borrarAntiguos($carpeta);

        $this->info("Respaldo creado: {$carpeta}/{$nombre}.tar.gz");

        return self::SUCCESS;
    }

    private function volcarBase(string $destino): void
    {
        $conexion = DB::connection();

        match ($conexion->getDriverName()) {
            // VACUUM INTO deja una copia consistente aunque haya escrituras en curso.
            'sqlite' => $conexion->statement('VACUUM INTO ?', [$destino.'/base.sqlite']),
            'mysql', 'mariadb' => $this->volcarMysql($conexion->getConfig(), $destino.'/base.sql'),
            default => throw new RuntimeException('Motor de base de datos sin respaldo: '.$conexion->getDriverName()),
        };
    }

    /**
     * @param  array{host: string, port: string|int, database: string, username: string, password: ?string}  $config
     */
    private function volcarMysql(array $config, string $archivo): void
    {
        // La clave va por entorno y no como argumento: así no aparece en `ps`.
        Process::env(['MYSQL_PWD' => (string) $config['password']])
            ->run([
                'mysqldump',
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--user='.$config['username'],
                '--single-transaction',
                '--routines',
                '--no-tablespaces',
                '--result-file='.$archivo,
                $config['database'],
            ])
            ->throw();
    }

    private function borrarAntiguos(string $carpeta): void
    {
        collect(File::glob($carpeta.'/respaldo-*.tar.gz'))
            ->sort()
            ->reverse()
            ->slice(max(1, config('respaldos.conservar')))
            ->each(fn (string $archivo) => File::delete($archivo));
    }
}
