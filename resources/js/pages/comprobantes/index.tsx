import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ComprobanteController from '@/actions/App/Http/Controllers/Comprobantes/ComprobanteController';
import { EstadoBadge } from '@/components/estado-badge';
import { NativeSelect } from '@/components/native-select';
import { cn } from '@/lib/utils';
import { Paginacion } from '@/components/paginacion';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Estado, Paginado } from '@/types';

type PagoFila = {
    id: number;
    numero: string | null;
    responsable: string | null;
    evento: string | null;
    monto: number;
    diferencia: number | null;
    transferido: string | null;
    estado: Estado;
    recibido: string;
    esperando: string | null;
};

type Filtros = {
    buscar: string | null;
    estado: string;
    evento: number | null;
    diferencia: boolean;
};

type Props = {
    pagos: Paginado<PagoFila>;
    filtros: Filtros;
    estados: Record<string, string>;
    resumen: Record<string, number>;
    eventos: Record<string, string>;
};

const clp = (valor: number): string => `$${valor.toLocaleString('es-CL')}`;

export default function ComprobantesIndex({
    pagos,
    filtros,
    estados,
    resumen,
    eventos,
}: Props) {
    const [buscar, setBuscar] = useState(filtros.buscar ?? '');

    setLayoutProps({
        breadcrumbs: [
            { title: 'Comprobantes', href: ComprobanteController.index() },
        ],
    });

    function filtrar(cambios: Partial<Filtros>) {
        const todos = { ...filtros, ...cambios };

        router.get(
            ComprobanteController.index.url(),
            {
                estado: todos.estado,
                ...(todos.buscar ? { buscar: todos.buscar } : {}),
                ...(todos.evento ? { evento: todos.evento } : {}),
                ...(todos.diferencia ? { diferencia: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function buscarInscripcion(e: FormEvent) {
        e.preventDefault();
        filtrar({ buscar });
    }

    const soloPendientes = filtros.estado === 'in_review';

    return (
        <>
            <Head title="Comprobantes" />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <h1 className="text-xl font-semibold tracking-tight">
                    Comprobantes
                </h1>

                {/* Cuántos hay en cada estado. Es la vista que pedía
                    administración para saber qué falta sin ir probando filtros
                    ni llevar una planilla aparte. */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {Object.entries(estados).map(([valor, texto]) => {
                        const activo = filtros.estado === valor;

                        return (
                            <button
                                key={valor}
                                type="button"
                                onClick={() => filtrar({ estado: valor })}
                                className={cn(
                                    'bg-card rounded-xl border p-4 text-left transition-colors',
                                    activo
                                        ? 'border-primary ring-primary/20 ring-2'
                                        : 'hover:border-primary/40',
                                )}
                            >
                                <span className="text-muted-foreground block text-sm">
                                    {texto}
                                </span>
                                <span className="mt-1 block text-2xl font-semibold tabular-nums">
                                    {resumen[valor] ?? 0}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={buscarInscripcion}
                        className="relative w-full sm:w-72"
                    >
                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            type="search"
                            aria-label="Buscar por N° de inscripción o responsable"
                            placeholder="N° de inscripción o responsable"
                            value={buscar}
                            onChange={(e) => setBuscar(e.target.value)}
                            className="pl-9"
                        />
                    </form>
                    <NativeSelect
                        aria-label="Estado"
                        className="w-auto"
                        value={filtros.estado}
                        onChange={(e) => filtrar({ estado: e.target.value })}
                    >
                        <option value="in_review">
                            Pendientes de revisión
                        </option>
                        <option value="todos">Todos los estados</option>
                        {Object.entries(estados)
                            .filter(([valor]) => valor !== 'in_review')
                            .map(([valor, texto]) => (
                                <option key={valor} value={valor}>
                                    {texto}
                                </option>
                            ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Evento"
                        className="w-auto max-w-64"
                        value={filtros.evento ?? ''}
                        onChange={(e) =>
                            filtrar({
                                evento: e.target.value
                                    ? Number(e.target.value)
                                    : null,
                            })
                        }
                    >
                        <option value="">Todos los eventos</option>
                        {Object.entries(eventos).map(([id, nombre]) => (
                            <option key={id} value={id}>
                                {nombre}
                            </option>
                        ))}
                    </NativeSelect>
                    <label className="flex items-center gap-2 px-2 text-sm">
                        <Checkbox
                            checked={filtros.diferencia}
                            onCheckedChange={(valor) =>
                                filtrar({ diferencia: valor === true })
                            }
                        />
                        Con diferencia de monto
                    </label>
                </div>

                <div className="bg-card rounded-xl border shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">
                                    N° inscripción
                                </TableHead>
                                <TableHead>Evento</TableHead>
                                <TableHead className="text-right">
                                    Monto informado
                                </TableHead>
                                <TableHead>Transferido</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead>Recibido</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {pagos.data.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        {soloPendientes
                                            ? 'Nada pendiente de revisión.'
                                            : 'Ningún comprobante coincide con los filtros.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {pagos.data.map((pago) => (
                                <TableRow
                                    key={pago.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.visit(
                                            ComprobanteController.show.url(
                                                pago.id,
                                            ),
                                        )
                                    }
                                >
                                    <TableCell className="pl-5">
                                        <Link
                                            href={ComprobanteController.show(
                                                pago.id,
                                            )}
                                            className="block font-semibold hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {pago.numero ?? 'Sin número'}
                                        </Link>
                                        <span className="text-muted-foreground block max-w-56 truncate text-xs">
                                            {pago.responsable}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-56 truncate">
                                        {pago.evento}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <span
                                            className={
                                                pago.diferencia !== null
                                                    ? 'font-medium text-amber-700 dark:text-amber-400'
                                                    : ''
                                            }
                                        >
                                            {clp(pago.monto)}
                                        </span>
                                        {pago.diferencia !== null && (
                                            <span className="block text-xs text-amber-700 dark:text-amber-400">
                                                {pago.diferencia > 0
                                                    ? 'Sobra'
                                                    : 'Falta'}{' '}
                                                {clp(Math.abs(pago.diferencia))}{' '}
                                                vs. total
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {pago.transferido ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <EstadoBadge estado={pago.estado} />
                                    </TableCell>
                                    <TableCell>
                                        {pago.recibido}
                                        {pago.esperando && (
                                            <span className="text-muted-foreground block text-xs">
                                                {pago.esperando}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Paginacion pagina={pagos} />
            </div>
        </>
    );
}
