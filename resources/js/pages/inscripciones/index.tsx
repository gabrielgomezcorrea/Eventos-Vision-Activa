import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus, Search, UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InscripcionController from '@/actions/App/Http/Controllers/Inscripciones/InscripcionController';
import { Campo } from '@/components/campo';
import { EstadoBadge } from '@/components/estado-badge';
import { Panel } from '@/pages/inscripciones/show';
import { NativeSelect } from '@/components/native-select';
import { Paginacion } from '@/components/paginacion';
import { Button } from '@/components/ui/button';
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
import { cn } from '@/lib/utils';
import type { Estado, Paginado } from '@/types';

type OrdenFila = {
    id: number;
    numero: string | null;
    detalle: string | null;
    entidad: string | null;
    evento: string | null;
    solicitante: { nombre: string; via: string } | null;
    responsable: string | null;
    correo: string | null;
    total: number | null;
    invitado: boolean;
    participantes: number;
    estado: Estado;
};

type Filtros = {
    buscar: string | null;
    evento: number | null;
    vista: string;
};

type Vista = Estado & { cantidad: number; descripcion: string };

type Props = {
    ordenes: Paginado<OrdenFila>;
    filtros: Filtros;
    vistas: Vista[];
    borradoresVacios: number;
    eventos: Record<string, string>;
    invitados: {
        cargos: Record<string, string[]>;
        accesos: Record<string, Record<string, string>>;
    } | null;
    nuevaInscripcion: { eventos: Record<string, string> } | null;
};

const clp = (valor: number): string => `$${valor.toLocaleString('es-CL')}`;

/** El equipo inicia la inscripción de un colegio con el correo de quien hace el trámite. */
function NuevaInscripcion({ eventos }: { eventos: Record<string, string> }) {
    const [abierto, setAbierto] = useState(false);
    const ids = Object.keys(eventos);
    const form = useForm({
        event_id: ids[0] ?? '',
        email: '',
        notify_client: true,
    });

    function guardar(e: FormEvent) {
        e.preventDefault();
        form.post(InscripcionController.crearDesdePanel.url(), {
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Panel
            titulo="Nueva inscripción"
            descripcion="Le enviamos el enlace al correo del responsable."
            trigger={
                <Button>
                    <Plus />
                    Nueva inscripción
                </Button>
            }
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
            onSubmit={guardar}
            procesando={form.processing}
            textoEnviar="Crear inscripción"
        >
            <Campo
                label="Evento"
                htmlFor="n-event"
                error={form.errors.event_id}
            >
                <NativeSelect
                    id="n-event"
                    value={form.data.event_id}
                    onChange={(e) => form.setData('event_id', e.target.value)}
                >
                    {ids.map((id) => (
                        <option key={id} value={id}>
                            {eventos[id]}
                        </option>
                    ))}
                </NativeSelect>
            </Campo>
            <Campo
                label="Correo del responsable"
                htmlFor="n-email"
                error={form.errors.email}
            >
                <Input
                    id="n-email"
                    type="email"
                    required
                    placeholder="nombre@colegio.cl"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </Campo>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.notify_client}
                    onCheckedChange={(valor) =>
                        form.setData('notify_client', valor === true)
                    }
                />
                Enviarle correo
            </label>
        </Panel>
    );
}

/** Invitado sin costo: ocupa cupo y recibe su credencial al guardar. */
function NuevoInvitado({
    opciones,
    nombresDeEvento,
}: {
    opciones: NonNullable<Props['invitados']>;
    nombresDeEvento: Record<string, string>;
}) {
    const [abierto, setAbierto] = useState(false);
    const eventos = Object.keys(opciones.accesos);
    const form = useForm({
        event_id: eventos[0] ?? '',
        first_name: '',
        last_name: '',
        rut: '',
        email: '',
        phone: '',
        position: '',
        position_otro: '',
        establecimiento: '',
        access_type_id: '',
    });
    const accesos = opciones.accesos[form.data.event_id] ?? {};
    // Cada evento decide qué cargos acepta para quien asiste.
    const cargos = opciones.cargos[form.data.event_id] ?? [];

    function guardar(e: FormEvent) {
        e.preventDefault();
        form.post(InscripcionController.registrarInvitado.url(), {
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Panel
            titulo="Invitado especial"
            descripcion="Entra sin pagar, ocupa un cupo y recibe su credencial por correo."
            trigger={
                <Button variant="outline">
                    <UserPlus />
                    Invitado especial
                </Button>
            }
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
            onSubmit={guardar}
            procesando={form.processing}
            textoEnviar="Registrar invitado"
        >
            <Campo
                label="Evento"
                htmlFor="i-event"
                error={form.errors.event_id}
            >
                <NativeSelect
                    id="i-event"
                    value={form.data.event_id}
                    onChange={(e) => {
                        form.setData('event_id', e.target.value);
                        form.setData('access_type_id', '');
                    }}
                >
                    {eventos.map((id) => (
                        <option key={id} value={id}>
                            {nombresDeEvento[id] ?? id}
                        </option>
                    ))}
                </NativeSelect>
            </Campo>
            <div className="grid gap-5 sm:grid-cols-2">
                <Campo
                    label="Nombre"
                    htmlFor="i-first"
                    error={form.errors.first_name}
                >
                    <Input
                        id="i-first"
                        required
                        value={form.data.first_name}
                        onChange={(e) =>
                            form.setData('first_name', e.target.value)
                        }
                    />
                </Campo>
                <Campo
                    label="Apellidos"
                    htmlFor="i-last"
                    error={form.errors.last_name}
                >
                    <Input
                        id="i-last"
                        required
                        value={form.data.last_name}
                        onChange={(e) =>
                            form.setData('last_name', e.target.value)
                        }
                    />
                </Campo>
                <Campo label="RUT" htmlFor="i-rut" error={form.errors.rut}>
                    <Input
                        id="i-rut"
                        placeholder="12.345.678-9"
                        value={form.data.rut}
                        onChange={(e) => form.setData('rut', e.target.value)}
                    />
                </Campo>
                <Campo
                    label="Correo"
                    htmlFor="i-email"
                    error={form.errors.email}
                >
                    <Input
                        id="i-email"
                        type="email"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </Campo>
                <Campo
                    label="Teléfono (opcional)"
                    htmlFor="i-phone"
                    error={form.errors.phone}
                >
                    <Input
                        id="i-phone"
                        placeholder="56912345678"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                </Campo>
                <Campo
                    label="Cargo"
                    htmlFor="i-position"
                    error={form.errors.position}
                >
                    <NativeSelect
                        id="i-position"
                        value={form.data.position}
                        onChange={(e) =>
                            form.setData('position', e.target.value)
                        }
                    >
                        <option value="">Selecciona</option>
                        {cargos.map((cargo) => (
                            <option key={cargo} value={cargo}>
                                {cargo}
                            </option>
                        ))}
                    </NativeSelect>
                </Campo>
                {form.data.position === 'Otro' && (
                    <Campo
                        label="¿Cuál es su cargo?"
                        htmlFor="i-position-otro"
                        error={form.errors.position_otro}
                    >
                        <Input
                            id="i-position-otro"
                            value={form.data.position_otro}
                            onChange={(e) =>
                                form.setData('position_otro', e.target.value)
                            }
                        />
                    </Campo>
                )}
                <Campo
                    label="Establecimiento o institución (opcional)"
                    htmlFor="i-establecimiento"
                    error={form.errors.establecimiento}
                >
                    <Input
                        id="i-establecimiento"
                        value={form.data.establecimiento}
                        onChange={(e) =>
                            form.setData('establecimiento', e.target.value)
                        }
                    />
                </Campo>
                <Campo
                    label="Tipo de acceso"
                    htmlFor="i-acceso"
                    error={form.errors.access_type_id}
                >
                    <NativeSelect
                        id="i-acceso"
                        value={form.data.access_type_id}
                        onChange={(e) =>
                            form.setData('access_type_id', e.target.value)
                        }
                    >
                        <option value="">Selecciona</option>
                        {Object.entries(accesos).map(([id, nombre]) => (
                            <option key={id} value={id}>
                                {nombre}
                            </option>
                        ))}
                    </NativeSelect>
                </Campo>
            </div>
        </Panel>
    );
}

export default function InscripcionesIndex({
    ordenes,
    filtros,
    vistas,
    borradoresVacios,
    eventos,
    invitados,
    nuevaInscripcion,
}: Props) {
    const [buscar, setBuscar] = useState(filtros.buscar ?? '');

    setLayoutProps({
        breadcrumbs: [
            { title: 'Inscripciones', href: InscripcionController.index() },
        ],
    });

    function filtrar(cambios: Partial<Filtros>) {
        const todos = { ...filtros, ...cambios };
        const query = {
            ...(todos.buscar ? { buscar: todos.buscar } : {}),
            ...(todos.evento ? { evento: todos.evento } : {}),
            vista: todos.vista,
        };

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

    const hayBusqueda = Boolean(filtros.buscar || filtros.evento);

    return (
        <>
            <Head title="Inscripciones" />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Inscripciones
                    </h1>
                    <div className="flex flex-wrap gap-2">
                        {invitados && (
                            <NuevoInvitado
                                opciones={invitados}
                                nombresDeEvento={eventos}
                            />
                        )}
                        {nuevaInscripcion &&
                            Object.keys(nuevaInscripcion.eventos).length >
                                0 && (
                                <NuevaInscripcion
                                    eventos={nuevaInscripcion.eventos}
                                />
                            )}
                    </div>
                </div>

                <div
                    role="group"
                    aria-label="Vista"
                    className="grid grid-cols-2 gap-2 lg:grid-cols-5"
                >
                    {vistas.map((vista) => {
                        const elegida = vista.value === filtros.vista;

                        return (
                            <button
                                key={vista.value}
                                type="button"
                                aria-pressed={elegida}
                                onClick={() => filtrar({ vista: vista.value })}
                                className={cn(
                                    'bg-card flex items-center justify-between gap-2 rounded-xl border px-4 py-3 text-left shadow-xs transition-colors',
                                    elegida
                                        ? 'border-primary ring-primary ring-1'
                                        : 'hover:bg-muted/50',
                                )}
                            >
                                <span className="min-w-0">
                                    <span className="block font-medium">
                                        {vista.label}
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {vista.descripcion}
                                    </span>
                                </span>
                                <span className="text-2xl font-semibold tabular-nums">
                                    {vista.cantidad}
                                </span>
                            </button>
                        );
                    })}
                </div>

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
                    {hayBusqueda && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setBuscar('');
                                filtrar({ buscar: null, evento: null });
                            }}
                        >
                            Quitar búsqueda
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
                                <TableHead>Solicitante</TableHead>
                                <TableHead>Entidad pagadora</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Estado</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ordenes.data.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        {hayBusqueda
                                            ? 'Ninguna inscripción de esta vista coincide con la búsqueda.'
                                            : 'No hay inscripciones en esta vista.'}
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
                                        {orden.detalle && (
                                            <span className="block text-xs font-medium text-amber-700">
                                                {orden.detalle}
                                            </span>
                                        )}
                                        {orden.evento && (
                                            <span className="text-muted-foreground block max-w-48 truncate text-xs">
                                                {orden.evento}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {orden.solicitante ? (
                                            <>
                                                <span className="block max-w-56 truncate">
                                                    {orden.solicitante.nombre}
                                                </span>
                                                <span className="text-muted-foreground block text-xs">
                                                    {orden.solicitante.via}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {orden.entidad ? (
                                            <span className="block max-w-56 truncate">
                                                {orden.entidad}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground block max-w-56 truncate">
                                                Aún no registra entidad pagadora
                                            </span>
                                        )}
                                        <span className="text-muted-foreground block max-w-56 truncate text-xs">
                                            {orden.responsable}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {orden.invitado ? (
                                            <span className="font-medium text-purple-700">
                                                Invitado especial
                                            </span>
                                        ) : orden.total === null ? (
                                            <span className="text-muted-foreground">
                                                Sin confirmar
                                            </span>
                                        ) : (
                                            clp(orden.total)
                                        )}
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
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Paginacion pagina={ordenes} />

                {borradoresVacios > 0 && (
                    <p className="text-muted-foreground text-xs">
                        {borradoresVacios === 1
                            ? '1 enlace abierto sin datos no se muestra: nadie escribió nada todavía.'
                            : `${borradoresVacios} enlaces abiertos sin datos no se muestran: nadie escribió nada todavía.`}
                    </p>
                )}
            </div>
        </>
    );
}
