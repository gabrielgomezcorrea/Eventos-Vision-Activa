import { Head, Link, setLayoutProps, useForm } from '@inertiajs/react';
import { ClipboardCheck, Download, FileText } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ComprobanteController from '@/actions/App/Http/Controllers/Comprobantes/ComprobanteController';
import InscripcionController from '@/actions/App/Http/Controllers/Inscripciones/InscripcionController';
import { EstadoBadge } from '@/components/estado-badge';
import InputError from '@/components/input-error';
import { Dato, Datos, SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Estado } from '@/types';

type Archivo = {
    id: number;
    nombre: string;
    tamano: string;
    fecha: string | null;
    tipo: 'imagen' | 'pdf' | 'otro';
    descargar: string;
    ver: string;
};

type Decision = { value: string; label: string; descripcion: string };

type Props = {
    pago: {
        id: number;
        estado: Estado;
        monto: number;
        diferencia: number | null;
        transferido: string | null;
        banco: string | null;
        pagador: string | null;
        rut_pagador: string | null;
        referencia: string | null;
        notas: string | null;
        informado_por: string | null;
        cargado_internamente: boolean;
    };
    orden: {
        id: number;
        numero: string | null;
        responsable: string | null;
        correo: string | null;
        evento: string | null;
        entidad: string | null;
        rut_entidad: string | null;
        total: number;
        participantes: number;
        estado: Estado;
        reserva_vence: string | null;
    };
    comprobantes: Archivo[];
    historial: {
        id: number;
        decision: Estado;
        usuario: string | null;
        fecha: string | null;
        comentario: string | null;
    }[];
    puedeRevisar: boolean;
    decisiones: Decision[];
};

const clp = (valor: number): string => `$${valor.toLocaleString('es-CL')}`;

/** Una decisión con tres salidas: un botón, y cada opción con su consecuencia. */
function Revisar({
    pagoId,
    decisiones,
}: {
    pagoId: number;
    decisiones: Decision[];
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm({ decision: '', comment: '' });

    // Aprobar no tiene nada que explicarle al cliente, así que llega escrito:
    // igual queda en la auditoría y nadie pierde tiempo redactándolo.
    const NOTA_APROBACION =
        'Monto abonado y verificado. Corresponde a esta inscripción.';

    const vaAlCliente =
        form.data.decision === 'observed' || form.data.decision === 'rejected';
    const etiquetaComentario =
        form.data.decision === 'observed'
            ? 'Qué debe corregir el cliente'
            : form.data.decision === 'rejected'
              ? 'Motivo del rechazo'
              : 'Nota de la revisión';

    function elegir(valor: string) {
        form.setData((datos) => ({
            ...datos,
            decision: valor,
            // Solo se rellena si no escribió nada suyo: lo que la persona
            // escribió no se pisa al cambiar de opción.
            comment:
                valor === 'approved' && datos.comment === ''
                    ? NOTA_APROBACION
                    : datos.comment === NOTA_APROBACION && valor !== 'approved'
                      ? ''
                      : datos.comment,
        }));
    }

    function confirmar(e: FormEvent) {
        e.preventDefault();
        form.post(ComprobanteController.revisar.url(pagoId), {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Sheet
            open={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <SheetTrigger asChild>
                <Button>
                    <ClipboardCheck />
                    Revisar el comprobante
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <form onSubmit={confirmar} className="flex min-h-full flex-col">
                    <SheetHeader>
                        <SheetTitle>Revisar el comprobante</SheetTitle>
                        <SheetDescription>
                            ¿Qué corresponde hacer con este comprobante?
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex-1 space-y-5 px-4">
                        <fieldset className="space-y-3">
                            {decisiones.map((opcion) => (
                                <label
                                    key={opcion.value}
                                    className="has-checked:border-primary has-checked:bg-accent/50 flex cursor-pointer gap-3 rounded-lg border p-4"
                                >
                                    <input
                                        type="radio"
                                        name="decision"
                                        value={opcion.value}
                                        checked={
                                            form.data.decision === opcion.value
                                        }
                                        onChange={() => elegir(opcion.value)}
                                        className="mt-1"
                                    />
                                    <span className="space-y-1">
                                        <span className="block font-medium">
                                            {opcion.label}
                                        </span>
                                        <span className="text-muted-foreground block text-sm">
                                            {opcion.descripcion}
                                        </span>
                                    </span>
                                </label>
                            ))}
                            <InputError message={form.errors.decision} />
                        </fieldset>

                        {form.data.decision !== '' && (
                            <div className="grid gap-2">
                                <Label htmlFor="comment">
                                    {etiquetaComentario}
                                </Label>
                                <Textarea
                                    id="comment"
                                    rows={3}
                                    required
                                    minLength={6}
                                    value={form.data.comment}
                                    onChange={(e) =>
                                        form.setData('comment', e.target.value)
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    {vaAlCliente
                                        ? 'Le llega al cliente por correo.'
                                        : 'Queda en el historial de la inscripción.'}
                                </p>
                                <InputError message={form.errors.comment} />
                            </div>
                        )}
                    </div>
                    <SheetFooter>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || form.data.decision === ''
                            }
                        >
                            {form.processing ? 'Confirmando…' : 'Confirmar'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

/** El documento a la vista: se revisa sin descargar ni cambiar de ventana. */
function Visor({ archivo }: { archivo: Archivo | undefined }) {
    if (!archivo) {
        return (
            <p className="text-muted-foreground py-16 text-center text-sm">
                No hay archivo cargado.
            </p>
        );
    }

    if (archivo.tipo === 'imagen') {
        return (
            <img
                src={archivo.ver}
                alt={`Comprobante ${archivo.nombre}`}
                className="mx-auto max-h-[75vh] w-auto rounded-md border"
            />
        );
    }

    if (archivo.tipo === 'pdf') {
        return (
            <iframe
                src={archivo.ver}
                title={`Comprobante ${archivo.nombre}`}
                className="h-[75vh] w-full rounded-md border"
            />
        );
    }

    return (
        <p className="text-muted-foreground py-16 text-center text-sm">
            Este tipo de archivo no se puede mostrar aquí. Descárgalo para
            verlo.
        </p>
    );
}

export default function ComprobanteShow({
    pago,
    orden,
    comprobantes,
    historial,
    puedeRevisar,
    decisiones,
}: Props) {
    const titulo = orden.numero ?? 'Comprobante';
    const ultimo = comprobantes[0];

    setLayoutProps({
        breadcrumbs: [
            { title: 'Comprobantes', href: ComprobanteController.index() },
            { title: titulo, href: ComprobanteController.show(pago.id) },
        ],
    });

    return (
        <>
            <Head title={`Comprobante ${titulo}`} />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {titulo}
                        </h1>
                        <div className="flex items-center gap-2 text-sm">
                            <EstadoBadge estado={pago.estado} />
                            <span className="text-muted-foreground">
                                {orden.evento}
                            </span>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {ultimo && (
                            <Button variant="outline" asChild>
                                <a href={ultimo.descargar}>
                                    <Download />
                                    {comprobantes.length > 1
                                        ? 'Descargar el último comprobante'
                                        : 'Descargar comprobante'}
                                </a>
                            </Button>
                        )}
                        {puedeRevisar && (
                            <Revisar pagoId={pago.id} decisiones={decisiones} />
                        )}
                    </div>
                </header>

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <SeccionFicha
                        titulo={ultimo ? ultimo.nombre : 'Comprobante'}
                        className="lg:sticky lg:top-4"
                    >
                        <Visor archivo={ultimo} />
                    </SeccionFicha>

                    <div className="flex flex-col gap-6">
                        <SeccionFicha titulo="Pago informado">
                            <Datos>
                                <Dato etiqueta="Monto informado">
                                    <span
                                        className={cn(
                                            'font-semibold tabular-nums',
                                            pago.diferencia !== null &&
                                                'text-amber-700 dark:text-amber-400',
                                        )}
                                    >
                                        {clp(pago.monto)}
                                    </span>
                                    {pago.diferencia !== null && (
                                        <span className="block text-xs text-amber-700 dark:text-amber-400">
                                            Difiere del total en{' '}
                                            {clp(Math.abs(pago.diferencia))}
                                        </span>
                                    )}
                                </Dato>
                                <Dato etiqueta="Total de la inscripción">
                                    {clp(orden.total)}
                                </Dato>
                                <Dato etiqueta="Fecha de transferencia">
                                    {pago.transferido}
                                </Dato>
                                <Dato etiqueta="Banco de origen">
                                    {pago.banco}
                                </Dato>
                                <Dato etiqueta="Pagador">{pago.pagador}</Dato>
                                <Dato etiqueta="RUT del pagador">
                                    {pago.rut_pagador}
                                </Dato>
                                <Dato etiqueta="Informado por">
                                    {pago.informado_por && (
                                        <>
                                            {pago.informado_por}
                                            <span className="text-muted-foreground block text-xs">
                                                {pago.cargado_internamente
                                                    ? 'Cargado internamente'
                                                    : 'Cargado por el cliente'}
                                            </span>
                                        </>
                                    )}
                                </Dato>
                                <Dato
                                    etiqueta="Observaciones del cliente"
                                    ancho
                                >
                                    {pago.notas}
                                </Dato>
                            </Datos>
                        </SeccionFicha>

                        <SeccionFicha titulo="Inscripción">
                            <Datos>
                                <Dato etiqueta="N° inscripción">
                                    <Link
                                        href={InscripcionController.show(
                                            orden.id,
                                        )}
                                        className="font-semibold hover:underline"
                                    >
                                        {orden.numero}
                                    </Link>
                                </Dato>
                                <Dato etiqueta="Responsable">
                                    {orden.responsable}
                                    {orden.correo && (
                                        <span className="text-muted-foreground block text-xs break-all">
                                            {orden.correo}
                                        </span>
                                    )}
                                </Dato>
                                <Dato etiqueta="Participantes">
                                    {orden.participantes}
                                </Dato>
                                <Dato etiqueta="Entidad pagadora">
                                    {orden.entidad}
                                </Dato>
                                <Dato etiqueta="RUT">{orden.rut_entidad}</Dato>
                                <Dato etiqueta="Estado de la orden">
                                    <EstadoBadge estado={orden.estado} />
                                </Dato>
                                <Dato etiqueta="Reserva vence">
                                    {orden.reserva_vence}
                                </Dato>
                            </Datos>
                        </SeccionFicha>

                        {comprobantes.length > 1 && (
                            <SeccionFicha titulo="Archivos anteriores">
                                <ul className="divide-y text-sm">
                                    {comprobantes.slice(1).map((archivo) => (
                                        <li
                                            key={archivo.id}
                                            className="flex items-center justify-between gap-3 py-2"
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                <FileText className="text-muted-foreground size-4 shrink-0" />
                                                <span className="truncate">
                                                    {archivo.nombre}
                                                </span>
                                                <span className="text-muted-foreground shrink-0 text-xs">
                                                    {archivo.fecha} ·{' '}
                                                    {archivo.tamano}
                                                </span>
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <a href={archivo.descargar}>
                                                    <Download />
                                                    Descargar
                                                </a>
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </SeccionFicha>
                        )}

                        {historial.length > 0 && (
                            <SeccionFicha titulo="Historial de revisión">
                                <ol className="space-y-4">
                                    {historial.map((revision) => (
                                        <li
                                            key={revision.id}
                                            className="text-sm"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <EstadoBadge
                                                    estado={revision.decision}
                                                />
                                                <span className="text-muted-foreground">
                                                    {revision.usuario} ·{' '}
                                                    {revision.fecha}
                                                </span>
                                            </div>
                                            {revision.comentario && (
                                                <p className="mt-1">
                                                    {revision.comentario}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            </SeccionFicha>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
