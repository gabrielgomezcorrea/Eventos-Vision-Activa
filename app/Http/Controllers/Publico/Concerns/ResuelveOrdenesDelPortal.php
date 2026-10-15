<?php

namespace App\Http\Controllers\Publico\Concerns;

use App\Actions\ConfirmarOrden;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Exceptions\EnlaceNoUtilizable;
use App\Models\Establishment;
use App\Models\MagicLink;
use App\Models\Order;
use App\Support\ReglasDeContacto;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;

/**
 * Lo que comparten el portal del cliente y las acciones que cuelgan de él
 * (subir un comprobante, pedir otro establecimiento): resolver la orden desde
 * el token y elegir el colegio del conjunto que se está mirando.
 */
trait ResuelveOrdenesDelPortal
{
    private const RELACIONES_DEL_PORTAL = [
        'event', 'establishments', 'participants.accessType', 'participants.establishment',
        'payerEntity', 'payments.proofs', 'payments.reviews',
    ];

    /**
     * Un enlace de orden resuelve directo; uno de conjunto no apunta a
     * ninguna en particular, así que se toma la más antigua como referencia
     * (el portal igual junta a todas sus hermanas). No exige que la orden sea
     * editable: el cliente debe poder consultarla siempre.
     */
    private function resolverParaConsulta(string $token): Order
    {
        $link = MagicLink::resolver($token);

        if ($link === null || ($link->order === null && $link->order_group_id === null)) {
            throw EnlaceNoUtilizable::vencido(MagicLink::porToken($token)?->event);
        }

        $orden = $link->order ?? $link->group->orders()->oldest('id')->firstOrFail();

        return $orden->load(self::RELACIONES_DEL_PORTAL);
    }

    /**
     * Colegio que se está mirando, elegido con `?colegio=` desde el menú
     * lateral. A diferencia de las acciones, aquí un colegio ajeno no corta
     * con 403: se vuelve al del token. Es un selector de vista, no una acción,
     * y dejar a alguien fuera de su propia pantalla por un parámetro mal
     * copiado es peor que ignorarlo. Lo que protege el dato sigue siendo lo
     * mismo: solo se muestran colegios del conjunto del token.
     */
    private function colegioElegido(Order $delToken, Request $request): Order
    {
        $elegido = $request->integer('colegio');

        if ($elegido === 0) {
            return $delToken;
        }

        $orden = $delToken->group?->load('orders.establishments')->orders
            ->first(fn (Order $o) => $o->establishments->contains('id', $elegido));

        return $orden?->load(self::RELACIONES_DEL_PORTAL) ?? $delToken;
    }

    /**
     * Orden objetivo de una acción dentro del conjunto: la del token si no se
     * indica colegio, o la del colegio indicado si pertenece al mismo conjunto
     * que el token. El colegio nunca autoriza por sí solo: siempre se verifica
     * contra el conjunto ya resuelto desde el token, nunca contra un id de
     * orden aceptado directamente de la URL.
     */
    private function ordenDelConjunto(Order $delToken, ?Establishment $establishment): Order
    {
        if ($establishment === null) {
            return $delToken;
        }

        $orden = $delToken->group?->load('orders.establishments')->orders
            ->first(fn (Order $o) => $o->establishments->contains($establishment));

        abort_if($orden === null, 403);

        return $orden->load(self::RELACIONES_DEL_PORTAL);
    }

    /**
     * Pestaña a la que cae quien llega sin elegir una (enlaces de correos ya
     * enviados): la que corresponde a la situación de la orden.
     */
    private function pestanaPorDefecto(Order $orden): string
    {
        if ($orden->esBorrador()) {
            return 'inscripcion.portal.inscripcion';
        }

        if ($orden->payment_status->liberaCredenciales() || $orden->status === OrderStatus::Finalizada) {
            return 'inscripcion.portal.credenciales';
        }

        return 'inscripcion.portal.pago';
    }

    /**
     * Datos que necesita el encabezado del portal en cualquier pestaña.
     *
     * @param  array<string, mixed>  $valores  Solo se aplican a la tarjeta de
     *                                         $orden: cada colegio del conjunto
     *                                         se equivoca por su cuenta.
     * @return array<string, mixed>
     */
    private function datosDelPortal(
        string $token,
        Order $orden,
        string $pestana,
        array $valores = [],
        ?MessageBag $errores = null,
        ?string $errorDeComprobante = null,
    ): array {
        $orden->load('payments.proofs', 'payments.reviews');

        // Con un solo colegio no hay conjunto que mostrar: se ve igual que si
        // el conjunto no existiera.
        $colegios = $orden->group
            ? $orden->group->orders()->with([
                'event', 'establishments', 'participants.accessType', 'participants.establishment',
                'payerEntity', 'payments.proofs', 'payments.reviews',
            ])->get()
            : collect([$orden]);

        return [
            'token' => $token,
            'orden' => $orden,
            'colegios' => $colegios,
            'varios' => $colegios->count() > 1,
            'event' => $orden->event,
            'pestana' => $pestana,
            'valores' => $valores,
            'errores' => $errores ?? new MessageBag,
            'errorDeComprobante' => $errorDeComprobante,
        ];
    }

    /**
     * Vuelve a la pestaña Inscripción, parado en el bloque. Sin sesión no hay
     * mensajes flash: `?guardado=` es lo que hace que el bloque diga "Cambios
     * guardados" en vez de recargar en silencio.
     */
    private function aBloque(string $token, string $bloque, bool $guardado = true): RedirectResponse
    {
        return redirect()->to(route('inscripcion.portal.inscripcion', array_filter([
            'token' => $token,
            'guardado' => $guardado ? $bloque : null,
        ])).'#'.$bloque);
    }

    /** @return array<string, mixed> */
    private function valoresDeResponsable(Order $orden): array
    {
        return [
            'responsible_name' => $orden->responsible_name,
            'responsible_lastname' => $orden->responsible_lastname,
            ...ReglasDeContacto::separarCargo($orden->responsible_position, 'responsible_position'),
            'responsible_email' => $orden->responsible_email,
            'responsible_phone' => $orden->responsible_phone,
            'responsible_institution' => $orden->responsible_institution,
            'kind' => $orden->kind->value,
        ];
    }

    /** @return array<string, mixed> */
    private function valoresDePagador(Order $orden): array
    {
        $pagador = $orden->payerEntity;

        return [
            'name' => $pagador->name ?? ($orden->kind === OrderKind::Particular ? $orden->responsableNombreCompleto() : ''),
            'rut' => $pagador?->rut,
            'address' => $pagador?->address,
            'commune' => $pagador?->commune,
            'billing_email' => $pagador->billing_email ?? $orden->responsible_email,
            'phone' => $pagador->phone ?? $orden->responsible_phone,
        ];
    }

    /**
     * Pestaña Inscripción. En un borrador reúne los cuatro bloques; con un
     * error de validación, ese bloque vuelve con lo escrito y sus mensajes y
     * los demás quedan como estaban, sin borrar nada.
     *
     * @param  array<string, mixed>  $valores
     * @param  array<string, mixed>  $extra
     */
    private function vistaInscripcion(
        string $token,
        Order $orden,
        ?string $bloqueConError = null,
        array $valores = [],
        ?MessageBag $errores = null,
        array $extra = [],
    ): Renderable {
        $valoresPorBloque = [
            'responsable' => $this->valoresDeResponsable($orden),
            'establecimientos' => [],
            'participantes' => [],
            'pagador' => $this->valoresDePagador($orden),
        ];
        $erroresPorBloque = array_map(fn (): MessageBag => new MessageBag, $valoresPorBloque);

        if ($bloqueConError !== null) {
            $valoresPorBloque[$bloqueConError] = $valores;
            $erroresPorBloque[$bloqueConError] = $errores ?? new MessageBag;
        }

        return view('publico.portal.inscripcion', $this->datosDelPortal($token, $orden, 'inscripcion', errores: $errores) + [
            'valoresPorBloque' => $valoresPorBloque,
            'erroresPorBloque' => $erroresPorBloque,
            'nivel' => $orden->nivelDeCambioDelCliente(),
            'participantes' => $orden->participantesVigentes()->with(['accessType', 'establishment'])->get(),
        ] + $extra);
    }

    /**
     * Pestaña Pago de un borrador: resumen, código de descuento y confirmar.
     *
     * @param  array<string, mixed>  $extra
     */
    private function vistaResumen(string $token, Order $orden, array $extra = []): Renderable
    {
        $orden->load('event', 'establishments', 'participants.accessType', 'participants.establishment', 'payerEntity', 'discountCode');

        // El `+` conserva la clave de la izquierda: lo que llega en $extra va
        // antes que los valores por defecto, o los pisaría con null.
        return view('publico.portal.pago', $extra + $this->datosDelPortal($token, $orden, 'pago') + [
            'faltantes' => app(ConfirmarOrden::class)->faltantes($orden),
            'errorDeConfirmacion' => null,
            'errorDeCodigo' => null,
        ]);
    }
}
