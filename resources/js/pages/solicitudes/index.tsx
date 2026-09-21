import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import SolicitudController from '@/actions/App/Http/Controllers/Solicitudes/SolicitudController';
import { EstadoBadge } from '@/components/estado-badge';
import { ExportarContactos } from '@/components/exportar-contactos';
import type { OpcionesExportacion } from '@/components/exportar-contactos';
import { NativeSelect } from '@/components/native-select';
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

type SolicitudFila = {
    id: number;
    recibida: string;
    persona: string;
    cargo: string | null;
    correo: string;
    telefono: string | null;
    institucion: string | null;
    evento: string | null;
    estado: Estado;
};

type Filtros = {
    buscar: string | null;
    evento: number | null;
    pendientes: boolean;
    cargo: string | null;
};

type Props = {
    solicitudes: Paginado<SolicitudFila>;
    filtros: Filtros;
    eventos: Record<string, string>;
    cargos: string[];
    exportacion: OpcionesExportacion | null;
};

export default function SolicitudesIndex({
    solicitudes,
    filtros,
    eventos,
    cargos,
    exportacion,
}: Props) {
    const [buscar, setBuscar] = useState(filtros.buscar ?? '');

    setLayoutProps({
        breadcrumbs: [
            { title: 'Solicitudes', href: SolicitudController.index() },
        ],
    });

    function filtrar(cambios: Partial<Filtros>) {
        const todos = { ...filtros, ...cambios };

        router.get(
            SolicitudController.index.url(),
            {
                ...(todos.buscar ? { buscar: todos.buscar } : {}),
                ...(todos.evento ? { evento: todos.evento } : {}),
                ...(todos.pendientes ? { pendientes: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function buscarPersona(e: FormEvent) {
        e.preventDefault();
        filtrar({ buscar });
    }

    const hayFiltros = Boolean(
        filtros.buscar || filtros.evento || filtros.pendientes || filtros.cargo,
    );

    return (
        <>
            <Head title="Solicitudes" />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Solicitudes
                    </h1>
                    {exportacion && (
                        <ExportarContactos
                            eventos={eventos}
                            opciones={exportacion}
                        />
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={buscarPersona}
                        className="relative w-full sm:w-80"
                    >
                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            type="search"
                            aria-label="Buscar"
                            placeholder="Nombre, correo o institución"
                            value={buscar}
                            onChange={(e) => setBuscar(e.target.value)}
                            className="pl-9"
                        />
                    </form>
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
                    {cargos.length > 0 && (
                        <NativeSelect
                            aria-label="Cargo"
                            className="w-auto max-w-56"
                            value={filtros.cargo ?? ''}
                            onChange={(e) =>
                                filtrar({ cargo: e.target.value || null })
                            }
                        >
                            <option value="">Todos los cargos</option>
                            {cargos.map((cargo) => (
                                <option key={cargo} value={cargo}>
                                    {cargo}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                    <label className="flex items-center gap-2 px-2 text-sm">
                        <Checkbox
                            checked={filtros.pendientes}
                            onCheckedChange={(valor) =>
                                filtrar({ pendientes: valor === true })
                            }
                        />
                        Solo las que faltan por seguir
                    </label>
                </div>

                <div className="bg-card rounded-xl border shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Recibida</TableHead>
                                <TableHead>Persona</TableHead>
                                <TableHead>Contacto</TableHead>
                                <TableHead>Institución</TableHead>
                                <TableHead>Evento</TableHead>
                                <TableHead>Estado</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {solicitudes.data.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        {hayFiltros
                                            ? 'Ninguna solicitud coincide con los filtros.'
                                            : 'Todavía no llegan solicitudes.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {solicitudes.data.map((s) => (
                                <TableRow
                                    key={s.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.visit(
                                            SolicitudController.show.url(s.id),
                                        )
                                    }
                                >
                                    <TableCell className="text-muted-foreground pl-5">
                                        {s.recibida}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={SolicitudController.show(
                                                s.id,
                                            )}
                                            className="block max-w-56 truncate font-medium hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {s.persona}
                                        </Link>
                                        {s.cargo && (
                                            <span className="text-muted-foreground block max-w-56 truncate text-xs">
                                                {s.cargo}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <span className="block max-w-56 truncate">
                                            {s.correo}
                                        </span>
                                        {s.telefono && (
                                            <span className="text-muted-foreground block text-xs">
                                                {s.telefono}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-48 truncate">
                                        {s.institucion ?? '—'}
                                    </TableCell>
                                    <TableCell className="max-w-48 truncate">
                                        {s.evento ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <EstadoBadge estado={s.estado} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Paginacion pagina={solicitudes} />
            </div>
        </>
    );
}
