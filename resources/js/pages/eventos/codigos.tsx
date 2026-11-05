import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CodigoDescuentoController from '@/actions/App/Http/Controllers/Eventos/CodigoDescuentoController';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import { Campo } from '@/components/campo';
import { Confirmar } from '@/components/confirmar';
import { EditarSeccion } from '@/components/editar-seccion';
import { EstadoBadge } from '@/components/estado-badge';
import { NativeSelect } from '@/components/native-select';
import { SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
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
import { show as inscripcion } from '@/routes/inscripciones';
import type { Estado } from '@/types';

type Persona = {
    correo: string;
    nombre: string;
    primer_uso: string | null;
    inscripciones: {
        orden_id: number;
        folio: string | null;
        colegio: string | null;
    }[];
};

type Codigo = {
    personas: Persona[];
    id: number;
    code: string;
    type: string;
    value: number;
    etiqueta: string;
    precios: {
        id: number;
        nombre: string;
        precio: number;
        precio_label: string;
    }[];
    max_people: number;
    usado_por: number;
    uses_per_person: string;
    usos_texto: string;
    expires_on: string;
    vence: string;
    is_active: boolean;
    estado: Estado;
};

type Acceso = { id: number; name: string; price: number; price_label: string };

type FilaPrecio = { access_type_id: string; price: string };

type Props = {
    evento: { id: number; name: string };
    accesos: Acceso[];
    codigos: Codigo[];
    sugerido: string;
    hoy: string;
    opciones: {
        tiposDescuento: Record<string, string>;
        usosPorPersona: Record<string, string>;
    };
};

/** «Por jornada»: una fila por acceso con su precio final por persona. */
function FilasPorJornada({
    accesos,
    filas,
    errores,
    onCambiar,
    onAgregar,
    onQuitar,
}: {
    accesos: Acceso[];
    filas: FilaPrecio[];
    errores: Record<string, string>;
    onCambiar: (indice: number, cambios: Partial<FilaPrecio>) => void;
    onAgregar: () => void;
    onQuitar: (indice: number) => void;
}) {
    if (accesos.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Este evento todavía no tiene accesos.
            </p>
        );
    }

    const elegidos = filas.map((fila) => fila.access_type_id);

    return (
        <div className="space-y-3">
            {errores.prices && (
                <p className="text-sm text-red-600">{errores.prices}</p>
            )}
            {filas.map((fila, indice) => (
                <div key={indice} className="space-y-1">
                    <div className="flex items-start gap-2">
                        <NativeSelect
                            aria-label="Acceso"
                            value={fila.access_type_id}
                            onChange={(e) =>
                                onCambiar(indice, {
                                    access_type_id: e.target.value,
                                })
                            }
                            required
                        >
                            <option value="">Elige un acceso</option>
                            {accesos
                                .filter(
                                    (acceso) =>
                                        String(acceso.id) ===
                                            fila.access_type_id ||
                                        !elegidos.includes(String(acceso.id)),
                                )
                                .map((acceso) => (
                                    <option key={acceso.id} value={acceso.id}>
                                        {acceso.name} — {acceso.price_label}
                                    </option>
                                ))}
                        </NativeSelect>
                        <Input
                            aria-label="Precio especial en pesos"
                            type="number"
                            min={0}
                            placeholder="Precio $"
                            className="w-36"
                            value={fila.price}
                            onChange={(e) =>
                                onCambiar(indice, { price: e.target.value })
                            }
                            required
                        />
                        {filas.length > 1 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Quitar esta jornada"
                                onClick={() => onQuitar(indice)}
                            >
                                <Trash2 />
                            </Button>
                        )}
                    </div>
                    {(errores[`prices.${indice}.access_type_id`] ||
                        errores[`prices.${indice}.price`]) && (
                        <p className="text-sm text-red-600">
                            {errores[`prices.${indice}.access_type_id`] ??
                                errores[`prices.${indice}.price`]}
                        </p>
                    )}
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={filas.length >= accesos.length}
                onClick={onAgregar}
            >
                <Plus />
                Agregar jornada
            </Button>
        </div>
    );
}

/** Crear pide lo mínimo y nace con un código ya generado: se puede usar tal cual. */
function NuevoCodigo({
    eventoId,
    sugerido,
    hoy,
    tipos,
    usos,
    accesos,
}: {
    eventoId: number;
    accesos: Acceso[];
    sugerido: string;
    hoy: string;
    tipos: Record<string, string>;
    usos: Record<string, string>;
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm<{
        code: string;
        type: string;
        value: string;
        prices: FilaPrecio[];
        max_people: string;
        uses_per_person: string;
        expires_on: string;
    }>({
        code: sugerido,
        type: 'full',
        value: '',
        prices: [{ access_type_id: '', price: '' }],
        max_people: '',
        uses_per_person: 'unlimited',
        expires_on: '',
    });

    function generarOtro() {
        router.reload({
            only: ['sugerido'],
            onSuccess: (pagina) =>
                form.setData('code', pagina.props.sugerido as string),
        });
    }

    const porJornada = form.data.type === 'per_access';

    function cambiarFila(indice: number, cambios: Partial<FilaPrecio>) {
        form.setData(
            'prices',
            form.data.prices.map((fila, i) =>
                i === indice ? { ...fila, ...cambios } : fila,
            ),
        );
    }

    function crear(e: FormEvent) {
        e.preventDefault();
        // Las filas de precios solo viajan con «Por jornada».
        form.transform((datos) => ({
            ...datos,
            prices: datos.type === 'per_access' ? datos.prices : [],
        }));
        form.post(CodigoDescuentoController.store.url(eventoId), {
            preserveScroll: true,
            onSuccess: () => {
                setAbierto(false);
                router.reload({ only: ['sugerido'] });
            },
        });
    }

    return (
        <Dialog
            open={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.setData('code', sugerido);
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus />
                    Nuevo código
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={crear} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Nuevo código de descuento</DialogTitle>
                        <DialogDescription className="sr-only">
                            Crear un código de descuento
                        </DialogDescription>
                    </DialogHeader>

                    <Campo
                        label="Código"
                        htmlFor="codigo"
                        error={form.errors.code}
                    >
                        <div className="flex gap-2">
                            <Input
                                id="codigo"
                                value={form.data.code}
                                maxLength={24}
                                className="font-mono tracking-wider uppercase"
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                required
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={generarOtro}
                            >
                                <RefreshCw />
                                Generar
                            </Button>
                        </div>
                    </Campo>

                    <div className="grid grid-cols-2 gap-4">
                        <Campo
                            label="Tipo"
                            htmlFor="codigo-tipo"
                            error={form.errors.type}
                        >
                            <NativeSelect
                                id="codigo-tipo"
                                value={form.data.type}
                                onChange={(e) =>
                                    form.setData('type', e.target.value)
                                }
                            >
                                {Object.entries(tipos).map(([valor, texto]) => (
                                    <option key={valor} value={valor}>
                                        {texto}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Campo>
                        {form.data.type !== 'full' && !porJornada && (
                            <Campo
                                label={
                                    form.data.type === 'percent'
                                        ? 'Porcentaje'
                                        : 'Monto a rebajar'
                                }
                                htmlFor="codigo-valor"
                                error={form.errors.value}
                            >
                                <Input
                                    id="codigo-valor"
                                    type="number"
                                    min={1}
                                    max={
                                        form.data.type === 'percent'
                                            ? 100
                                            : undefined
                                    }
                                    value={form.data.value}
                                    onChange={(e) =>
                                        form.setData('value', e.target.value)
                                    }
                                    required
                                />
                            </Campo>
                        )}
                    </div>

                    {porJornada && (
                        <FilasPorJornada
                            accesos={accesos}
                            filas={form.data.prices}
                            errores={form.errors as Record<string, string>}
                            onCambiar={cambiarFila}
                            onAgregar={() =>
                                form.setData('prices', [
                                    ...form.data.prices,
                                    { access_type_id: '', price: '' },
                                ])
                            }
                            onQuitar={(indice) =>
                                form.setData(
                                    'prices',
                                    form.data.prices.filter(
                                        (_, i) => i !== indice,
                                    ),
                                )
                            }
                        />
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <Campo
                            label="¿Cuántas personas pueden usarlo?"
                            htmlFor="codigo-max"
                            error={form.errors.max_people}
                        >
                            <Input
                                id="codigo-max"
                                type="number"
                                min={1}
                                max={99}
                                value={form.data.max_people}
                                onChange={(e) =>
                                    form.setData('max_people', e.target.value)
                                }
                                required
                            />
                        </Campo>
                        <Campo
                            label="Válido hasta"
                            htmlFor="codigo-vence"
                            error={form.errors.expires_on}
                        >
                            <Input
                                id="codigo-vence"
                                type="date"
                                min={hoy}
                                value={form.data.expires_on}
                                onChange={(e) =>
                                    form.setData('expires_on', e.target.value)
                                }
                                required
                            />
                        </Campo>
                    </div>

                    <Campo
                        label="Usos por persona"
                        htmlFor="codigo-usos"
                        error={form.errors.uses_per_person}
                    >
                        <NativeSelect
                            id="codigo-usos"
                            value={form.data.uses_per_person}
                            onChange={(e) =>
                                form.setData('uses_per_person', e.target.value)
                            }
                        >
                            {Object.entries(usos).map(([valor, texto]) => (
                                <option key={valor} value={valor}>
                                    {texto}
                                </option>
                            ))}
                        </NativeSelect>
                    </Campo>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Creando…' : 'Crear código'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Quiénes usaron el código: una persona por correo, con lo que inscribió. */
function PersonasDelCodigo({ codigo }: { codigo: Codigo }) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="link" className="h-auto p-0">
                    {codigo.usado_por} de {codigo.max_people}
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Quiénes usaron {codigo.code}</SheetTitle>
                    <SheetDescription className="sr-only">
                        Personas que usaron este código
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-4 px-4 pb-6">
                    {codigo.personas.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Nadie lo ha usado todavía.
                        </p>
                    ) : (
                        codigo.personas.map((persona) => (
                            <div
                                key={persona.correo}
                                className="space-y-0.5 border-b pb-3 text-sm last:border-0"
                            >
                                <p className="font-medium">{persona.nombre}</p>
                                <p className="text-muted-foreground">
                                    {persona.correo}
                                </p>
                                {persona.primer_uso && (
                                    <p className="text-muted-foreground">
                                        Lo usó el {persona.primer_uso}
                                    </p>
                                )}
                                {persona.inscripciones.map((i) => (
                                    <Link
                                        key={i.orden_id}
                                        href={inscripcion.url(i.orden_id)}
                                        className="text-primary block underline-offset-4 hover:underline"
                                    >
                                        Inscripción {i.folio}
                                        {i.colegio ? ` · ${i.colegio}` : ''}
                                    </Link>
                                ))}
                            </div>
                        ))
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

/** «Jornada 1: $80.000 · Ambas Jornadas: $140.000» */
function resumenDePrecios(codigo: Codigo): string {
    return codigo.precios
        .map((fila) => `${fila.nombre}: ${fila.precio_label}`)
        .join(' · ');
}

export default function CodigosDeDescuento({
    evento,
    accesos,
    codigos,
    sugerido,
    hoy,
    opciones,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Eventos', href: EventoController.index() },
            { title: evento.name, href: EventoController.show(evento.id) },
            { title: 'Códigos de descuento', href: '#' },
        ],
    });

    function cambiarActivo(codigo: Codigo, activo: boolean) {
        router.patch(
            CodigoDescuentoController.update.url([evento.id, codigo.id]),
            { is_active: activo ? '1' : '0' },
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title={`Códigos de descuento · ${evento.name}`} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <SeccionFicha
                    titulo="Códigos de descuento"
                    className="[&>div]:p-0"
                    accion={
                        <NuevoCodigo
                            eventoId={evento.id}
                            sugerido={sugerido}
                            hoy={hoy}
                            accesos={accesos}
                            tipos={opciones.tiposDescuento}
                            usos={opciones.usosPorPersona}
                        />
                    }
                >
                    {codigos.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            Todavía no hay códigos.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Código</TableHead>
                                    <TableHead>Descuento</TableHead>
                                    <TableHead>Personas</TableHead>
                                    <TableHead>Válido hasta</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead className="w-0" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {codigos.map((codigo) => (
                                    <TableRow key={codigo.id}>
                                        <TableCell className="font-mono font-medium tracking-wider">
                                            {codigo.code}
                                        </TableCell>
                                        <TableCell>
                                            {codigo.etiqueta}
                                            {codigo.precios.length > 0 && (
                                                <span className="text-muted-foreground block text-xs">
                                                    {resumenDePrecios(codigo)}
                                                </span>
                                            )}
                                            <span className="text-muted-foreground block text-xs">
                                                {codigo.usos_texto}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <PersonasDelCodigo
                                                codigo={codigo}
                                            />
                                        </TableCell>
                                        <TableCell>{codigo.vence}</TableCell>
                                        <TableCell>
                                            <EstadoBadge
                                                estado={codigo.estado}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-1">
                                                <EditarSeccion
                                                    titulo={`Código ${codigo.code}`}
                                                    url={CodigoDescuentoController.update.url(
                                                        [evento.id, codigo.id],
                                                    )}
                                                    inicial={{
                                                        max_people:
                                                            codigo.max_people.toString(),
                                                        uses_per_person:
                                                            codigo.uses_per_person,
                                                        expires_on:
                                                            codigo.expires_on,
                                                    }}
                                                >
                                                    {(f) => (
                                                        <>
                                                            {codigo.precios
                                                                .length > 0 && (
                                                                <p className="text-sm">
                                                                    {resumenDePrecios(
                                                                        codigo,
                                                                    )}
                                                                </p>
                                                            )}
                                                            <Campo
                                                                label="¿Cuántas personas pueden usarlo?"
                                                                htmlFor={`max-${codigo.id}`}
                                                                error={
                                                                    f.errors
                                                                        .max_people
                                                                }
                                                            >
                                                                <Input
                                                                    id={`max-${codigo.id}`}
                                                                    type="number"
                                                                    min={Math.max(
                                                                        1,
                                                                        codigo.usado_por,
                                                                    )}
                                                                    max={99}
                                                                    value={
                                                                        f.data
                                                                            .max_people
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        f.set(
                                                                            'max_people',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                            </Campo>
                                                            <Campo
                                                                label="Usos por persona"
                                                                htmlFor={`usos-${codigo.id}`}
                                                                error={
                                                                    f.errors
                                                                        .uses_per_person
                                                                }
                                                            >
                                                                <NativeSelect
                                                                    id={`usos-${codigo.id}`}
                                                                    value={
                                                                        f.data
                                                                            .uses_per_person
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        f.set(
                                                                            'uses_per_person',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                >
                                                                    {Object.entries(
                                                                        opciones.usosPorPersona,
                                                                    ).map(
                                                                        ([
                                                                            valor,
                                                                            texto,
                                                                        ]) => (
                                                                            <option
                                                                                key={
                                                                                    valor
                                                                                }
                                                                                value={
                                                                                    valor
                                                                                }
                                                                            >
                                                                                {
                                                                                    texto
                                                                                }
                                                                            </option>
                                                                        ),
                                                                    )}
                                                                </NativeSelect>
                                                            </Campo>
                                                            <Campo
                                                                label="Válido hasta"
                                                                htmlFor={`vence-${codigo.id}`}
                                                                error={
                                                                    f.errors
                                                                        .expires_on
                                                                }
                                                            >
                                                                <Input
                                                                    id={`vence-${codigo.id}`}
                                                                    type="date"
                                                                    min={hoy}
                                                                    value={
                                                                        f.data
                                                                            .expires_on
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        f.set(
                                                                            'expires_on',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                            </Campo>
                                                        </>
                                                    )}
                                                </EditarSeccion>
                                                {codigo.is_active ? (
                                                    <Confirmar
                                                        titulo={`Desactivar ${codigo.code}`}
                                                        descripcion="Nadie más podrá usarlo. Las inscripciones que ya lo usaron no cambian."
                                                        textoAccion="Desactivar"
                                                        destructivo={false}
                                                        onConfirmar={() =>
                                                            cambiarActivo(
                                                                codigo,
                                                                false,
                                                            )
                                                        }
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            Desactivar
                                                        </Button>
                                                    </Confirmar>
                                                ) : (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            cambiarActivo(
                                                                codigo,
                                                                true,
                                                            )
                                                        }
                                                    >
                                                        Activar
                                                    </Button>
                                                )}
                                                {codigo.usado_por === 0 && (
                                                    <Confirmar
                                                        titulo={`Eliminar el código ${codigo.code}`}
                                                        descripcion="Nadie lo ha usado todavía. Si alguien lo tenía puesto sin confirmar su inscripción, deja de tener el descuento. No se puede recuperar."
                                                        textoAccion="Eliminar código"
                                                        onConfirmar={() =>
                                                            router.delete(
                                                                CodigoDescuentoController.destroy.url(
                                                                    [
                                                                        evento.id,
                                                                        codigo.id,
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
                                                            size="sm"
                                                            className="text-red-600 hover:text-red-700"
                                                        >
                                                            Eliminar
                                                        </Button>
                                                    </Confirmar>
                                                )}
                                            </div>
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
