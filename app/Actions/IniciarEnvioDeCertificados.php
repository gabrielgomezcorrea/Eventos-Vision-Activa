<?php

namespace App\Actions;

use App\Enums\CertificateCampaignStatus;
use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\CertificateCampaign;
use App\Models\Event;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Puts the event's ready certificates in line and starts (or resumes) the
 * spaced sending. Nothing is sent here: `certificados:enviar` releases them one
 * by one. Pressing it twice only queues what is ready, never twice the same.
 */
class IniciarEnvioDeCertificados
{
    /**
     * @param  array<int, int>|null  $soloEstos  certificate ids (resend one); null = every ready one
     */
    public function __invoke(Event $evento, ?User $usuario, ?array $soloEstos = null): int
    {
        return DB::transaction(function () use ($evento, $usuario, $soloEstos): int {
            $estados = $soloEstos === null
                ? [CertificateStatus::Listo->value]
                : [CertificateStatus::Listo->value, CertificateStatus::Fallido->value, CertificateStatus::Enviado->value];

            // Conditional UPDATE: a second click finds nothing ready and queues nothing.
            $enCola = $evento->certificates()
                ->whereIn('status', $estados)
                // Without an email it would only fail in its turn: it stays ready, marked, until fixed.
                ->whereHas('participant', fn ($q) => $q->where('email', 'like', '%_@_%._%'))
                ->when($soloEstos !== null, fn ($q) => $q->whereIn('id', $soloEstos))
                ->update(['status' => CertificateStatus::EnCola->value, 'queued_at' => now(), 'last_error' => null]);

            if ($enCola === 0) {
                return 0;
            }

            $campana = CertificateCampaign::query()->lockForUpdate()->firstOrNew(['event_id' => $evento->id]);
            $yaEnviando = $campana->exists && $campana->status === CertificateCampaignStatus::Enviando;

            $campana->fill([
                'status' => CertificateCampaignStatus::Enviando,
                'consecutive_failures' => 0,
                'paused_reason' => null,
                'finished_at' => null,
            ]);

            if (! $yaEnviando) {
                $campana->fill([
                    'next_send_at' => max(now(), $campana->next_send_at ?? now()),
                    'started_at' => now(),
                    'started_by_user_id' => $usuario?->id,
                ]);
            }

            $campana->save();

            Auditor::registrar(
                sobre: $evento,
                accion: $soloEstos === null ? 'certificados.envio_iniciado' : 'certificados.reenvio',
                propiedades: ['en_cola' => $enCola],
            );

            return $enCola;
        });
    }

    /** Pause keeps the line as it is; nothing queued is lost. */
    public function pausar(Event $evento, string $motivo): void
    {
        $evento->certificateCampaign()
            ->where('status', CertificateCampaignStatus::Enviando->value)
            ->update(['status' => CertificateCampaignStatus::Pausada->value, 'paused_reason' => $motivo]);

        Auditor::registrar(sobre: $evento, accion: 'certificados.envio_pausado', comentario: $motivo);
    }

    public function reanudar(Event $evento): bool
    {
        $hayEnCola = $evento->certificates()->where('status', CertificateStatus::EnCola->value)->exists();

        $reanudada = $hayEnCola && $evento->certificateCampaign()
            ->where('status', CertificateCampaignStatus::Pausada->value)
            ->update([
                'status' => CertificateCampaignStatus::Enviando->value,
                'paused_reason' => null,
                'consecutive_failures' => 0,
                'next_send_at' => now(),
            ]) === 1;

        if ($reanudada) {
            Auditor::registrar(sobre: $evento, accion: 'certificados.envio_reanudado');
        }

        return $reanudada;
    }

    /** Whether the certificate has somewhere to go: its person with a valid email. */
    public static function tieneDestino(Certificate $certificado): bool
    {
        return filter_var($certificado->participant?->email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
