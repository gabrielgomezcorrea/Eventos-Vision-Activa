<?php

namespace App\Console\Commands;

use App\Actions\ImportarInscripcion;
use App\Exceptions\ImportacionDetenida;
use Illuminate\Console\Command;
use Throwable;

/**
 * Door for the local MCP: JSON on stdin, JSON result on stdout. The input is never
 * logged (it carries RUTs and the proof). See the Ciclo 16 spec.
 */
class ImportarInscripcionPorCorreo extends Command
{
    protected $signature = 'inscripciones:importar {--usuario= : Email of an active panel user who can create enrollments} {--simular : Run everything and roll it back}';

    protected $description = 'Imports an enrollment received by email (JSON on stdin) as a confirmed order with its proof';

    public function handle(ImportarInscripcion $importar): int
    {
        try {
            $entrada = app()->bound('inscripciones.entrada')
                ? app('inscripciones.entrada')
                : (string) file_get_contents('php://stdin');

            $datos = json_decode($entrada, true);

            if (! is_array($datos)) {
                throw ImportacionDetenida::porque('La entrada no es un JSON válido.');
            }

            $usuario = ImportarInscripcion::usuarioValido((string) $this->option('usuario'));

            return $this->emitir($importar($datos, $usuario, (bool) $this->option('simular')), self::SUCCESS);
        } catch (ImportacionDetenida $e) {
            return $this->emitir(['ok' => false, 'problems' => $e->problemas, 'warnings' => []], self::FAILURE);
        } catch (Throwable $e) {
            report($e);

            return $this->emitir(['ok' => false, 'problems' => ['Error inesperado: '.$e->getMessage()], 'warnings' => []], self::FAILURE);
        }
    }

    private function emitir(array $resultado, int $codigo): int
    {
        $this->line(json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $codigo;
    }
}
