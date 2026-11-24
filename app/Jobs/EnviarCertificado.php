<?php

namespace App\Jobs;

use App\Actions\IniciarEnvioDeCertificados;
use App\Enums\CertificateCampaignStatus;
use App\Enums\CertificateStatus;
use App\Mail\CertificadoDeParticipacion;
use App\Models\Certificate;
use App\Models\CertificateCampaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one certificate. Never twice: it first claims the row with a
 * conditional UPDATE (queued → sending) and does nothing if someone else
 * already did. It is never retried by the queue: a retry after a send that
 * worked but was not recorded would email the person twice.
 */
class EnviarCertificado implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $certificadoId) {}

    public function handle(): void
    {
        $reclamado = Certificate::query()
            ->whereKey($this->certificadoId)
            ->where('status', CertificateStatus::EnCola->value)
            ->update(['status' => CertificateStatus::Enviando->value, 'sending_at' => now()]);

        if ($reclamado === 0) {
            return;
        }

        $certificado = Certificate::with(['participant', 'event'])->findOrFail($this->certificadoId);
        $certificado->increment('attempts');

        if (! IniciarEnvioDeCertificados::tieneDestino($certificado)) {
            // Not the mail account's fault: does not count towards pausing.
            $this->fallo($certificado, $certificado->participant === null
                ? 'No tiene una persona asignada.'
                : 'La persona no tiene un correo válido. Corrígelo en su inscripción y vuelve a intentarlo.', false);

            return;
        }

        $correo = (string) $certificado->participant?->email;

        try {
            Mail::to($correo)->send(new CertificadoDeParticipacion($certificado));
        } catch (Throwable $e) {
            report($e);
            $this->fallo($certificado, 'El servidor de correo no lo aceptó: '.Str::limit($e->getMessage(), 300), true);

            return;
        }

        $certificado->forceFill([
            'status' => CertificateStatus::Enviado,
            'sent_at' => now(),
            'sent_to' => $correo,
            'last_error' => null,
        ])->save();

        CertificateCampaign::where('event_id', $certificado->event_id)->update(['consecutive_failures' => 0]);

        $this->cerrarSiFueElUltimo($certificado->event_id);
    }

    /** The last one out closes the sending right away, not on the next scheduler turn. */
    private function cerrarSiFueElUltimo(int $eventoId): void
    {
        $quedan = Certificate::where('event_id', $eventoId)
            ->whereIn('status', [CertificateStatus::EnCola->value, CertificateStatus::Enviando->value])
            ->exists();

        if (! $quedan) {
            CertificateCampaign::where('event_id', $eventoId)
                ->where('status', CertificateCampaignStatus::Enviando->value)
                ->update(['status' => CertificateCampaignStatus::Terminada->value, 'finished_at' => now()]);
        }
    }

    private function fallo(Certificate $certificado, string $motivo, bool $culpaDelCorreo): void
    {
        $certificado->forceFill(['status' => CertificateStatus::Fallido, 'last_error' => $motivo])->save();

        if (! $culpaDelCorreo) {
            $this->cerrarSiFueElUltimo($certificado->event_id);

            return;
        }

        $campana = CertificateCampaign::where('event_id', $certificado->event_id)->first();

        if ($campana === null) {
            return;
        }

        $campana->increment('consecutive_failures');

        // Several failures in a row: the account is being refused. Stop before it gets worse.
        if ($campana->consecutive_failures >= (int) config('certificados.failures_to_pause')) {
            CertificateCampaign::whereKey($campana->id)
                ->where('status', CertificateCampaignStatus::Enviando->value)
                ->update([
                    'status' => CertificateCampaignStatus::Pausada->value,
                    'paused_reason' => 'Se pausó solo: '.$campana->consecutive_failures.' envíos seguidos fallaron. Revisa la cuenta de correo antes de reanudar.',
                ]);
        }
    }
}
