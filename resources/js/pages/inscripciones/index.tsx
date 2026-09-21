import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { Search, UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InscripcionController from '@/actions/App/Http/Controllers/Inscripciones/InscripcionController';
import { Campo } from '@/components/campo';
import { EstadoBadge } from '@/components/estado-badge';
import { Panel } from '@/pages/inscripciones/show';
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
    invitados: {
        cargos: Record<string, string[]>;
        accesos: Record<string, Record<string, string>>;
    } | null;
};

const clp = (valor: number): string => `$${valor.toLocaleString('es-CL')}`;

export const tonoVencimiento: Record<string, string> = {
    info: 'text-sky-700 dark:text-sky-400',
    warning: 'text-amber-700 dark:text-amber-400',
    danger: 'text-red-700 dark:text-red-400',
};

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
                        required
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
    eventos,
    estados,
    pagos,
    invitados,
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
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Inscripciones
                    </h1>
                    {invitados && (
                        <NuevoInvitado
                            opciones={invitados}
                            nombresDeEvento={eventos}
                        />
                    )}
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
