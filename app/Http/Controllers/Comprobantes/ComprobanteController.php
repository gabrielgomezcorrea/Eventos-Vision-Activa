<?php

namespace App\Http\Controllers\Comprobantes;

use App\Actions\RevisarPago;
use App\Enums\PaymentReviewAction;
use App\Enums\PaymentStatus;
use App\Exceptions\RevisionNoValida;
use App\Http\Controllers\Controller;
use App\Http\Requests\Comprobantes\RevisarComprobanteRequest;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PaymentReview;
use App\Support\Presentar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La cola de trabajo de Contabilidad. Aprobar, observar y rechazar viven solo
 * en la ficha: decidir sobre un pago sin haber visto el documento es justo lo
 * que no queremos que pase por tener el botón a mano en la fila.
 */
class ComprobanteController extends Controller
{
    /** Sin filtro elegido, se abre en lo que espera a alguien. */
    private const TODOS = 'todos';

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payment::class);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in([self::TODOS, ...array_column(PaymentStatus::cases(), 'value')])],
            'evento' => ['nullable', 'integer'],
            'diferencia' => ['nullable', 'boolean'],
        ]);

        $estado = $filtros['estado'] ?? PaymentStatus::EnValidacion->value;

        $pagos = Payment::query()
            ->with('order.event')
            ->when($estado !== self::TODOS, fn (Builder $q) => $q->where('status', $estado))
            ->when($filtros['evento'] ?? null, fn (Builder $q, int $evento) => $q->whereIn(
                'order_id',
                Order::query()->where('event_id', $evento)->select('id'),
            ))
            ->when($filtros['diferencia'] ?? false, fn (Builder $q) => $q->whereHas(
                'order',
                fn (Builder $o) => $o->whereColumn('orders.total', '!=', 'payments.amount'),
            ))
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $buscar) => $q->whereHas(
                'order',
                fn (Builder $o) => $o->where('number', 'like', "%{$buscar}%")
                    ->orWhere('responsible_name', 'like', "%{$buscar}%")
                    ->orWhere('responsible_lastname', 'like', "%{$buscar}%"),
            ))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Payment $pago): array => [
                'id' => $pago->id,
                'numero' => $pago->order->number,
                'responsable' => $pago->order->responsableNombreCompleto(),
                'evento' => $pago->order->event?->name,
                'monto' => $pago->amount,
                'diferencia' => $pago->tieneDiferenciaDeMonto() ? $pago->diferencia() : null,
                'transferido' => $pago->paid_on?->format('d-m-Y'),
                'estado' => Presentar::estado($pago->status),
                'recibido' => $pago->created_at->format('d-m-Y H:i'),
                'esperando' => $pago->esperaRevision()
                    ? 'Esperando hace '.$pago->created_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)
                    : null,
            ]);

        return Inertia::render('comprobantes/index', [
            'pagos' => $pagos,
            'filtros' => [
                'buscar' => $filtros['buscar'] ?? null,
                'estado' => $estado,
                'evento' => $filtros['evento'] ?? null,
                'diferencia' => (bool) ($filtros['diferencia'] ?? false),
            ],
            'estados' => collect(PaymentStatus::cases())
                ->mapWithKeys(fn (PaymentStatus $s): array => [$s->value => $s->label()])
                ->all(),
            // Cuántos hay en cada estado, para ver de un vistazo qué falta sin
            // tener que ir probando filtros uno por uno.
            'resumen' => collect(PaymentStatus::cases())
                ->mapWithKeys(fn (PaymentStatus $s): array => [
                    $s->value => Payment::where('status', $s)->count(),
                ])
                ->all(),
            'eventos' => Event::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(Request $request, Payment $payment): Response
    {
        Gate::authorize('view', $payment);

        $payment->load(['order.event', 'order.payerEntity', 'proofs', 'reviews']);
        $orden = $payment->order;

        return Inertia::render('comprobantes/show', [
            'pago' => [
                'id' => $payment->id,
                'estado' => Presentar::estado($payment->status),
                'monto' => $payment->amount,
                'diferencia' => $payment->tieneDiferenciaDeMonto() ? $payment->diferencia() : null,
                'transferido' => $payment->paid_on?->format('d-m-Y'),
                'banco' => $payment->bank_name,
                'pagador' => $payment->payer_name,
                'rut_pagador' => $payment->payer_rut,
                'referencia' => $payment->reference,
                'notas' => $payment->notes,
                'informado_por' => $payment->submitted_by_label,
                'cargado_internamente' => $payment->submitted_by_user_id !== null,
            ],
            'orden' => [
                'id' => $orden->id,
                'numero' => $orden->number,
                'responsable' => $orden->responsableNombreCompleto(),
                'correo' => $orden->responsible_email,
                'evento' => $orden->event?->name,
                'entidad' => $orden->payerEntity?->name,
                'rut_entidad' => $orden->payerEntity?->rut,
                'total' => $orden->total,
                'participantes' => $orden->participantesVigentes()->count(),
                'estado' => Presentar::estado($orden->status),
                'reserva_vence' => $orden->reserved_until?->format('d-m-Y H:i'),
            ],
            // El que se revisa es el último; los anteriores quedan como historial.
            'comprobantes' => $payment->proofs
                ->sortByDesc('id')
                ->map(fn (PaymentProof $archivo): array => [
                    'id' => $archivo->id,
                    'nombre' => $archivo->original_name,
                    'tamano' => $archivo->tamanoLegible(),
                    'fecha' => $archivo->created_at?->format('d-m-Y H:i'),
                    'tipo' => match (true) {
                        $archivo->esImagen() => 'imagen',
                        $archivo->mime_type === 'application/pdf' => 'pdf',
                        default => 'otro',
                    },
                    'descargar' => route('comprobantes.descargar', $archivo),
                    'ver' => route('comprobantes.descargar', [$archivo, 'ver' => 1]),
                ])
                ->values()
                ->all(),
            'historial' => $payment->reviews
                ->map(fn (PaymentReview $revision): array => [
                    'id' => $revision->id,
                    'decision' => Presentar::estado($revision->action),
                    'usuario' => $revision->user_label,
                    'fecha' => $revision->created_at->format('d-m-Y H:i'),
                    'comentario' => $revision->comment,
                ])
                ->all(),
            'puedeRevisar' => $request->user()->can('update', $payment) && $payment->esperaRevision(),
            'decisiones' => [
                ['value' => PaymentReviewAction::Aprobar->value, 'label' => 'Aprobar el pago', 'descripcion' => 'El monto está abonado y corresponde a esta inscripción. Se liberan las credenciales.'],
                ['value' => PaymentReviewAction::Observar->value, 'label' => 'Pedir que lo corrija', 'descripcion' => 'Algo falta o no cuadra. El cliente recibe tu observación y puede enviar otro comprobante. Los cupos siguen reservados.'],
                ['value' => PaymentReviewAction::Rechazar->value, 'label' => 'Rechazarlo', 'descripcion' => 'El documento no acredita el pago.'],
            ],
        ]);
    }

    public function revisar(RevisarComprobanteRequest $request, Payment $payment, RevisarPago $revisarPago): RedirectResponse
    {
        $decision = $request->decision();

        try {
            $revisarPago($payment, $decision, $request->user(), $request->validated('comment'));
        } catch (RevisionNoValida $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Comprobante '.mb_strtolower($decision->label()).'. Se notificó al responsable de la inscripción.',
        ]);

        return back();
    }
}
