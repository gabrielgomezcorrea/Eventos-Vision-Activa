import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps, FormEvent, ReactNode } from 'react';
import AccesoController from '@/actions/App/Http/Controllers/Eventos/AccesoController';
import TramoDescuentoController from '@/actions/App/Http/Controllers/Eventos/TramoDescuentoController';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import JornadaController from '@/actions/App/Http/Controllers/Eventos/JornadaController';
import { Campo } from '@/components/campo';
import { NativeSelect } from '@/components/native-select';
import { Confirmar } from '@/components/confirmar';
import InputError from '@/components/input-error';
import { SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type Jornada = {
    id: number;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    inicio: string | null;
    location: string | null;
    capacity: number | null;
    reserved_seats: number;
    disponibles: number | null;
};

type Acceso = {
    id: number;
    name: string;
    price: number;
    early_price: number | null;
    early_until: string | null;
    description: string | null;
    is_active: boolean;
    wristband_label: string | null;
    wristband_color: string | null;
    sessions: number[];
    jornadas: string[];
};

type Descuento = {
    id: number;
    min_participants: number;
    type: string;
    value: number;
    etiqueta: string;
};

type Props = {
    evento: { id: number; name: string; usa_pulseras: boolean };
    jornadas: Jornada[];
    accesos: Acceso[];
    descuentos: Descuento[];
    opciones: { tiposDescuento: Record<string, string> };
};

const clp = (valor: number): string => `$${valor.toLocaleString('es-CL')}`;

function colorQuedan(disponibles: number | null): string {
    if (disponibles === null) {
        return 'bg-muted text-muted-foreground';
    }

    if (disponibles === 0) {
        return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200';
    }

    if (disponibles <= 10) {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200';
    }

    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
}

/** Botón de icono con su nombre en un tooltip. */
function BotonIcono({
    texto,
    children,
    ...props
}: {
    texto: string;
    children: ReactNode;
} & ComponentProps<typeof Button>) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={texto}
                        {...props}
                    >
                        {children}
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent>{texto}</TooltipContent>
        </Tooltip>
    );
}

/** Subir o bajar: el orden se decide moviendo, no escribiendo un número. */
function Mover({
    url,
    primero,
    ultimo,
}: {
    url: string;
    primero: boolean;
    ultimo: boolean;
}) {
    const mover = (direccion: 'arriba' | 'abajo') =>
        router.post(url, { direccion }, { preserveScroll: true });

    return (
        <>
            <BotonIcono
                texto="Subir"
                disabled={primero}
                onClick={() => mover('arriba')}
            >
                <ArrowUp />
            </BotonIcono>
            <BotonIcono
                texto="Bajar"
                disabled={ultimo}
                onClick={() => mover('abajo')}
            >
                <ArrowDown />
            </BotonIcono>
        </>
    );
}

function PanelLateral({
    titulo,
    abierto,
    onOpenChange,
    trigger,
    onSubmit,
    procesando,
    children,
}: {
    titulo: string;
    abierto: boolean;
    onOpenChange: (valor: boolean) => void;
    trigger: ReactNode;
    onSubmit: (e: FormEvent) => void;
    procesando: boolean;
    children: ReactNode;
}) {
    return (
        <Sheet open={abierto} onOpenChange={onOpenChange}>
            <SheetTrigger asChild>{trigger}</SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <form onSubmit={onSubmit} className="flex min-h-full flex-col">
                    <SheetHeader>
                        <SheetTitle>{titulo}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {titulo}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex-1 space-y-5 px-4">{children}</div>
                    <SheetFooter>
                        <Button type="submit" disabled={procesando}>
                            {procesando ? 'Guardando…' : 'Guardar'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function FormularioJornada({
    eventoId,
    jornada,
    trigger,
}: {
    eventoId: number;
    jornada?: Jornada;
    trigger: ReactNode;
}) {
    const [abierto, setAbierto] = useState(false);
    const inicial = {
        name: jornada?.name ?? '',
        capacity: jornada?.capacity?.toString() ?? '',
        starts_at: jornada?.starts_at ?? '',
        ends_at: jornada?.ends_at ?? '',
        location: jornada?.location ?? '',
    };
    const form = useForm(inicial);

    function guardar(e: FormEvent) {
        e.preventDefault();
        const opciones = {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        };

        if (jornada) {
            form.patch(
                JornadaController.update.url({
                    event: eventoId,
                    session: jornada.id,
                }),
                opciones,
            );
        } else {
            form.post(JornadaController.store.url(eventoId), opciones);
        }
    }

    return (
        <PanelLateral
            titulo={jornada ? 'Editar jornada' : 'Nueva jornada'}
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.setData(inicial);
                    form.clearErrors();
                }
            }}
            trigger={trigger}
            onSubmit={guardar}
            procesando={form.processing}
        >
            <Campo
                label="Nombre"
                htmlFor="jornada-name"
                error={form.errors.name}
            >
                <Input
                    id="jornada-name"
                    value={form.data.name}
                    placeholder="Jornada 1"
                    required
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </Campo>
            <Campo
                label="Cupos"
                htmlFor="jornada-capacity"
                error={form.errors.capacity}
            >
                <Input
                    id="jornada-capacity"
                    type="number"
                    min={jornada?.reserved_seats ?? 0}
                    placeholder="Sin límite"
                    className="w-40"
                    value={form.data.capacity}
                    onChange={(e) => form.setData('capacity', e.target.value)}
                />
            </Campo>
            <div className="grid gap-5 sm:grid-cols-2">
                <Campo
                    label="Inicio"
                    htmlFor="jornada-starts"
                    error={form.errors.starts_at}
                >
                    <Input
                        id="jornada-starts"
                        type="datetime-local"
                        value={form.data.starts_at}
                        onChange={(e) =>
                            form.setData('starts_at', e.target.value)
                        }
                    />
                </Campo>
                <Campo
                    label="Término"
                    htmlFor="jornada-ends"
                    error={form.errors.ends_at}
                >
                    <Input
                        id="jornada-ends"
                        type="datetime-local"
                        value={form.data.ends_at}
                        onChange={(e) =>
                            form.setData('ends_at', e.target.value)
                        }
                    />
                </Campo>
            </div>
            <Campo
                label="Lugar, solo si difiere del evento"
                htmlFor="jornada-location"
                error={form.errors.location}
            >
                <Input
                    id="jornada-location"
                    value={form.data.location}
                    onChange={(e) => form.setData('location', e.target.value)}
                />
            </Campo>
        </PanelLateral>
    );
}

function FormularioAcceso({
    eventoId,
    jornadas,
    usaPulseras,
    acceso,
    trigger,
}: {
    eventoId: number;
    jornadas: Jornada[];
    usaPulseras: boolean;
    acceso?: Acceso;
    trigger: ReactNode;
}) {
    const [abierto, setAbierto] = useState(false);
    const inicial = {
        name: acceso?.name ?? '',
        price: acceso?.price.toString() ?? '',
        early_price: acceso?.early_price?.toString() ?? '',
        early_until: acceso?.early_until ?? '',
        sessions: acceso?.sessions ?? [],
        description: acceso?.description ?? '',
        is_active: acceso?.is_active ?? true,
        wristband_label: acceso?.wristband_label ?? '',
        wristband_color: acceso?.wristband_color ?? '',
    };
    const form = useForm(inicial);
    const errores = form.errors as Record<string, string | undefined>;

    function guardar(e: FormEvent) {
        e.preventDefault();
        const opciones = {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        };

        if (acceso) {
            form.patch(
                AccesoController.update.url({
                    event: eventoId,
                    accessType: acceso.id,
                }),
                opciones,
            );
        } else {
            form.post(AccesoController.store.url(eventoId), opciones);
        }
    }

    function alternar(id: number, marcada: boolean) {
        form.setData(
            'sessions',
            marcada
                ? [...form.data.sessions, id]
                : form.data.sessions.filter((s) => s !== id),
        );
    }

    const todas =
        jornadas.length > 0 && form.data.sessions.length === jornadas.length;

    return (
        <PanelLateral
            titulo={acceso ? 'Editar acceso' : 'Nuevo acceso'}
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.setData(inicial);
                    form.clearErrors();
                }
            }}
            trigger={trigger}
            onSubmit={guardar}
            procesando={form.processing}
        >
            <Campo
                label="Nombre"
                htmlFor="acceso-name"
                error={form.errors.name}
            >
                <Input
                    id="acceso-name"
                    value={form.data.name}
                    placeholder="Ambas jornadas"
                    required
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </Campo>
            <Campo
                label="Valor que paga el cliente"
                htmlFor="acceso-price"
                error={form.errors.price}
            >
                <div className="relative w-48">
                    <span className="text-muted-foreground absolute top-1/2 left-3 -translate-y-1/2 text-sm">
                        $
                    </span>
                    <Input
                        id="acceso-price"
                        type="number"
                        min={0}
                        step={1}
                        className="pl-7"
                        required
                        value={form.data.price}
                        onChange={(e) => form.setData('price', e.target.value)}
                    />
                </div>
            </Campo>

            <div className="grid gap-4 sm:grid-cols-2">
                <Campo
                    label="Valor anticipado (opcional)"
                    htmlFor="acceso-early-price"
                    error={form.errors.early_price}
                >
                    <div className="relative">
                        <span className="text-muted-foreground absolute top-1/2 left-3 -translate-y-1/2 text-sm">
                            $
                        </span>
                        <Input
                            id="acceso-early-price"
                            type="number"
                            min={0}
                            step={1}
                            className="pl-7"
                            value={form.data.early_price}
                            onChange={(e) =>
                                form.setData('early_price', e.target.value)
                            }
                        />
                    </div>
                </Campo>
                <Campo
                    label="Hasta el día"
                    htmlFor="acceso-early-until"
                    error={form.errors.early_until}
                >
                    <Input
                        id="acceso-early-until"
                        type="date"
                        value={form.data.early_until}
                        onChange={(e) =>
                            form.setData('early_until', e.target.value)
                        }
                    />
                </Campo>
            </div>

            <fieldset className="space-y-3">
                <div className="flex items-center justify-between">
                    <legend className="text-sm font-medium">
                        ¿Qué jornadas incluye este acceso?
                    </legend>
                    {jornadas.length > 1 && (
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="h-auto p-0"
                            onClick={() =>
                                form.setData(
                                    'sessions',
                                    todas ? [] : jornadas.map((j) => j.id),
                                )
                            }
                        >
                            {todas ? 'Desmarcar todas' : 'Marcar todas'}
                        </Button>
                    )}
                </div>
                {jornadas.map((jornada) => (
                    <label
                        key={jornada.id}
                        className="has-data-[state=checked]:border-primary flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                    >
                        <Checkbox
                            checked={form.data.sessions.includes(jornada.id)}
                            onCheckedChange={(valor) =>
                                alternar(jornada.id, valor === true)
                            }
                            className="mt-0.5"
                        />
                        <span className="text-sm">
                            <span className="block font-medium">
                                {jornada.name}
                            </span>
                            <span className="text-muted-foreground">
                                {jornada.inicio ?? 'Sin fecha'}
                            </span>
                        </span>
                    </label>
                ))}
                <InputError
                    message={
                        errores.sessions ??
                        Object.entries(errores).find(([clave]) =>
                            clave.startsWith('sessions.'),
                        )?.[1]
                    }
                />
            </fieldset>

            <Campo
                label="Descripción"
                htmlFor="acceso-description"
                error={form.errors.description}
            >
                <Textarea
                    id="acceso-description"
                    rows={2}
                    value={form.data.description}
                    onChange={(e) =>
                        form.setData('description', e.target.value)
                    }
                />
            </Campo>

            <div className="flex items-center gap-3">
                <Checkbox
                    id="acceso-activo"
                    checked={form.data.is_active}
                    onCheckedChange={(valor) =>
                        form.setData('is_active', valor === true)
                    }
                />
                <Label htmlFor="acceso-activo">Disponible para la venta</Label>
            </div>

            {usaPulseras && (
                <fieldset className="space-y-4 rounded-lg border p-4">
                    <legend className="px-1 text-sm font-medium">
                        Pulsera que se entrega en la acreditación
                    </legend>
                    <Campo
                        label="Nombre de la pulsera"
                        htmlFor="acceso-pulsera"
                        error={form.errors.wristband_label}
                    >
                        <Input
                            id="acceso-pulsera"
                            value={form.data.wristband_label}
                            placeholder="Acceso completo"
                            onChange={(e) =>
                                form.setData('wristband_label', e.target.value)
                            }
                        />
                    </Campo>
                    <Campo
                        label="Color"
                        htmlFor="acceso-color"
                        error={form.errors.wristband_color}
                    >
                        <div className="flex items-center gap-3">
                            <input
                                id="acceso-color"
                                type="color"
                                className="h-9 w-14 cursor-pointer rounded-md border"
                                value={form.data.wristband_color || '#084887'}
                                onChange={(e) =>
                                    form.setData(
                                        'wristband_color',
                                        e.target.value,
                                    )
                                }
                            />
                            {form.data.wristband_color ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        form.setData('wristband_color', '')
                                    }
                                >
                                    Sin color
                                </Button>
                            ) : (
                                <span className="text-muted-foreground text-sm">
                                    Sin color
                                </span>
                            )}
                        </div>
                    </Campo>
                </fieldset>
            )}
        </PanelLateral>
    );
}

/** Alta y edición de un tramo de descuento por cantidad. */
function FormularioTramo({
    eventoId,
    tipos,
    tramo,
    trigger,
}: {
    eventoId: number;
    tipos: Record<string, string>;
    tramo?: Descuento;
    trigger: ReactNode;
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm({
        min_participants: tramo?.min_participants.toString() ?? '',
        type: tramo?.type ?? 'percent',
        value: tramo?.value.toString() ?? '',
    });

    function enviar(e: FormEvent) {
        e.preventDefault();

        const opciones = {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        };

        if (tramo) {
            form.patch(
                TramoDescuentoController.update.url([eventoId, tramo.id]),
                opciones,
            );
        } else {
            form.post(TramoDescuentoController.store.url(eventoId), {
                ...opciones,
                onSuccess: () => {
                    setAbierto(false);
                    form.reset();
                },
            });
        }
    }

    return (
        <PanelLateral
            titulo={tramo ? 'Editar tramo' : 'Nuevo tramo de descuento'}
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.clearErrors();
                }
            }}
            trigger={trigger}
            onSubmit={enviar}
            procesando={form.processing}
        >
            <Campo
                label="Desde cuántos participantes"
                htmlFor="tramo-min"
                error={form.errors.min_participants}
            >
                <Input
                    id="tramo-min"
                    type="number"
                    min={2}
                    className="w-32"
                    required
                    value={form.data.min_participants}
                    onChange={(e) =>
                        form.setData('min_participants', e.target.value)
                    }
                />
            </Campo>

            <Campo label="Tipo" htmlFor="tramo-tipo" error={form.errors.type}>
                <NativeSelect
                    id="tramo-tipo"
                    value={form.data.type}
                    onChange={(e) => form.setData('type', e.target.value)}
                >
                    {Object.entries(tipos).map(([valor, texto]) => (
                        <option key={valor} value={valor}>
                            {texto}
                        </option>
                    ))}
                </NativeSelect>
            </Campo>

            <Campo
                label={
                    form.data.type === 'percent'
                        ? 'Porcentaje a descontar'
                        : 'Monto a rebajar'
                }
                htmlFor="tramo-valor"
                error={form.errors.value}
            >
                <div className="relative w-40">
                    <span className="text-muted-foreground absolute top-1/2 left-3 -translate-y-1/2 text-sm">
                        {form.data.type === 'percent' ? '%' : '$'}
                    </span>
                    <Input
                        id="tramo-valor"
                        type="number"
                        min={1}
                        className="pl-7"
                        required
                        value={form.data.value}
                        onChange={(e) => form.setData('value', e.target.value)}
                    />
                </div>
            </Campo>
        </PanelLateral>
    );
}

export default function CuposYPrecios({
    evento,
    jornadas,
    accesos,
    descuentos,
    opciones,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Eventos', href: EventoController.index() },
            { title: evento.name, href: EventoController.show(evento.id) },
            { title: 'Cupos y precios', href: '#' },
        ],
    });

    const sinJornadas = jornadas.length === 0;

    const botonNuevoAcceso = (
        <Button size="sm" disabled={sinJornadas}>
            <Plus />
            Nuevo acceso
        </Button>
    );

    return (
        <>
            <Head title={`Cupos y precios · ${evento.name}`} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Cupos y precios
                    </h1>
                    <Button variant="outline" asChild>
                        <Link href={EventoController.show(evento.id)}>
                            <ArrowLeft />
                            Volver al evento
                        </Link>
                    </Button>
                </header>

                <SeccionFicha
                    titulo="Jornadas"
                    accion={
                        <FormularioJornada
                            eventoId={evento.id}
                            trigger={
                                <Button size="sm">
                                    <Plus />
                                    Nueva jornada
                                </Button>
                            }
                        />
                    }
                    className="[&>div]:p-0"
                >
                    {sinJornadas ? (
                        <p className="text-muted-foreground px-5 py-10 text-center text-sm">
                            Empieza por aquí: los accesos que se venden se arman
                            con las jornadas.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-40 pl-5">
                                        <span className="sr-only">
                                            Acciones
                                        </span>
                                    </TableHead>
                                    <TableHead>Jornada</TableHead>
                                    <TableHead>Inicio</TableHead>
                                    <TableHead className="text-center">
                                        Cupos
                                    </TableHead>
                                    <TableHead className="text-center">
                                        Tomados
                                    </TableHead>
                                    <TableHead className="text-center">
                                        Quedan
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {jornadas.map((jornada, i) => (
                                    <TableRow key={jornada.id}>
                                        <TableCell className="pl-3">
                                            <div className="flex items-center">
                                                <Mover
                                                    url={JornadaController.mover.url(
                                                        {
                                                            event: evento.id,
                                                            session: jornada.id,
                                                        },
                                                    )}
                                                    primero={i === 0}
                                                    ultimo={
                                                        i ===
                                                        jornadas.length - 1
                                                    }
                                                />
                                                <FormularioJornada
                                                    eventoId={evento.id}
                                                    jornada={jornada}
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={`Editar ${jornada.name}`}
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                    }
                                                />
                                                {jornada.reserved_seats > 0 ? (
                                                    <BotonIcono
                                                        texto="Tiene cupos tomados: no se puede eliminar"
                                                        disabled
                                                    >
                                                        <Trash2 />
                                                    </BotonIcono>
                                                ) : (
                                                    <Confirmar
                                                        titulo={`Eliminar la jornada «${jornada.name}»`}
                                                        descripcion="Dejará de estar incluida en los accesos que la usaban."
                                                        textoAccion="Eliminar jornada"
                                                        onConfirmar={() =>
                                                            router.delete(
                                                                JornadaController.destroy.url(
                                                                    {
                                                                        event: evento.id,
                                                                        session:
                                                                            jornada.id,
                                                                    },
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8 text-red-600 hover:text-red-700"
                                                            aria-label={`Eliminar ${jornada.name}`}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </Confirmar>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {jornada.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {jornada.inicio ?? 'Sin fecha'}
                                        </TableCell>
                                        <TableCell className="text-center tabular-nums">
                                            {jornada.capacity ?? 'Sin límite'}
                                        </TableCell>
                                        <TableCell className="text-center tabular-nums">
                                            {jornada.reserved_seats}
                                        </TableCell>
                                        <TableCell className="text-center">
                                            <span
                                                className={cn(
                                                    'inline-block rounded-md px-2 py-0.5 text-xs font-medium tabular-nums',
                                                    colorQuedan(
                                                        jornada.disponibles,
                                                    ),
                                                )}
                                            >
                                                {jornada.disponibles ??
                                                    'Sin límite'}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SeccionFicha>

                <SeccionFicha
                    titulo="Accesos que se venden"
                    accion={
                        sinJornadas ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span>{botonNuevoAcceso}</span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    Agrega primero una jornada arriba.
                                </TooltipContent>
                            </Tooltip>
                        ) : (
                            <FormularioAcceso
                                eventoId={evento.id}
                                jornadas={jornadas}
                                usaPulseras={evento.usa_pulseras}
                                trigger={botonNuevoAcceso}
                            />
                        )
                    }
                    className="[&>div]:p-0"
                >
                    {accesos.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-10 text-center text-sm">
                            Crea lo que el cliente puede comprar: por ejemplo
                            Jornada 1, Jornada 2 y Ambas jornadas.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-40 pl-5">
                                        <span className="sr-only">
                                            Acciones
                                        </span>
                                    </TableHead>
                                    <TableHead>Acceso</TableHead>
                                    <TableHead>Valor</TableHead>
                                    <TableHead>Jornadas que incluye</TableHead>
                                    {evento.usa_pulseras && (
                                        <TableHead>Pulsera</TableHead>
                                    )}
                                    <TableHead>A la venta</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {accesos.map((acceso, i) => (
                                    <TableRow key={acceso.id}>
                                        <TableCell className="pl-3">
                                            <div className="flex items-center">
                                                <Mover
                                                    url={AccesoController.mover.url(
                                                        {
                                                            event: evento.id,
                                                            accessType:
                                                                acceso.id,
                                                        },
                                                    )}
                                                    primero={i === 0}
                                                    ultimo={
                                                        i === accesos.length - 1
                                                    }
                                                />
                                                <FormularioAcceso
                                                    eventoId={evento.id}
                                                    jornadas={jornadas}
                                                    usaPulseras={
                                                        evento.usa_pulseras
                                                    }
                                                    acceso={acceso}
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={`Editar ${acceso.name}`}
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                    }
                                                />
                                                <Confirmar
                                                    titulo={`Eliminar el acceso «${acceso.name}»`}
                                                    descripcion="Las inscripciones ya hechas conservan su precio."
                                                    textoAccion="Eliminar acceso"
                                                    onConfirmar={() =>
                                                        router.delete(
                                                            AccesoController.destroy.url(
                                                                {
                                                                    event: evento.id,
                                                                    accessType:
                                                                        acceso.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8 text-red-600 hover:text-red-700"
                                                        aria-label={`Eliminar ${acceso.name}`}
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </Confirmar>
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {acceso.name}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {clp(acceso.price)}
                                            {acceso.early_price !== null &&
                                                acceso.early_until && (
                                                    <span className="text-muted-foreground block text-xs">
                                                        {clp(
                                                            acceso.early_price,
                                                        )}{' '}
                                                        hasta el{' '}
                                                        {acceso.early_until}
                                                    </span>
                                                )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {acceso.jornadas.length ===
                                                0 ? (
                                                    <span className="text-muted-foreground">
                                                        Ninguna
                                                    </span>
                                                ) : (
                                                    acceso.jornadas.map(
                                                        (nombre) => (
                                                            <span
                                                                key={nombre}
                                                                className="bg-muted rounded-md px-2 py-0.5 text-xs"
                                                            >
                                                                {nombre}
                                                            </span>
                                                        ),
                                                    )
                                                )}
                                            </div>
                                        </TableCell>
                                        {evento.usa_pulseras && (
                                            <TableCell>
                                                {acceso.wristband_label ? (
                                                    <span className="flex items-center gap-2">
                                                        {acceso.wristband_color && (
                                                            <span
                                                                className="size-3 rounded-full border"
                                                                style={{
                                                                    backgroundColor:
                                                                        acceso.wristband_color,
                                                                }}
                                                            />
                                                        )}
                                                        {acceso.wristband_label}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            {acceso.is_active ? 'Sí' : 'No'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SeccionFicha>

                <SeccionFicha
                    titulo="Descuentos por cantidad"
                    className="[&>div]:p-0"
                    accion={
                        <FormularioTramo
                            eventoId={evento.id}
                            tipos={opciones.tiposDescuento}
                            trigger={
                                <Button variant="outline" size="sm">
                                    <Plus />
                                    Agregar tramo
                                </Button>
                            }
                        />
                    }
                >
                    {descuentos.length === 0 ? (
                        <p className="text-muted-foreground px-5 pb-5 text-sm">
                            Sin descuentos. El total es la suma de los accesos.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-24 pl-5" />
                                    <TableHead>Desde</TableHead>
                                    <TableHead>Descuento</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {descuentos.map((tramo) => (
                                    <TableRow key={tramo.id}>
                                        <TableCell className="pl-5">
                                            <div className="flex gap-1">
                                                <FormularioTramo
                                                    eventoId={evento.id}
                                                    tipos={
                                                        opciones.tiposDescuento
                                                    }
                                                    tramo={tramo}
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                    }
                                                />
                                                <Confirmar
                                                    titulo="Eliminar tramo"
                                                    descripcion={`Se elimina el descuento de ${tramo.etiqueta}. Las inscripciones ya confirmadas conservan el suyo.`}
                                                    textoAccion="Eliminar tramo"
                                                    onConfirmar={() =>
                                                        router.delete(
                                                            TramoDescuentoController.destroy.url(
                                                                [
                                                                    evento.id,
                                                                    tramo.id,
                                                                ],
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </Confirmar>
                                            </div>
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {tramo.min_participants} o más
                                        </TableCell>
                                        <TableCell>
                                            {tramo.type === 'percent'
                                                ? `${tramo.value}%`
                                                : clp(tramo.value)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SeccionFicha>
            </div>
        </>
    );
}
