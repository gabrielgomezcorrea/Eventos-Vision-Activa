<?php

namespace App\Http\Controllers\Eventos;

use App\Actions\IniciarEnvioDeCertificados;
use App\Actions\SubirCertificados;
use App\Enums\CertificateCampaignStatus;
use App\Enums\CertificateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eventos\CertificadosRequest;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Participant;
use App\Support\Auditor;
use App\Support\Certificados\EmparejadorDeCertificados;
use App\Support\Presentar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificates made outside the system (24/11/2026): upload, check who each one
 * belongs to, and send them spaced in time. Context in
 * docs/decisiones/11-24-2026-certificados.md.
 */
class CertificadoController extends Controller
{
    public function index(Event $event, EmparejadorDeCertificados $emparejador): Response
    {
        Gate::authorize('viewAny', Certificate::class);

        $certificados = $event->certificates()->with('participant.establishment')->orderBy('id')->get();
        $campana = $event->certificateCampaign;

        return Inertia::render('eventos/certificados', [
            'evento' => ['id' => $event->id, 'name' => $event->name],
            'certificados' => $certificados->map(fn (Certificate $c): array => [
                'id' => $c->id,
                'archivo' => $c->original_name,
                'nombre_leido' => $c->detected_name,
                'participant_id' => $c->participant_id,
                'persona' => $c->participant?->nombre_completo,
                'correo' => $c->participant?->email,
                'sin_correo' => $c->participant !== null && ! IniciarEnvioDeCertificados::tieneDestino($c),
                'dudoso' => $c->status === CertificateStatus::PorRevisar && $c->participant_id !== null,
                'estado' => Presentar::estado($c->status),
                'se_edita' => $c->status->seEdita(),
                'se_reenvia' => in_array($c->status, [CertificateStatus::Enviado, CertificateStatus::Fallido], true) && $c->participant_id !== null,
                'enviado' => $c->sent_at ? Presentar::fechaYHora($c->sent_at).' a '.$c->sent_to : null,
                'error' => $c->status === CertificateStatus::Fallido ? $c->last_error : null,
            ])->values()->all(),
            'personas' => $emparejador->candidatos($event)->map(fn (Participant $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre_completo,
                'detalle' => collect([$p->email, $p->establishment->name ?? $p->order->establishments->first()?->name])->filter()->implode(' · '),
            ])->values()->all(),
            'campana' => $campana === null ? null : [
                'estado' => Presentar::estado($campana->status),
                'enviando' => $campana->status === CertificateCampaignStatus::Enviando,
                'pausada' => $campana->status === CertificateCampaignStatus::Pausada,
                'motivo' => $campana->paused_reason,
                'proximo' => $campana->status === CertificateCampaignStatus::Enviando && $campana->next_send_at
                    ? ($campana->next_send_at->isFuture() ? $campana->next_send_at->format('H:i') : 'en un momento')
                    : null,
            ],
            'ritmo' => ['minimo' => (int) config('certificados.gap_min_seconds'), 'maximo' => (int) config('certificados.gap_max_seconds'), 'tope' => (int) config('certificados.daily_limit')],
            'subida' => ['por_vez' => (int) config('certificados.files_per_request'), 'max_mb' => (int) (config('certificados.max_file_kb') / 1024)],
        ]);
    }

    public function store(CertificadosRequest $request, Event $event, SubirCertificados $subir): RedirectResponse
    {
        $resultado = $subir($event, $request->file('certificados'), $request->user());

        // The screen uploads in batches and adds these up; the toast comes at the end.
        Inertia::flash('certificados', $resultado);

        return back();
    }

    public function asignar(Request $request, Event $event, Certificate $certificate, EmparejadorDeCertificados $emparejador): RedirectResponse
    {
        Gate::authorize('update', Certificate::class);

        $datos = $request->validate([
            'participant_id' => ['required', 'integer', Rule::in($emparejador->consultaDeCandidatos($event)->pluck('participants.id')->all())],
        ], ['participant_id.in' => 'Esa persona no está entre los asistentes de este evento.']);

        if (! $certificate->status->seEdita()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Ese certificado ya está en el envío: no se puede cambiar de persona.']);

            return back();
        }

        $anterior = $certificate->participant?->nombre_completo;

        $certificate->forceFill([
            'participant_id' => $datos['participant_id'],
            'status' => CertificateStatus::Listo,
            'last_error' => null,
        ])->save();

        $certificate->load('participant');

        Auditor::registrar(
            sobre: $certificate,
            accion: 'certificado.asignado',
            propiedades: ['antes' => $anterior, 'ahora' => $certificate->participant?->nombre_completo, 'archivo' => $certificate->original_name],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certificado asignado a '.$certificate->participant?->nombre_completo.'.']);

        return back();
    }

    public function destroy(Event $event, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('delete', Certificate::class);

        if (! $certificate->status->seEdita()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Ese certificado ya está en el envío o fue enviado: no se quita.']);

            return back();
        }

        Storage::disk($certificate->disk)->delete($certificate->path);
        $certificate->delete();

        Auditor::registrar(sobre: $event, accion: 'certificado.quitado', propiedades: ['archivo' => $certificate->original_name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certificado quitado.']);

        return back();
    }

    public function enviar(Request $request, Event $event, IniciarEnvioDeCertificados $iniciar): RedirectResponse
    {
        Gate::authorize('update', Certificate::class);

        $enCola = $iniciar($event, $request->user());

        Inertia::flash('toast', $enCola === 0
            ? ['type' => 'error', 'message' => 'No hay certificados listos para enviar.']
            : ['type' => 'success', 'message' => ($enCola === 1 ? '1 certificado quedó' : "{$enCola} certificados quedaron").' en el envío. Salen de a uno, espaciados.']);

        return back();
    }

    public function reenviar(Request $request, Event $event, Certificate $certificate, IniciarEnvioDeCertificados $iniciar): RedirectResponse
    {
        Gate::authorize('update', Certificate::class);

        $enCola = $iniciar($event, $request->user(), [$certificate->id]);

        Inertia::flash('toast', $enCola === 0
            ? ['type' => 'error', 'message' => 'Ese certificado no se puede volver a enviar ahora.']
            : ['type' => 'success', 'message' => 'Quedó en el envío: sale en su turno.']);

        return back();
    }

    public function pausar(Event $event, IniciarEnvioDeCertificados $iniciar): RedirectResponse
    {
        Gate::authorize('update', Certificate::class);

        $iniciar->pausar($event, 'Pausado por '.request()->user()?->name.'.');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Envío pausado. Lo que faltaba queda esperando.']);

        return back();
    }

    public function reanudar(Event $event, IniciarEnvioDeCertificados $iniciar): RedirectResponse
    {
        Gate::authorize('update', Certificate::class);

        Inertia::flash('toast', $iniciar->reanudar($event)
            ? ['type' => 'success', 'message' => 'Envío reanudado.']
            : ['type' => 'error', 'message' => 'No hay nada pendiente que reanudar.']);

        return back();
    }

    /** Shown inside the screen to check it before sending. */
    public function archivo(Event $event, Certificate $certificate): StreamedResponse
    {
        Gate::authorize('viewAny', Certificate::class);
        abort_unless(Storage::disk($certificate->disk)->exists($certificate->path), 404);

        return Storage::disk($certificate->disk)->response($certificate->path, $certificate->original_name, [
            'Content-Type' => 'application/pdf',
        ], 'inline');
    }
}
