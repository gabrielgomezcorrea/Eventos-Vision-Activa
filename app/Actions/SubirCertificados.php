<?php

namespace App\Actions;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\User;
use App\Support\Auditor;
use App\Support\Certificados\EmparejadorDeCertificados;
use App\Support\Certificados\LectorDeCertificados;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores certificate PDFs made outside the system and matches each one to a
 * participant. Idempotent: the same file uploaded again is skipped, so a batch
 * resent after a dropped connection never duplicates anything.
 */
class SubirCertificados
{
    public function __construct(
        private LectorDeCertificados $lector,
        private EmparejadorDeCertificados $emparejador,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $archivos
     * @return array{subidos: int, asignados: int, repetidos: int}
     */
    public function __invoke(Event $evento, array $archivos, ?User $usuario): array
    {
        $disco = (string) config('filesystems.private_disk');
        $nuevos = [];
        $repetidos = 0;

        foreach ($archivos as $i => $archivo) {
            $hash = hash_file('sha256', $archivo->getRealPath());

            if ($evento->certificates()->where('file_hash', $hash)->exists() || in_array($hash, array_column($nuevos, 'hash'), true)) {
                $repetidos++;

                continue;
            }

            $nuevos[$i] = ['archivo' => $archivo, 'hash' => $hash, 'texto' => $this->lector->texto($archivo->getRealPath())];
        }

        $previos = $evento->certificates()->whereNotNull('pdf_text')->latest('id')->limit(50)->pluck('pdf_text')->all();
        $nombres = $this->lector->nombres(array_map(fn (array $n): ?string => $n['texto'], $nuevos), $previos);

        $candidatos = $this->emparejador->candidatos($evento);
        // People who already have a certificate here: a second one goes to review, never straight out.
        $conCertificado = $evento->certificates()->whereNotNull('participant_id')->pluck('participant_id')->all();

        $subidos = 0;
        $asignados = 0;

        foreach ($nuevos as $i => $nuevo) {
            /** @var UploadedFile $archivo */
            $archivo = $nuevo['archivo'];
            $nombre = $nombres[$i] ?? $this->lector->nombreDesdeArchivo($archivo->getClientOriginalName());
            $pareja = $this->emparejador->emparejar($nombre, $candidatos);
            $participante = $pareja['participante'];
            $seguro = $pareja['seguro'] && ! in_array($participante?->id, $conCertificado, true);

            $ruta = $archivo->storeAs("certificados/{$evento->id}", Str::ulid().'.pdf', $disco);

            if ($ruta === false) {
                throw new RuntimeException('No se pudo guardar el certificado '.$archivo->getClientOriginalName().'.');
            }

            try {
                Certificate::create([
                    'event_id' => $evento->id,
                    'participant_id' => $participante?->id,
                    'disk' => $disco,
                    'path' => $ruta,
                    'original_name' => Str::limit($archivo->getClientOriginalName(), 250, ''),
                    'file_hash' => $nuevo['hash'],
                    'pdf_text' => $nuevo['texto'],
                    'detected_name' => $nombre === null ? null : Str::limit($nombre, 250, ''),
                    'match_score' => $pareja['puntaje'],
                    'status' => $seguro ? CertificateStatus::Listo : CertificateStatus::PorRevisar,
                    'uploaded_by_user_id' => $usuario?->id,
                ]);
            } catch (QueryException) {
                // Same file uploaded at the same moment from another tab: the unique index already holds it.
                Storage::disk($disco)->delete($ruta);
                $repetidos++;

                continue;
            }

            if ($participante !== null) {
                $conCertificado[] = $participante->id;
            }

            $subidos++;
            $asignados += $seguro ? 1 : 0;
        }

        if ($subidos > 0) {
            Auditor::registrar(
                sobre: $evento,
                accion: 'certificados.subidos',
                propiedades: ['subidos' => $subidos, 'asignados' => $asignados, 'repetidos' => $repetidos],
            );
        }

        return ['subidos' => $subidos, 'asignados' => $asignados, 'repetidos' => $repetidos];
    }
}
