<?php

namespace App\Console\Commands;

use App\Enums\CertificateCampaignStatus;
use App\Enums\CertificateStatus;
use App\Jobs\EnviarCertificado;
use App\Models\Certificate;
use App\Models\CertificateCampaign;
use Illuminate\Console\Command;

/**
 * Releases certificates one at a time (scheduled every minute). Each campaign
 * sends at most one per turn, with a random gap between turns and a daily cap
 * shared by every event, because the limit belongs to the mail account.
 */
class EnviarCertificados extends Command
{
    protected $signature = 'certificados:enviar';

    protected $description = 'Envía el siguiente certificado de cada envío en curso, respetando el espaciado y el tope diario';

    public function handle(): int
    {
        $this->liberarAtascados();

        $campanas = CertificateCampaign::where('status', CertificateCampaignStatus::Enviando->value)->get();

        foreach ($campanas as $campana) {
            $siguiente = Certificate::where('event_id', $campana->event_id)
                ->where('status', CertificateStatus::EnCola->value)
                ->orderBy('queued_at')->orderBy('id')
                ->first();

            if ($siguiente === null) {
                $this->cerrarSiTermino($campana);

                continue;
            }

            if ($campana->next_send_at !== null && $campana->next_send_at->isFuture()) {
                continue;
            }

            if ($this->enviadosUltimas24Horas() >= (int) config('certificados.daily_limit')) {
                $this->line('Tope diario alcanzado: se sigue mañana.');

                return self::SUCCESS;
            }

            // Take the turn with a conditional UPDATE: two runs at once dispatch one job, not two.
            $proximo = now()->addSeconds(random_int(
                (int) config('certificados.gap_min_seconds'),
                max((int) config('certificados.gap_min_seconds'), (int) config('certificados.gap_max_seconds')),
            ));

            $turno = CertificateCampaign::whereKey($campana->id)
                ->where('status', CertificateCampaignStatus::Enviando->value)
                ->where(fn ($q) => $q->whereNull('next_send_at')->orWhere('next_send_at', '<=', now()))
                ->update(['next_send_at' => $proximo]);

            if ($turno === 1) {
                EnviarCertificado::dispatch($siguiente->id);
                $this->line("Certificado {$siguiente->id} en camino; el próximo a las {$proximo->format('H:i')}.");
            }
        }

        return self::SUCCESS;
    }

    private function enviadosUltimas24Horas(): int
    {
        return Certificate::where('status', CertificateStatus::Enviado->value)
            ->where('sent_at', '>=', now()->subDay())
            ->count();
    }

    /** The worker died mid-send: nobody knows if it left, so it is not retried on its own. */
    private function liberarAtascados(): void
    {
        Certificate::where('status', CertificateStatus::Enviando->value)
            ->where('sending_at', '<', now()->subMinutes((int) config('certificados.sending_timeout_minutes')))
            ->update([
                'status' => CertificateStatus::Fallido->value,
                'last_error' => 'No se confirmó el envío: puede que haya salido. Pregunta a la persona antes de reintentar.',
            ]);
    }

    private function cerrarSiTermino(CertificateCampaign $campana): void
    {
        $enCurso = Certificate::where('event_id', $campana->event_id)
            ->where('status', CertificateStatus::Enviando->value)
            ->exists();

        if (! $enCurso) {
            $campana->forceFill(['status' => CertificateCampaignStatus::Terminada, 'finished_at' => now()])->save();
        }
    }
}
