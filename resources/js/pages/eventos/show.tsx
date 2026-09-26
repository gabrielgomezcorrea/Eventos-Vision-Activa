import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import { ArrowRight, Copy, Flag, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import CuentaBancariaController from '@/actions/App/Http/Controllers/Configuracion/CuentaBancariaController';
import CodigoDescuentoController from '@/actions/App/Http/Controllers/Eventos/CodigoDescuentoController';
import InvitacionController from '@/actions/App/Http/Controllers/Eventos/InvitacionController';
import CuposYPreciosController from '@/actions/App/Http/Controllers/Eventos/CuposYPreciosController';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import FormularioPublicoController from '@/actions/App/Http/Controllers/Eventos/FormularioPublicoController';
import { Campo } from '@/components/campo';
import { Confirmar } from '@/components/confirmar';
import { EditarSeccion } from '@/components/editar-seccion';
import { EstadoBadge } from '@/components/estado-badge';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Dato, Datos, SeccionFicha } from '@/components/seccion-ficha';
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
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { index as comprobantes } from '@/routes/comprobantes';
import { index as inscripciones } from '@/routes/inscripciones';
import { index as solicitudes } from '@/routes/solicitudes';
import type { Estado } from '@/types';

type Cuenta = {
    label: string;
    bank_name: string;
    account_number: string;
    holder_name: string;
    holder_rut: string | null;
    account_type: string | null;
    payment_instructions: string | null;
};

type Evento = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    modality: string;
    modalidad: string;
    starts_on: string | null;
    fecha: string | null;
    estado: Estado;
    location: string | null;
    address: string | null;
    region: string | null;
    commune: string | null;
    city: string | null;
    reservation_duration_value: number;
    reservation_duration_unit: string;
    reminder_hours_before: number | null;
    aviso: string;
    reserva: string;
    fecha_limite_reemplazos: string | null;
    bank_account_id: number | null;
    cuenta: Cuenta | null;
    enlace_publico: string;
    banner: string | null;
    banner_ubicaciones: { clave: string; etiqueta: string; activa: boolean }[];
    banner_max_mb: number;
    banner_formatos: string;
    usa_lugar: boolean;
};

type Resumen = {
    solicitudes: number;
    solicitudes_sin_seguir: number;
    inscripciones: number;
    pagadas: number;
    por_vencer: number;
    comprobantes: number | null;
    acreditados: number;
    credenciales: number;
};

type OpcionEstado = { value: string; label: string; descripcion: string };

type Props = {
    evento: Evento;
    resumen: Resumen;
    jornadas: string[];
    accesos: string[];
    formulario: string[];
    faltaParaPublicar: string[];
    opciones: {
        modalidades: Record<string, string>;
        unidades: Record<string, string>;
        regiones: Record<string, string[]>;
        cuentas: { id: number; label: string }[];
        estados: OpcionEstado[];
    };
    codigos: string[];
    invitaciones: string[];
    puede: { editar: boolean; eliminar: boolean; descuentos: boolean };
};

function enumerar(items: string[]): string {
    if (items.length <= 1) {
        return items.join('');
    }

    return `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`;
}

/** Un botón con las opciones adentro: la que no corresponde dice por qué. */
function CambiarEstado({
    evento,
    opciones,
    falta,
}: {
    evento: Evento;
    opciones: OpcionEstado[];
    falta: string[];
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm({ status: evento.estado.value });

    function guardar(e: FormEvent) {
        e.preventDefault();
        form.patch(EventoController.cambiarEstado.url(evento.id), {
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
                    form.setData('status', evento.estado.value);
                    form.clearErrors();
                }
            }}
        >
            <SheetTrigger asChild>
                <Button variant="outline">
                    <Flag />
                    Estado: {evento.estado.label}
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full sm:max-w-lg">
                <form onSubmit={guardar} className="flex min-h-full flex-col">
                    <SheetHeader>
                        <SheetTitle>Estado del evento</SheetTitle>
                        <SheetDescription>
                            ¿En qué estado queda el evento?
                        </SheetDescription>
                    </SheetHeader>
                    <fieldset className="flex-1 space-y-3 px-4">
                        {opciones.map((opcion) => {
                            const bloqueada =
                                opcion.value === 'published' &&
                                falta.length > 0;

                            return (
                                <label
                                    key={opcion.value}
                                    className={cn(
                                        'flex gap-3 rounded-lg border p-4',
                                        bloqueada
                                            ? 'cursor-not-allowed opacity-60'
                                            : 'has-checked:border-primary has-checked:bg-accent/50 cursor-pointer',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="status"
                                        value={opcion.value}
                                        checked={
                                            form.data.status === opcion.value
                                        }
                                        disabled={bloqueada}
                                        onChange={() =>
                                            form.setData('status', opcion.value)
                                        }
                                        className="mt-1"
                                    />
                                    <span className="space-y-1">
                                        <span className="block font-medium">
                                            {opcion.label}
                                        </span>
                                        <span className="text-muted-foreground block text-sm">
                                            {opcion.descripcion}
                                            {bloqueada &&
                                                ` No disponible: falta cargar ${enumerar(falta)}.`}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                        <InputError message={form.errors.status} />
                    </fieldset>
                    <SheetFooter>
                        <Button type="submit" disabled={form.processing}>
                            Guardar estado
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

/** El mismo lápiz de las demás secciones, pero lleva a su propia pantalla. */
/** Goes to the block's own screen: arrow, not pencil, because it is not edited here. */
function IrA({ href, texto }: { href: string; texto: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button variant="ghost" size="icon" asChild>
                    <Link href={href} aria-label={texto}>
                        <ArrowRight />
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>{texto}</TooltipContent>
        </Tooltip>
    );
}

function Numero({
    titulo,
    valor,
    detalle,
    href,
    alerta = false,
}: {
    titulo: string;
    valor: string | number;
    detalle: string;
    href?: string;
    alerta?: boolean;
}) {
    const contenido = (
        <>
            <div className="text-muted-foreground text-sm">{titulo}</div>
            <div
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    alerta && 'text-amber-600 dark:text-amber-400',
                )}
            >
                {valor}
            </div>
            <div className="text-muted-foreground text-xs">{detalle}</div>
        </>
    );

    return href ? (
        <Link
            href={href}
            className="hover:bg-accent/50 rounded-lg p-3 transition-colors"
        >
            {contenido}
        </Link>
    ) : (
        <div className="p-3">{contenido}</div>
    );
}

function Lista({ items, vacio }: { items: string[]; vacio: string }) {
    if (items.length === 0) {
        return <p className="text-muted-foreground text-sm">{vacio}</p>;
    }

    return (
        <ul className="list-disc space-y-1 pl-5 text-sm">
            {items.map((item) => (
                <li key={item}>{item}</li>
            ))}
        </ul>
    );
}

/**
 * Banner del evento: una sola imagen, opcional.
 *
 * No va con lápiz ni panel lateral: es un archivo que se sube o se quita, y
 * verlo mientras se cambia es la mitad de la decisión.
 */
function Banner({
    evento,
    puedeEditar,
}: {
    evento: Evento;
    puedeEditar: boolean;
}) {
    const entrada = useRef<HTMLInputElement>(null);
    const [subiendo, setSubiendo] = useState(false);
    const [errorLocal, setErrorLocal] = useState<string | null>(null);
    const { errors } = usePage().props;

    function subir(archivo: File | undefined) {
        setErrorLocal(null);

        if (!archivo) {
            return;
        }

        // El límite se avisa antes de subir: enterarse después de esperar una
        // subida lenta es la peor forma de descubrirlo.
        if (archivo.size > evento.banner_max_mb * 1024 * 1024) {
            setErrorLocal(
                `La imagen pesa más de ${evento.banner_max_mb} MB. Elige una más liviana.`,
            );

            return;
        }

        router.post(
            EventoController.subirBanner.url(evento.id),
            { banner: archivo },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setSubiendo(true),
                onFinish: () => {
                    setSubiendo(false);

                    if (entrada.current) {
                        entrada.current.value = '';
                    }
                },
            },
        );
    }

    return (
        <SeccionFicha
            titulo="Banner"
            accion={
                puedeEditar &&
                evento.banner && (
                    <EditarSeccion
                        titulo="Dónde se ve el banner"
                        url={EventoController.ubicarBanner.url(evento.id)}
                        inicial={Object.fromEntries(
                            evento.banner_ubicaciones.map((u) => [
                                u.clave,
                                u.activa ? '1' : '0',
                            ]),
                        )}
                    >
                        {(f) => (
                            <div className="space-y-3">
                                {evento.banner_ubicaciones.map((u) => (
                                    <div
                                        key={u.clave}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`ubicacion-${u.clave}`}
                                            checked={f.data[u.clave] === '1'}
                                            onCheckedChange={(valor) =>
                                                f.set(
                                                    u.clave,
                                                    valor === true ? '1' : '0',
                                                )
                                            }
                                        />
                                        <Label htmlFor={`ubicacion-${u.clave}`}>
                                            {u.etiqueta}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        )}
                    </EditarSeccion>
                )
            }
        >
            <div className="grid gap-3">
                {evento.banner ? (
                    <div className="relative w-fit max-w-full">
                        <img
                            src={evento.banner}
                            alt={`Banner de ${evento.name}`}
                            className="max-h-40 max-w-full rounded-lg border object-contain"
                        />
                        {puedeEditar && (
                            <Confirmar
                                titulo="Quitar el banner"
                                descripcion="Los correos y las páginas del evento vuelven a verse sin imagen."
                                textoAccion="Quitar banner"
                                onConfirmar={() =>
                                    router.delete(
                                        EventoController.quitarBanner.url(
                                            evento.id,
                                        ),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Button
                                    variant="secondary"
                                    size="icon"
                                    aria-label="Quitar banner"
                                    className="absolute top-2 right-2 size-8 bg-white/90 text-red-600 shadow-sm hover:bg-white hover:text-red-700"
                                >
                                    <Trash2 />
                                </Button>
                            </Confirmar>
                        )}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        Sin banner. Los correos y las páginas del evento se ven
                        sin imagen arriba.
                    </p>
                )}

                {puedeEditar && (
                    <div className="grid gap-2">
                        <Input
                            ref={entrada}
                            id="banner"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            disabled={subiendo}
                            className="max-w-md"
                            onChange={(e) => subir(e.target.files?.[0])}
                        />
                        <p className="text-muted-foreground text-xs">
                            {evento.banner_formatos}, hasta{' '}
                            {evento.banner_max_mb} MB. Se muestra tal cual, sin
                            texto encima.
                        </p>
                        {subiendo && (
                            <p className="text-muted-foreground text-xs">
                                Subiendo…
                            </p>
                        )}
                        <InputError message={errorLocal ?? errors.banner} />
                    </div>
                )}
            </div>
        </SeccionFicha>
    );
}

export default function EventoShow({
    evento,
    resumen,
    jornadas,
    accesos,
    formulario,
    faltaParaPublicar,
    opciones,
    codigos,
    invitaciones,
    puede,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Eventos', href: EventoController.index() },
            { title: evento.name, href: EventoController.show(evento.id) },
        ],
    });

    const urlActualizar = EventoController.update.url(evento.id);
    const puedeCrearCuentas = usePage().props.auth.permisos.includes(
        'gestionar_cuentas_bancarias',
    );
    const filtro = { query: { evento: evento.id } };

    const detalleInscripciones = [
        `${resumen.pagadas} pagadas`,
        ...(resumen.por_vencer > 0 ? [`${resumen.por_vencer} por vencer`] : []),
    ].join(' · ');

    return (
        <>
            <Head title={evento.name} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight break-words">
                            {evento.name}
                        </h1>
                        <div className="flex items-center gap-2 text-sm">
                            <EstadoBadge estado={evento.estado} />
                            <span className="text-muted-foreground">
                                {evento.modalidad}
                            </span>
                            {evento.fecha && (
                                <span className="text-muted-foreground">
                                    · {evento.fecha}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {puede.editar && (
                            <CambiarEstado
                                evento={evento}
                                opciones={opciones.estados}
                                falta={faltaParaPublicar}
                            />
                        )}
                        {puede.eliminar && (
                            <Confirmar
                                titulo={`Eliminar «${evento.name}»`}
                                descripcion="Se eliminará con sus jornadas y tipos de acceso. Las inscripciones ya creadas no se pueden recuperar desde aquí."
                                textoAccion="Eliminar evento"
                                onConfirmar={() =>
                                    router.delete(
                                        EventoController.destroy.url(evento.id),
                                    )
                                }
                            >
                                <Button variant="destructive">
                                    <Trash2 />
                                    Eliminar
                                </Button>
                            </Confirmar>
                        )}
                    </div>
                </header>

                <SeccionFicha titulo="Cómo va este evento">
                    <div className="-m-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                        <Numero
                            titulo="Solicitudes"
                            valor={resumen.solicitudes}
                            detalle={`${resumen.solicitudes_sin_seguir} sin seguir`}
                            href={solicitudes.url(filtro)}
                        />
                        <Numero
                            titulo="Inscripciones"
                            valor={resumen.inscripciones}
                            detalle={detalleInscripciones}
                            href={inscripciones.url(filtro)}
                        />
                        {resumen.comprobantes !== null && (
                            <Numero
                                titulo="Comprobantes por revisar"
                                valor={resumen.comprobantes}
                                detalle="Esperando a Contabilidad"
                                href={comprobantes.url(filtro)}
                                alerta={resumen.comprobantes > 0}
                            />
                        )}
                        <Numero
                            titulo="Acreditados"
                            valor={`${resumen.acreditados} de ${resumen.credenciales}`}
                            detalle="Credenciales vigentes"
                        />
                    </div>
                </SeccionFicha>

                <SeccionFicha
                    titulo="Datos del evento"
                    accion={
                        puede.editar && (
                            <EditarSeccion
                                titulo="Datos del evento"
                                url={urlActualizar}
                                inicial={{
                                    name: evento.name,
                                    starts_on: evento.starts_on ?? '',
                                    modality: evento.modality,
                                    slug: evento.slug,
                                    description: evento.description ?? '',
                                }}
                            >
                                {(f) => (
                                    <>
                                        <Campo
                                            label="Nombre del evento"
                                            htmlFor="name"
                                            error={f.errors.name}
                                        >
                                            <Input
                                                id="name"
                                                value={f.data.name}
                                                maxLength={120}
                                                onChange={(e) =>
                                                    f.set(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Campo>
                                        <Campo
                                            label="Fecha del evento"
                                            htmlFor="starts_on"
                                            error={f.errors.starts_on}
                                        >
                                            <Input
                                                id="starts_on"
                                                type="date"
                                                value={f.data.starts_on}
                                                onChange={(e) =>
                                                    f.set(
                                                        'starts_on',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Campo>
                                        <Campo
                                            label="Modalidad"
                                            htmlFor="modality"
                                            error={f.errors.modality}
                                        >
                                            <NativeSelect
                                                id="modality"
                                                value={f.data.modality}
                                                onChange={(e) =>
                                                    f.set(
                                                        'modality',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                {Object.entries(
                                                    opciones.modalidades,
                                                ).map(([valor, texto]) => (
                                                    <option
                                                        key={valor}
                                                        value={valor}
                                                    >
                                                        {texto}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </Campo>
                                        <Campo
                                            label="Identificador en la URL"
                                            htmlFor="slug"
                                            error={f.errors.slug}
                                        >
                                            <Input
                                                id="slug"
                                                value={f.data.slug}
                                                onChange={(e) =>
                                                    f.set(
                                                        'slug',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {f.data.slug !== evento.slug && (
                                                <p className="text-sm text-amber-700 dark:text-amber-400">
                                                    Si el formulario ya está
                                                    puesto en otra página web,
                                                    dejará de funcionar con la
                                                    dirección anterior.
                                                </p>
                                            )}
                                        </Campo>
                                        <Campo
                                            label="Descripción"
                                            htmlFor="description"
                                            error={f.errors.description}
                                        >
                                            <Textarea
                                                id="description"
                                                rows={3}
                                                value={f.data.description}
                                                onChange={(e) =>
                                                    f.set(
                                                        'description',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Campo>
                                    </>
                                )}
                            </EditarSeccion>
                        )
                    }
                >
                    <Datos columnas={2}>
                        <Dato etiqueta="Nombre">{evento.name}</Dato>
                        <Dato etiqueta="Fecha del evento">
                            {evento.fecha ?? 'Sin fecha'}
                        </Dato>
                        <Dato etiqueta="Descripción">{evento.description}</Dato>
                        <Dato etiqueta="Modalidad">{evento.modalidad}</Dato>
                        <Dato
                            etiqueta="Enlace del formulario para inscribirse"
                            ancho
                        >
                            <span className="flex items-center gap-2">
                                <a
                                    href={evento.enlace_publico}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="truncate underline-offset-4 hover:underline"
                                >
                                    {evento.enlace_publico}
                                </a>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 shrink-0"
                                    aria-label="Copiar enlace"
                                    onClick={() => {
                                        void navigator.clipboard.writeText(
                                            evento.enlace_publico,
                                        );
                                        toast.success('Enlace copiado');
                                    }}
                                >
                                    <Copy />
                                </Button>
                            </span>
                        </Dato>
                    </Datos>
                </SeccionFicha>

                <Banner evento={evento} puedeEditar={puede.editar} />

                {puede.descuentos && (
                    <SeccionFicha
                        titulo="Códigos de descuento"
                        accion={
                            <IrA
                                href={CodigoDescuentoController.index.url(
                                    evento.id,
                                )}
                                texto="Ir a códigos de descuento"
                            />
                        }
                    >
                        <Lista
                            items={codigos}
                            vacio="Todavía no hay códigos."
                        />
                    </SeccionFicha>
                )}

                {puede.descuentos && (
                    <SeccionFicha
                        titulo="Invitaciones"
                        accion={
                            <IrA
                                href={InvitacionController.index.url(evento.id)}
                                texto="Ir a invitaciones"
                            />
                        }
                    >
                        <Lista
                            items={invitaciones}
                            vacio="Todavía no hay invitaciones."
                        />
                    </SeccionFicha>
                )}

                {evento.usa_lugar && (
                    <SeccionFicha
                        titulo="Lugar"
                        accion={
                            puede.editar && (
                                <EditarSeccion
                                    titulo="Lugar"
                                    url={urlActualizar}
                                    inicial={{
                                        location: evento.location ?? '',
                                        address: evento.address ?? '',
                                        region: evento.region ?? '',
                                        commune: evento.commune ?? '',
                                        city: evento.city ?? '',
                                    }}
                                >
                                    {(f) => (
                                        <>
                                            <Campo
                                                label="Recinto"
                                                htmlFor="location"
                                                error={f.errors.location}
                                            >
                                                <Input
                                                    id="location"
                                                    value={f.data.location}
                                                    placeholder="Centro de Eventos Casapiedra"
                                                    onChange={(e) =>
                                                        f.set(
                                                            'location',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Campo>
                                            <Campo
                                                label="Dirección"
                                                htmlFor="address"
                                                error={f.errors.address}
                                            >
                                                <Input
                                                    id="address"
                                                    value={f.data.address}
                                                    placeholder="Av. Kennedy 5413, piso 2"
                                                    onChange={(e) =>
                                                        f.set(
                                                            'address',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Campo>
                                            <Campo
                                                label="Región"
                                                htmlFor="region"
                                                error={f.errors.region}
                                            >
                                                <NativeSelect
                                                    id="region"
                                                    value={f.data.region}
                                                    onChange={(e) => {
                                                        f.set(
                                                            'region',
                                                            e.target.value,
                                                        );
                                                        f.set('commune', '');
                                                    }}
                                                >
                                                    <option value="">
                                                        Elige una región
                                                    </option>
                                                    {Object.keys(
                                                        opciones.regiones,
                                                    ).map((region) => (
                                                        <option
                                                            key={region}
                                                            value={region}
                                                        >
                                                            {region}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </Campo>
                                            <Campo
                                                label="Comuna"
                                                htmlFor="commune"
                                                error={f.errors.commune}
                                            >
                                                <NativeSelect
                                                    id="commune"
                                                    value={f.data.commune}
                                                    disabled={!f.data.region}
                                                    onChange={(e) =>
                                                        f.set(
                                                            'commune',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        {f.data.region
                                                            ? 'Elige una comuna'
                                                            : 'Elige primero la región'}
                                                    </option>
                                                    {(
                                                        opciones.regiones[
                                                            f.data.region
                                                        ] ?? []
                                                    ).map((comuna) => (
                                                        <option
                                                            key={comuna}
                                                            value={comuna}
                                                        >
                                                            {comuna}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </Campo>
                                            <Campo
                                                label="Ciudad"
                                                htmlFor="city"
                                                error={f.errors.city}
                                            >
                                                <Input
                                                    id="city"
                                                    value={f.data.city}
                                                    onChange={(e) =>
                                                        f.set(
                                                            'city',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Campo>
                                        </>
                                    )}
                                </EditarSeccion>
                            )
                        }
                    >
                        <Datos>
                            <Dato etiqueta="Recinto">{evento.location}</Dato>
                            <Dato etiqueta="Dirección">{evento.address}</Dato>
                            <Dato etiqueta="Región">{evento.region}</Dato>
                            <Dato etiqueta="Comuna">{evento.commune}</Dato>
                            <Dato etiqueta="Ciudad">{evento.city}</Dato>
                        </Datos>
                    </SeccionFicha>
                )}

                <SeccionFicha
                    titulo="Reserva de cupos"
                    accion={
                        puede.editar && (
                            <EditarSeccion
                                titulo="Reserva de cupos"
                                url={urlActualizar}
                                inicial={{
                                    reservation_duration_value: String(
                                        evento.reservation_duration_value,
                                    ),
                                    reservation_duration_unit:
                                        evento.reservation_duration_unit,
                                    reminder_hours_before:
                                        evento.reminder_hours_before
                                            ? String(
                                                  evento.reminder_hours_before,
                                              )
                                            : '',
                                }}
                            >
                                {(f) => (
                                    <>
                                        <Campo
                                            label="Tiempo para pagar antes de perder el cupo"
                                            htmlFor="reservation_duration_value"
                                            error={
                                                f.errors
                                                    .reservation_duration_value ??
                                                f.errors
                                                    .reservation_duration_unit
                                            }
                                        >
                                            <div className="flex flex-wrap items-center gap-3">
                                                <Input
                                                    id="reservation_duration_value"
                                                    type="number"
                                                    min={1}
                                                    className="w-24"
                                                    value={
                                                        f.data
                                                            .reservation_duration_value
                                                    }
                                                    onChange={(e) =>
                                                        f.set(
                                                            'reservation_duration_value',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                {Object.entries(
                                                    opciones.unidades,
                                                ).map(([valor, texto]) => (
                                                    <label
                                                        key={valor}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <input
                                                            type="radio"
                                                            name="reservation_duration_unit"
                                                            value={valor}
                                                            checked={
                                                                f.data
                                                                    .reservation_duration_unit ===
                                                                valor
                                                            }
                                                            onChange={() =>
                                                                f.set(
                                                                    'reservation_duration_unit',
                                                                    valor,
                                                                )
                                                            }
                                                        />
                                                        {texto}
                                                        {valor ===
                                                            'business_days' && (
                                                            <span className="text-muted-foreground">
                                                                (sin sábados ni
                                                                domingos)
                                                            </span>
                                                        )}
                                                    </label>
                                                ))}
                                            </div>
                                        </Campo>
                                        <Campo
                                            label="Aviso antes de que venza la reserva"
                                            htmlFor="reminder_hours_before"
                                            error={
                                                f.errors.reminder_hours_before
                                            }
                                        >
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    id="reminder_hours_before"
                                                    type="number"
                                                    min={1}
                                                    max={720}
                                                    className="w-24"
                                                    placeholder="48"
                                                    value={
                                                        f.data
                                                            .reminder_hours_before
                                                    }
                                                    onChange={(e) =>
                                                        f.set(
                                                            'reminder_hours_before',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                <span className="text-muted-foreground text-sm">
                                                    horas antes
                                                </span>
                                            </div>
                                        </Campo>
                                    </>
                                )}
                            </EditarSeccion>
                        )
                    }
                >
                    <Datos columnas={2}>
                        <Dato etiqueta="Tiempo para pagar antes de perder el cupo">
                            {evento.reserva}
                        </Dato>
                        <Dato etiqueta="Aviso antes de que venza">
                            {evento.aviso}
                        </Dato>
                        <Dato etiqueta="Hasta cuándo se aceptan reemplazos">
                            {evento.fecha_limite_reemplazos ??
                                'Falta la fecha del evento'}
                        </Dato>
                    </Datos>
                </SeccionFicha>

                <SeccionFicha
                    titulo="Cuenta para las transferencias"
                    accion={
                        puede.editar && (
                            <EditarSeccion
                                titulo="Cuenta para las transferencias"
                                url={urlActualizar}
                                inicial={{
                                    bank_account_id: evento.bank_account_id
                                        ? String(evento.bank_account_id)
                                        : '',
                                }}
                            >
                                {(f) => (
                                    <Campo
                                        label="Cuenta para recibir las transferencias"
                                        htmlFor="bank_account_id"
                                        error={f.errors.bank_account_id}
                                    >
                                        {puedeCrearCuentas && (
                                            <Link
                                                href={CuentaBancariaController.index.url(
                                                    {
                                                        query: { nueva: 1 },
                                                    },
                                                )}
                                                className="text-primary text-sm hover:underline"
                                            >
                                                {opciones.cuentas.length === 0
                                                    ? 'No hay cuentas activas: crear una'
                                                    : 'Crear otra cuenta'}
                                            </Link>
                                        )}
                                        {opciones.cuentas.length === 0 ? (
                                            !puedeCrearCuentas && (
                                                <p className="text-sm text-amber-700 dark:text-amber-400">
                                                    No hay cuentas activas. Pide
                                                    a Administración que cree
                                                    una.
                                                </p>
                                            )
                                        ) : (
                                            <NativeSelect
                                                id="bank_account_id"
                                                value={f.data.bank_account_id}
                                                onChange={(e) =>
                                                    f.set(
                                                        'bank_account_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Elige una cuenta
                                                </option>
                                                {opciones.cuentas.map(
                                                    (cuenta) => (
                                                        <option
                                                            key={cuenta.id}
                                                            value={cuenta.id}
                                                        >
                                                            {cuenta.label}
                                                        </option>
                                                    ),
                                                )}
                                            </NativeSelect>
                                        )}
                                    </Campo>
                                )}
                            </EditarSeccion>
                        )
                    }
                >
                    {evento.cuenta === null ? (
                        <p className="text-sm font-medium text-amber-700 dark:text-amber-400">
                            Debes elegir una cuenta primero.
                        </p>
                    ) : (
                        <Datos>
                            <Dato etiqueta="Cuenta elegida">
                                {evento.cuenta.label}
                            </Dato>
                            <Dato etiqueta="Banco">
                                {evento.cuenta.bank_name}
                            </Dato>
                            <Dato etiqueta="Número de cuenta">
                                {evento.cuenta.account_number}
                            </Dato>
                            <Dato etiqueta="Titular">
                                {evento.cuenta.holder_name}
                            </Dato>
                            <Dato etiqueta="RUT del titular">
                                {evento.cuenta.holder_rut}
                            </Dato>
                            <Dato etiqueta="Tipo de cuenta">
                                {evento.cuenta.account_type}
                            </Dato>
                            <Dato etiqueta="Instrucciones de pago" ancho>
                                {evento.cuenta.payment_instructions}
                            </Dato>
                        </Datos>
                    )}
                </SeccionFicha>

                <SeccionFicha
                    titulo="Jornadas y accesos"
                    accion={
                        puede.editar && (
                            <IrA
                                href={CuposYPreciosController.url(evento.id)}
                                texto="Ir a cupos y precios"
                            />
                        )
                    }
                >
                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="space-y-2">
                            <h3 className="text-muted-foreground text-sm">
                                Jornadas
                            </h3>
                            <Lista
                                items={jornadas}
                                vacio="Todavía no hay jornadas."
                            />
                        </div>
                        <div className="space-y-2">
                            <h3 className="text-muted-foreground text-sm">
                                Accesos que se venden
                            </h3>
                            <Lista
                                items={accesos}
                                vacio="Todavía no hay accesos a la venta."
                            />
                        </div>
                    </div>
                </SeccionFicha>

                <SeccionFicha
                    titulo="Formulario público"
                    accion={
                        puede.editar && (
                            <IrA
                                href={FormularioPublicoController.edit.url(
                                    evento.id,
                                )}
                                texto="Ir al formulario público"
                            />
                        )
                    }
                >
                    <Lista
                        items={formulario}
                        vacio="El formulario no tiene preguntas."
                    />
                </SeccionFicha>
            </div>
        </>
    );
}
