import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus, RefreshCw } from 'lucide-react';
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
    id: number;
    nombre: string;
    rut: string | null;
    email: string;
    colegio: string | null;
    folio: string | null;
    orden_id: number;
};

type Codigo = {
    personas: Persona[];
    id: number;
    code: string;
    type: string;
    value: number;
    etiqueta: string;
    max_people: number;
    used_people: number;
    expires_on: string;
    vence: string;
    is_active: boolean;
    estado: Estado;
};

type Props = {
    evento: { id: number; name: string };
    codigos: Codigo[];
    sugerido: string;
    hoy: string;
    opciones: { tiposDescuento: Record<string, string> };
};

/** Crear pide lo mínimo y nace con un código ya generado: se puede usar tal cual. */
function NuevoCodigo({
    eventoId,
    sugerido,
    hoy,
    tipos,
}: {
    eventoId: number;
    sugerido: string;
    hoy: string;
    tipos: Record<string, string>;
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm({
        code: sugerido,
        type: 'full',
        value: '',
        max_people: '',
        expires_on: '',
    });

    function generarOtro() {
        router.reload({
            only: ['sugerido'],
            onSuccess: (pagina) =>
                form.setData('code', pagina.props.sugerido as string),
        });
    }

    function crear(e: FormEvent) {
        e.preventDefault();
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
                        {form.data.type !== 'full' && (
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

                    <div className="grid grid-cols-2 gap-4">
                        <Campo
                            label="Máx. personas que pueden usarlo"
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

                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Creando…' : 'Crear código'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Quiénes usaron el código, con lo básico que dejaron al inscribirse. */
function PersonasDelCodigo({ codigo }: { codigo: Codigo }) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="link" className="h-auto p-0">
                    {codigo.used_people} de {codigo.max_people}
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Quiénes usaron {codigo.code}</SheetTitle>
                    <SheetDescription className="sr-only">
                        Personas inscritas con este código
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
                                key={persona.id}
                                className="space-y-0.5 border-b pb-3 text-sm last:border-0"
                            >
                                <p className="font-medium">{persona.nombre}</p>
                                <p className="text-muted-foreground">
                                    {[persona.rut, persona.email]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                {persona.colegio && (
                                    <p className="text-muted-foreground">
                                        {persona.colegio}
                                    </p>
                                )}
                                <Link
                                    href={inscripcion.url(persona.orden_id)}
                                    className="text-primary underline-offset-4 hover:underline"
                                >
                                    Inscripción {persona.folio}
                                </Link>
                            </div>
                        ))
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

export default function CodigosDeDescuento({
    evento,
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
                            tipos={opciones.tiposDescuento}
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
                                        <TableCell>{codigo.etiqueta}</TableCell>
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
                                                        expires_on:
                                                            codigo.expires_on,
                                                    }}
                                                >
                                                    {(f) => (
                                                        <>
                                                            <Campo
                                                                label="Máx. personas que pueden usarlo"
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
                                                                        codigo.used_people,
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
