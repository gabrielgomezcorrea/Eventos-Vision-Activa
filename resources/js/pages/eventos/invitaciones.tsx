import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import InvitacionController from '@/actions/App/Http/Controllers/Eventos/InvitacionController';
import { Campo } from '@/components/campo';
import { Confirmar } from '@/components/confirmar';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import type { Estado } from '@/types';

type Invitacion = {
    id: number;
    email: string;
    acceso: string;
    vence: string;
    estado: Estado;
    pendiente: boolean;
};

type Acceso = { id: number; name: string; disponibles: number | null };

type Props = {
    evento: { id: number; name: string; starts_on: string | null };
    invitaciones: Invitacion[];
    accesos: Acceso[];
    hoy: string;
    sugerida: string | null;
};

/** Cuántos correos hay escritos: uno por línea, o separados por coma o punto y coma. */
function contarCorreos(texto: string): number {
    return texto.split(/[\s,;]+/).filter(Boolean).length;
}

function NuevaInvitacion({
    eventoId,
    accesos,
    hoy,
    sugerida,
}: {
    eventoId: number;
    accesos: Acceso[];
    hoy: string;
    sugerida: string | null;
}) {
    const [abierto, setAbierto] = useState(false);
    const inicial = {
        emails: '',
        access_type_id: accesos[0]?.id.toString() ?? '',
        expires_on: sugerida && sugerida >= hoy ? sugerida : '',
    };
    const form = useForm(inicial);

    const acceso = accesos.find(
        (a) => a.id.toString() === form.data.access_type_id,
    );
    const cantidad = contarCorreos(form.data.emails);
    const faltan = acceso?.disponibles != null && cantidad > acceso.disponibles;

    function enviar(e: FormEvent) {
        e.preventDefault();
        form.post(InvitacionController.store.url(eventoId), {
            preserveScroll: true,
            onSuccess: () => {
                setAbierto(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog
            open={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.setData(inicial);
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm" disabled={accesos.length === 0}>
                    <Plus />
                    Nueva invitación
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={enviar} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Nueva invitación</DialogTitle>
                        <DialogDescription>
                            Cada correo recibe su propio enlace, de un solo uso.
                        </DialogDescription>
                    </DialogHeader>

                    <Campo
                        label="Correos (uno por línea)"
                        htmlFor="invitacion-correos"
                        error={form.errors.emails}
                    >
                        <Textarea
                            id="invitacion-correos"
                            rows={5}
                            value={form.data.emails}
                            onChange={(e) =>
                                form.setData('emails', e.target.value)
                            }
                            placeholder={'ana@colegio.cl\nbeto@colegio.cl'}
                            required
                        />
                        {faltan && (
                            <p className="text-sm text-amber-700">
                                Son {cantidad} correos y quedan{' '}
                                {acceso?.disponibles} cupos.
                            </p>
                        )}
                    </Campo>

                    <div className="grid grid-cols-2 gap-4">
                        <Campo
                            label="Acceso"
                            htmlFor="invitacion-acceso"
                            error={form.errors.access_type_id}
                        >
                            <NativeSelect
                                id="invitacion-acceso"
                                value={form.data.access_type_id}
                                onChange={(e) =>
                                    form.setData(
                                        'access_type_id',
                                        e.target.value,
                                    )
                                }
                            >
                                {accesos.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                        {a.disponibles != null
                                            ? ` (quedan ${a.disponibles})`
                                            : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Campo>
                        <Campo
                            label="Válida hasta"
                            htmlFor="invitacion-vence"
                            error={form.errors.expires_on}
                        >
                            <Input
                                id="invitacion-vence"
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
                        {form.processing ? 'Enviando…' : 'Enviar invitaciones'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Invitaciones({
    evento,
    invitaciones,
    accesos,
    hoy,
    sugerida,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Eventos', href: EventoController.index() },
            { title: evento.name, href: EventoController.show(evento.id) },
            { title: 'Invitaciones', href: '#' },
        ],
    });

    return (
        <>
            <Head title={`Invitaciones · ${evento.name}`} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <SeccionFicha
                    titulo="Invitaciones"
                    className="[&>div]:p-0"
                    accion={
                        <NuevaInvitacion
                            eventoId={evento.id}
                            accesos={accesos}
                            hoy={hoy}
                            sugerida={sugerida}
                        />
                    }
                >
                    {invitaciones.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            Todavía no hay invitaciones.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Correo</TableHead>
                                    <TableHead>Acceso</TableHead>
                                    <TableHead>Válida hasta</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead className="w-0" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invitaciones.map((invitacion) => (
                                    <TableRow key={invitacion.id}>
                                        <TableCell className="font-medium">
                                            {invitacion.email}
                                        </TableCell>
                                        <TableCell>
                                            {invitacion.acceso}
                                        </TableCell>
                                        <TableCell>
                                            {invitacion.vence}
                                        </TableCell>
                                        <TableCell>
                                            <EstadoBadge
                                                estado={invitacion.estado}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {invitacion.pendiente && (
                                                <Confirmar
                                                    titulo={`Anular la invitación a ${invitacion.email}`}
                                                    descripcion="Su enlace dejará de funcionar. Después puedes invitarla de nuevo."
                                                    textoAccion="Anular"
                                                    onConfirmar={() =>
                                                        router.patch(
                                                            InvitacionController.anular.url(
                                                                [
                                                                    evento.id,
                                                                    invitacion.id,
                                                                ],
                                                            ),
                                                            {},
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
                                                        Anular
                                                    </Button>
                                                </Confirmar>
                                            )}
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
