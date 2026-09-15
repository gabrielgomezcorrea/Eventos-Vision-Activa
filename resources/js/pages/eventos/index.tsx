import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import { Campo } from '@/components/campo';
import { EstadoBadge } from '@/components/estado-badge';
import { NativeSelect } from '@/components/native-select';
import { Paginacion } from '@/components/paginacion';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import type { Estado, Paginado } from '@/types';

type EventoFila = {
    id: number;
    name: string;
    slug: string;
    estado: Estado;
    modalidad: string;
    fecha: string | null;
    jornadas: number;
    accesos: number;
    reserva: string;
};

type Filtros = {
    buscar?: string;
    estado?: string;
    modalidad?: string;
};

type Props = {
    eventos: Paginado<EventoFila>;
    filtros: Filtros;
    estados: Record<string, string>;
    modalidades: Record<string, string>;
    puedeCrear: boolean;
};

/** Crear pide lo mínimo: el resto se completa en la ficha cuando se sabe. */
function NuevoEvento({ modalidades }: { modalidades: Record<string, string> }) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm({
        name: '',
        starts_on: '',
        modality: 'presencial',
        description: '',
    });

    function crear(e: FormEvent) {
        e.preventDefault();
        form.post(EventoController.store.url());
    }

    return (
        <Dialog
            open={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Nuevo evento
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={crear} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Nuevo evento</DialogTitle>
                        <DialogDescription>
                            Lo demás se completa en la ficha.
                        </DialogDescription>
                    </DialogHeader>

                    <Campo
                        label="Nombre del evento"
                        htmlFor="name"
                        error={form.errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Seminario de Liderazgo Escolar 2026"
                            maxLength={120}
                            autoFocus
                            required
                        />
                    </Campo>

                    <Campo
                        label="Fecha del evento"
                        htmlFor="starts_on"
                        error={form.errors.starts_on}
                    >
                        <Input
                            id="starts_on"
                            type="date"
                            value={form.data.starts_on}
                            onChange={(e) =>
                                form.setData('starts_on', e.target.value)
                            }
                            required
                        />
                    </Campo>

                    <Campo
                        label="Modalidad"
                        htmlFor="modality"
                        error={form.errors.modality}
                    >
                        <NativeSelect
                            id="modality"
                            value={form.data.modality}
                            onChange={(e) =>
                                form.setData('modality', e.target.value)
                            }
                        >
                            {Object.entries(modalidades).map(
                                ([valor, texto]) => (
                                    <option key={valor} value={valor}>
                                        {texto}
                                    </option>
                                ),
                            )}
                        </NativeSelect>
                    </Campo>

                    <Campo
                        label="Descripción"
                        htmlFor="description"
                        error={form.errors.description}
                    >
                        <Textarea
                            id="description"
                            rows={2}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </Campo>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creando…' : 'Crear evento'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function EventosIndex({
    eventos,
    filtros,
    estados,
    modalidades,
    puedeCrear,
}: Props) {
    const [buscar, setBuscar] = useState(filtros.buscar ?? '');

    setLayoutProps({
        breadcrumbs: [{ title: 'Eventos', href: EventoController.index() }],
    });

    function filtrar(cambios: Filtros) {
        const query = Object.fromEntries(
            Object.entries({ ...filtros, ...cambios }).filter(
                ([, valor]) => valor,
            ),
        );

        router.get(EventoController.index.url(), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function buscarPorNombre(e: FormEvent) {
        e.preventDefault();
        filtrar({ buscar });
    }

    const hayFiltros = Boolean(
        filtros.buscar || filtros.estado || filtros.modalidad,
    );

    return (
        <>
            <Head title="Eventos" />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Eventos
                    </h1>
                    {puedeCrear && <NuevoEvento modalidades={modalidades} />}
                </div>

                <div className="flex flex-wrap gap-2">
                    <form
                        onSubmit={buscarPorNombre}
                        className="relative w-full sm:w-72"
                    >
                        <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            type="search"
                            aria-label="Buscar por nombre"
                            placeholder="Buscar por nombre"
                            value={buscar}
                            onChange={(e) => setBuscar(e.target.value)}
                            className="pl-9"
                        />
                    </form>
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
                        aria-label="Modalidad"
                        className="w-auto"
                        value={filtros.modalidad ?? ''}
                        onChange={(e) => filtrar({ modalidad: e.target.value })}
                    >
                        <option value="">Todas las modalidades</option>
                        {Object.entries(modalidades).map(([valor, texto]) => (
                            <option key={valor} value={valor}>
                                {texto}
                            </option>
                        ))}
                    </NativeSelect>
                    {hayFiltros && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setBuscar('');
                                router.get(EventoController.index.url());
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
                                <TableHead className="pl-5">Evento</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead>Modalidad</TableHead>
                                <TableHead className="text-center">
                                    Jornadas
                                </TableHead>
                                <TableHead className="text-center">
                                    Accesos
                                </TableHead>
                                <TableHead>Reserva</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {eventos.data.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        {hayFiltros
                                            ? 'Ningún evento coincide con los filtros.'
                                            : 'Todavía no hay eventos.'}
                                    </TableCell>
                                </TableRow>
                            )}
                            {eventos.data.map((evento) => (
                                <TableRow
                                    key={evento.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.visit(
                                            EventoController.show.url(
                                                evento.id,
                                            ),
                                        )
                                    }
                                >
                                    <TableCell className="pl-5">
                                        <Link
                                            href={EventoController.show(
                                                evento.id,
                                            )}
                                            className="block max-w-sm truncate font-medium hover:underline"
                                            title={evento.name}
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {evento.name}
                                        </Link>
                                        <span className="text-muted-foreground block max-w-sm truncate text-xs">
                                            {evento.slug}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <EstadoBadge estado={evento.estado} />
                                    </TableCell>
                                    <TableCell>{evento.modalidad}</TableCell>
                                    <TableCell className="text-center tabular-nums">
                                        {evento.jornadas}
                                    </TableCell>
                                    <TableCell className="text-center tabular-nums">
                                        {evento.accesos}
                                    </TableCell>
                                    <TableCell>{evento.reserva}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Paginacion pagina={eventos} />
            </div>
        </>
    );
}
