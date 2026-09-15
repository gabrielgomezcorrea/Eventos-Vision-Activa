import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InscripcionController from '@/actions/App/Http/Controllers/Inscripciones/InscripcionController';
import { EstadoBadge } from '@/components/estado-badge';
import { NativeSelect } from '@/components/native-select';
import { Paginacion } from '@/components/paginacion';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { Estado, Paginado } from '@/types';

export type Vencimiento = { tono: string; nota: string | null } | null;

type OrdenFila = {
    id: number;
    numero: string | null;
    responsable: string | null;
    correo: string | null;
    total: number;
    participantes: number;
    estado: Estado;
    pago: Estado;
    vence: string | null;
    vencimiento: Vencimiento;
};

type Filtros = {
    buscar?: string;
    evento?: string;
    estado?: string;
    pago?: string;
    vista?: string;
};

type Props = {
    ordenes: Paginado<OrdenFila>;
    filtros: Filtros;
    eventos: Record<string, string>;
    estados: Record<string, string>;
    pagos: Record<string, string>;
};

const clp = (valor: number): string => `$${valor.toLocaleString('es-CL')}`;

export const tonoVencimiento: Record<string, string> = {
    info: 'text-sky-700 dark:text-sky-400',
    warning: 'text-amber-700 dark:text-amber-400',
    danger: 'text-red-700 dark:text-red-400',
};

export default function InscripcionesIndex({
    ordenes,
    filtros,
    eventos,
    estados,
    pagos,
}: Props) {
    const [buscar, setBuscar] = useState(filtros.buscar ?? '');

    setLayoutProps({
        breadcrumbs: [
            { title: 'Inscripciones', href: InscripcionController.index() },
        ],
    });

    function filtrar(cambios: Filtros) {
        const query = Object.fromEntries(
            Object.entries({ ...filtros, ...cambios }).filter(
                ([, valor]) => valor,
            ),
        );

        router.get(InscripcionController.index.url(), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function buscarOrden(e: FormEvent) {
        e.preventDefault();
        filtrar({ buscar });
    }

    const hayFiltros = Object.values(filtros).some(Boolean);

    return (
        <>
            <Head title="Inscripciones" />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <h1 className="text-xl font-semibold tracking-tight">
                    Inscripciones
                </h1>

                <div className="flex flex-wrap gap-2">
                    <form
                        onSubmit={buscarOrden}
                        className="relative w-full sm:w-80"
                    >
                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            type="search"
                            aria-label="Buscar"
                            placeholder="N°, responsable, correo o entidad"
                            value={buscar}
                            onChange={(e) => setBuscar(e.target.value)}
                            className="pl-9"
                        />
                    </form>
                    <NativeSelect
                        aria-label="Evento"
                        className="w-auto max-w-64"
                        value={filtros.evento ?? ''}
                        onChange={(e) => filtrar({ evento: e.target.value })}
                    >
                        <option value="">Todos los eventos</option>
                        {Object.entries(eventos).map(([id, nombre]) => (
                            <option key={id} value={id}>
                                {nombre}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Estado"
                        className="w-auto"
                        value={filtros.estado ?? ''}
                        onChange={(e) => filtrar({ estado: e.target.value })}
                    >
                        <option value="">Todos los estados</option>
                        {Object.entries(estados).map(([valor, texto]) => (
                            <option key={valor} value={valor}>
                                {texto}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Pago"
                        className="w-auto"
                        value={filtros.pago ?? ''}
                        onChange={(e) => filtrar({ pago: e.target.value })}
                    >
                        <option value="">Cualquier pago</option>
                        {Object.entries(pagos).map(([valor, texto]) => (
                            <option key={valor} value={valor}>
                                {texto}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Reservas"
                        className="w-auto"
                        value={filtros.vista ?? ''}
                        onChange={(e) => filtrar({ vista: e.target.value })}
                    >
                        <option value="">Todas las reservas</option>
                        <option value="por_vencer">Por vencer (48 h)</option>
                        <option value="vencidas">Vencidas sin procesar</option>
                    </NativeSelect>
                    {hayFiltros && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setBuscar('');
                                router.get(InscripcionController.index.url());
                            }}
                        >
                            Quitar filtros
                        </Button>
                    )}
                </div>

                <div className="bg-card rounded-xl border shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">
                                    N° inscripción
                                </TableHead>
                                <TableHead>Responsable</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead>Pago</TableHead>
                                <TableHead>Vence</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ordenes.data.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        {hayFiltros
                                            ? 'Ninguna inscripción coincide con los filtros.'
                                            : 'Todavía no hay inscripciones.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {ordenes.data.map((orden) => (
                                <TableRow
                                    key={orden.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.visit(
                                            InscripcionController.show.url(
                                                orden.id,
                                            ),
                                        )
                                    }
                                >
                                    <TableCell className="pl-5">
                                        <Link
                                            href={InscripcionController.show(
                                                orden.id,
                                            )}
                                            className="font-semibold hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {orden.numero ?? 'Borrador'}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <span className="block max-w-56 truncate">
                                            {orden.responsable}
                                        </span>
                                        <span className="text-muted-foreground block max-w-56 truncate text-xs">
                                            {orden.correo}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {clp(orden.total)}
                                        <span className="text-muted-foreground block text-xs">
                                            {orden.participantes}{' '}
                                            {orden.participantes === 1
                                                ? 'participante'
                                                : 'participantes'}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <EstadoBadge estado={orden.estado} />
                                    </TableCell>
                                    <TableCell>
                                        <EstadoBadge estado={orden.pago} />
                                    </TableCell>
                                    <TableCell>
                                        <span
                                            className={cn(
                                                orden.vencimiento &&
                                                    tonoVencimiento[
                                                        orden.vencimiento.tono
                                                    ],
                                            )}
                                        >
                                            {orden.vence ?? '—'}
                                        </span>
                                        {orden.vencimiento?.nota && (
                                            <span
                                                className={cn(
                                                    'block text-xs',
                                                    tonoVencimiento[
                                                        orden.vencimiento.tono
                                                    ],
                                                )}
                                            >
                                                {orden.vencimiento.nota}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Paginacion pagina={ordenes} />
            </div>
        </>
    );
}
