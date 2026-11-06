import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Download, Search, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ExportarContactosController from '@/actions/App/Http/Controllers/Solicitudes/ExportarContactosController';
import SolicitudController from '@/actions/App/Http/Controllers/Solicitudes/SolicitudController';
import { EstadoBadge } from '@/components/estado-badge';
import { ExportarContactos } from '@/components/exportar-contactos';
import type { OpcionesExportacion } from '@/components/exportar-contactos';
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

type SolicitudFila = {
    id: number;
    recibida: string;
    recibida_hora: string;
    persona: string;
    directa: boolean;
    cargo: string | null;
    correo: string;
    telefono: string | null;
    institucion: string | null;
    evento: string | null;
    estado: Estado;
    detalle: string | null;
    ultima_nota: string | null;
};

type Filtros = {
    buscar: string | null;
    evento: number | null;
    etapa: string;
    sin_contactar: boolean;
};

type Etapa = Estado & { cantidad: number; descripcion: string };

type Props = {
    solicitudes: Paginado<SolicitudFila>;
    filtros: Filtros;
    etapas: Etapa[];
    eventos: Record<string, string>;
    exportacion: OpcionesExportacion | null;
};

const VACIO: Record<string, string> = {
    solicito: 'Nadie está esperando seguimiento.',
    pre_inscrito: 'Nadie dejó su inscripción a medias.',
    inscrito: 'Todavía no hay inscripciones pagadas.',
    no_participara: 'Nadie se ha marcado como «No participará».',
};

export default function SolicitudesIndex({
    solicitudes,
    filtros,
    etapas,
    eventos,
    exportacion,
}: Props) {
    const [buscar, setBuscar] = useState(filtros.buscar ?? '');

    setLayoutProps({
        breadcrumbs: [
            { title: 'Solicitudes', href: SolicitudController.index() },
        ],
    });

    function consulta(todos: Filtros) {
        return {
            ...(todos.buscar ? { buscar: todos.buscar } : {}),
            ...(todos.evento ? { evento: todos.evento } : {}),
            etapa: todos.etapa,
            ...(todos.sin_contactar ? { seguimiento: 'sin' } : {}),
        };
    }

    function filtrar(cambios: Partial<Filtros>) {
        router.get(
            SolicitudController.index.url(),
            consulta({ ...filtros, ...cambios }),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function buscarPersona(e: FormEvent) {
        e.preventDefault();
        filtrar({ buscar });
    }

    const actual = etapas.find((e) => e.value === filtros.etapa);
    const urlDescarga = ExportarContactosController.etapa.url({
        query: consulta(filtros),
    });

    return (
        <>
            <Head title="Solicitudes" />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-2">
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

                <div
                    role="group"
                    aria-label="Etapa"
                    className="grid grid-cols-2 gap-2 lg:grid-cols-4"
                >
                    {etapas.map((etapa) => {
                        const elegida = etapa.value === filtros.etapa;

                        return (
                            <button
                                key={etapa.value}
                                type="button"
                                aria-pressed={elegida}
                                onClick={() =>
                                    filtrar({
                                        etapa: etapa.value,
                                        sin_contactar: false,
                                    })
                                }
                                className={cn(
                                    'bg-card flex items-center justify-between gap-2 rounded-xl border px-4 py-3 text-left shadow-xs transition-colors',
                                    elegida
                                        ? 'border-primary ring-primary ring-1'
                                        : 'hover:bg-muted/50',
                                )}
                            >
                                <span className="min-w-0">
                                    <span className="block font-medium">
                                        {etapa.label}
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {etapa.descripcion}
                                    </span>
                                </span>
                                <span className="text-2xl font-semibold tabular-nums">
                                    {etapa.cantidad}
                                </span>
                            </button>
                        );
                    })}
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
                    {filtros.sin_contactar && (
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => filtrar({ sin_contactar: false })}
                        >
                            Solo sin contactar
                            <X />
                        </Button>
                    )}
                    {exportacion && (
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <Button asChild>
                                <a href={urlDescarga}>
                                    <Download />
                                    Descargar {actual?.label.toLowerCase()}
                                </a>
                            </Button>
                        </div>
                    )}
                </div>

                <div className="bg-card rounded-xl border shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Recibida</TableHead>
                                <TableHead>Institución</TableHead>
                                <TableHead>Solicitante</TableHead>
                                <TableHead>Contacto</TableHead>
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
                                        {filtros.buscar || filtros.evento
                                            ? 'Nadie coincide con la búsqueda en esta etapa.'
                                            : VACIO[filtros.etapa]}
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
                                        <span className="block text-xs">
                                            {s.recibida_hora}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-48 truncate">
                                        {s.institucion ?? '—'}
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
                                        {s.directa && (
                                            <span className="text-muted-foreground block max-w-56 truncate text-xs">
                                                Inscripción directa
                                            </span>
                                        )}
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
                                        {s.evento ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <EstadoBadge estado={s.estado} />
                                        {s.detalle && (
                                            <span className="text-muted-foreground block text-xs">
                                                {s.detalle}
                                            </span>
                                        )}
                                        {s.ultima_nota && (
                                            <span className="text-muted-foreground block text-xs">
                                                Última nota: {s.ultima_nota}
                                            </span>
                                        )}
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
