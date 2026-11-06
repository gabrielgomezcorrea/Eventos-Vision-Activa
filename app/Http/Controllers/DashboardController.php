<?php

namespace App\Http\Controllers;

use App\Enums\EtapaDeSeguimiento;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permiso;
use App\Enums\VistaDeInscripciones;
use App\Models\Accreditation;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProgramRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que el equipo necesita saber al abrir el panel: qué está esperando a
 * alguien. No son métricas de vanidad, son colas de trabajo.
 *
 * Cada tarjeta va detrás del permiso de lo que muestra: si se ocultara el
 * bloque entero, Acreditación entraría a un Escritorio en blanco.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();
        $tarjetas = [];

        if ($usuario->can(Permiso::VerComprobantes->value)) {
            $enValidacion = Payment::pendientesDeRevision()->whereHas('order.event', fn (Builder $q) => $q->publicados())->count();

            $tarjetas[] = self::tarjeta('Comprobantes por revisar', $enValidacion, $enValidacion > 0 ? 'Esperando a Contabilidad' : 'Nada pendiente', $enValidacion > 0 ? 'warning' : 'gray', 'banknote', route('comprobantes.index'));
        }

        if ($usuario->can(Permiso::VerOrdenes->value)) {
            $porVencer = Order::query()
                ->whereHas('event', fn (Builder $q) => $q->publicados())
                ->where('status', OrderStatus::Reservada)
                ->whereNotIn('payment_status', [PaymentStatus::EnValidacion->value, PaymentStatus::Aprobado->value])
                ->whereBetween('reserved_until', [now(), now()->addHours(48)])
                ->count();

            $observados = Order::whereHas('event', fn (Builder $q) => $q->publicados())->where('payment_status', PaymentStatus::Observado)->count();

            $tarjetas[] = self::tarjeta('Reservas por vencer', $porVencer, 'En las próximas 48 horas', $porVencer > 0 ? 'danger' : 'gray', 'clock', route('inscripciones.index', ['vista' => VistaDeInscripciones::PorPagar->value]));
            $tarjetas[] = self::tarjeta('Pagos observados', $observados, 'Esperando respuesta del cliente', $observados > 0 ? 'warning' : 'gray', 'alert', route('inscripciones.index', ['vista' => VistaDeInscripciones::PorPagar->value]));
            $tarjetas[] = self::tarjeta('Inscripciones pagadas', Order::whereHas('event', fn (Builder $q) => $q->publicados())->where('payment_status', PaymentStatus::Aprobado)->count(), 'Con credenciales emitidas', 'success', 'check', route('inscripciones.index', ['vista' => VistaDeInscripciones::Pagadas->value]));
        }

        // Solicitudes tiene permiso propio: sin esto, Contabilidad veía una
        // tarjeta que la llevaba a una pantalla que no puede abrir.
        if ($usuario->can(Permiso::VerSolicitudes->value)) {
            $tarjetas[] = self::tarjeta('Solicitudes sin seguimiento', ProgramRequest::whereHas('event', fn (Builder $q) => $q->publicados())->enEtapa(EtapaDeSeguimiento::Solicito)->whereDoesntHave('notes')->count(), 'Nadie las ha contactado y no han llenado datos', 'info', 'inbox', route('solicitudes.index', ['etapa' => EtapaDeSeguimiento::Solicito->value, 'seguimiento' => 'sin']));
        }

        // Quien solo trabaja en la puerta no tiene colas de oficina: su
        // Escritorio es el avance de cada evento y el acceso al escáner.
        $soloPuerta = $usuario->can(Permiso::VerAcreditacion->value) && ! $usuario->can(Permiso::VerOrdenes->value);

        if ($usuario->can(Permiso::VerAcreditacion->value) && ! $soloPuerta) {
            $tarjetas[] = self::tarjeta('Acreditados', Accreditation::whereHas('event', fn (Builder $q) => $q->publicados())->count(), 'Retiraron su pulsera', 'success', 'qr', null);
        }

        return Inertia::render('dashboard', [
            'tarjetas' => $tarjetas,
            'puerta' => $soloPuerta ? self::puerta() : null,
            'actividad' => $usuario->can(Permiso::VerOrdenes->value) ? self::actividad() : null,
        ]);
    }

    /**
     * Inscripciones confirmadas y pagos aprobados por día, últimos 90 días.
     * Se agrupa en PHP y no con funciones de fecha del motor: SQLite y MySQL
     * no las escriben igual.
     *
     * @return array<int, array{fecha: string, inscripciones: int, pagadas: int}>
     */
    private static function actividad(): array
    {
        $desde = today()->subDays(89);
        $porDia = fn (Collection $fechas): Collection => $fechas->countBy(fn ($fecha): string => Carbon::parse($fecha)->format('Y-m-d'));

        $inscritas = $porDia(Order::whereHas('event', fn (Builder $q) => $q->publicados())->where('confirmed_at', '>=', $desde)->pluck('confirmed_at'));
        $pagadas = $porDia(Payment::whereHas('order.event', fn (Builder $q) => $q->publicados())->where('status', PaymentStatus::Aprobado)->where('reviewed_at', '>=', $desde)->pluck('reviewed_at'));

        return collect(range(0, 89))
            ->map(function (int $dias) use ($desde, $inscritas, $pagadas): array {
                $fecha = $desde->copy()->addDays($dias)->format('Y-m-d');

                return ['fecha' => $fecha, 'inscripciones' => (int) ($inscritas[$fecha] ?? 0), 'pagadas' => (int) ($pagadas[$fecha] ?? 0)];
            })
            ->all();
    }

    /**
     * Eventos por acreditar, del más próximo al más lejano, y lo último que
     * pasó en la puerta. Los esperados son las credenciales vigentes: solo
     * existen con el pago aprobado.
     *
     * @return array{eventos: array<int, array<string, mixed>>, ultimas: array<int, array<string, mixed>>}
     */
    private static function puerta(): array
    {
        $eventos = Event::query()
            ->publicados()
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhere('starts_on', '>=', today()))
            ->withCount([
                'tickets as esperados' => fn (Builder $q) => $q->whereNull('revoked_at'),
                'accreditations as acreditados',
            ])
            ->orderByRaw('starts_on is null')
            ->orderBy('starts_on')
            ->get()
            ->map(fn (Event $evento): array => [
                'id' => $evento->id,
                'nombre' => $evento->name,
                'fecha' => $evento->starts_on?->format('d-m-Y'),
                'es_hoy' => $evento->starts_on?->isToday() ?? false,
                'acreditados' => (int) $evento->getAttribute('acreditados'),
                'esperados' => (int) $evento->getAttribute('esperados'),
                'escaner' => route('acreditacion.inicio', ['event_id' => $evento->id]),
            ])
            ->all();

        $ultimas = Accreditation::query()
            ->with(['participant', 'event'])
            ->whereHas('event', fn (Builder $q) => $q->publicados())
            ->latest('accredited_at')
            ->limit(8)
            ->get()
            ->map(fn (Accreditation $a): array => [
                'id' => $a->id,
                'participante' => $a->participant?->nombre_completo,
                'evento' => $a->event?->name,
                'pulsera' => $a->wristband_label,
                'color' => $a->wristband_color,
                'hora' => $a->accredited_at->isToday() ? $a->accredited_at->format('H:i') : $a->accredited_at->format('d-m H:i'),
            ])
            ->all();

        return ['eventos' => $eventos, 'ultimas' => $ultimas];
    }

    /**
     * @return array{titulo: string, valor: int, detalle: string, tono: string, icono: string, href: string|null}
     */
    private static function tarjeta(string $titulo, int $valor, string $detalle, string $tono, string $icono, ?string $href): array
    {
        return compact('titulo', 'valor', 'detalle', 'tono', 'icono', 'href');
    }
}
