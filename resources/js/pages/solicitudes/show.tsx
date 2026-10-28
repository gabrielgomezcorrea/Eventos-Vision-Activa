import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Mail, Trash2 } from 'lucide-react';
import { useState } from 'react';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import SolicitudController from '@/actions/App/Http/Controllers/Solicitudes/SolicitudController';
import { Confirmar } from '@/components/confirmar';
import { EstadoBadge } from '@/components/estado-badge';
import { NotasConFecha } from '@/components/notas-con-fecha';
import type { Nota } from '@/components/notas-con-fecha';
import { Dato, Datos, SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Estado } from '@/types';

type Props = {
    solicitud: {
        id: number;
        persona: string;
        correo: string;
        cargo: string | null;
        institucion: string | null;
        telefono: string | null;
        interes: string | null;
        evento: { id: number; nombre: string } | null;
        estado: Estado;
        inscrita: boolean;
        llego: string;
        programa_enviado: string | null;
        se_inscribio: string | null;
        desde: string | null;
        campana: string | null;
        internal_notes: string | null;
    };
    respuestas: { pregunta: string; respuesta: string }[];
    notas: Nota[];
    puede: { gestionar: boolean; eliminar: boolean; eliminar_todo: boolean };
    relacionado: {
        inscripciones: number;
        participantes: number;
        pagos: number;
    } | null;
};

export default function SolicitudShow({
    solicitud,
    respuestas,
    notas,
    puede,
    relacionado,
}: Props) {
    const [segundaConfirmacion, setSegundaConfirmacion] = useState(false);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Solicitudes', href: SolicitudController.index() },
            {
                title: solicitud.persona,
                href: SolicitudController.show(solicitud.id),
            },
        ],
    });

    const opciones = { preserveScroll: true };

    return (
        <>
            <Head title={solicitud.persona} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {solicitud.persona}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <EstadoBadge estado={solicitud.estado} />
                            {solicitud.evento && (
                                <Link
                                    href={EventoController.show(
                                        solicitud.evento.id,
                                    )}
                                    className="text-muted-foreground hover:underline"
                                >
                                    {solicitud.evento.nombre}
                                </Link>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {puede.gestionar && (
                            <Confirmar
                                titulo="Reenviar el programa"
                                descripcion={`Se enviará de nuevo a ${solicitud.correo}, con el programa adjunto y el enlace para inscribirse.`}
                                textoAccion="Enviar"
                                destructivo={false}
                                onConfirmar={() =>
                                    router.post(
                                        SolicitudController.reenviarPrograma.url(
                                            solicitud.id,
                                        ),
                                        {},
                                        opciones,
                                    )
                                }
                            >
                                <Button variant="outline">
                                    <Mail />
                                    Reenviar el programa
                                </Button>
                            </Confirmar>
                        )}
                        {puede.eliminar && (
                            <Confirmar
                                titulo={`Eliminar la solicitud de ${solicitud.persona}`}
                                descripcion={
                                    puede.eliminar_todo &&
                                    relacionado &&
                                    relacionado.inscripciones > 0
                                        ? `«Eliminar solicitud» borra solo el contacto y sus notas. «Limpiar solicitud» borra además ${relacionado.inscripciones} inscripción(es) con ${relacionado.participantes} participante(s) y ${relacionado.pagos} pago(s) de este correo, con sus comprobantes, facturas y credenciales, y devuelve los cupos. No se puede recuperar.`
                                        : 'Se borra el contacto y sus respuestas. No se puede recuperar.'
                                }
                                textoAccion="Eliminar solicitud"
                                accionExtra={
                                    puede.eliminar_todo
                                        ? {
                                              texto: 'Limpiar solicitud',
                                              onConfirmar: () =>
                                                  setSegundaConfirmacion(true),
                                          }
                                        : undefined
                                }
                                onConfirmar={() =>
                                    router.delete(
                                        SolicitudController.destroy.url(
                                            solicitud.id,
                                        ),
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
                    <Dialog
                        open={segundaConfirmacion}
                        onOpenChange={setSegundaConfirmacion}
                    >
                        <DialogContent>
                            <DialogTitle>
                                ¿Seguro? Esto no se puede deshacer
                            </DialogTitle>
                            <DialogDescription>
                                Se eliminará todo lo relacionado con este correo
                                en el evento: inscripciones, participantes,
                                pagos, comprobantes, credenciales, enlaces e
                                invitaciones.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">
                                        Cancelar
                                    </Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        router.delete(
                                            SolicitudController.destroyAll.url(
                                                solicitud.id,
                                            ),
                                        );
                                        setSegundaConfirmacion(false);
                                    }}
                                >
                                    Sí, eliminar todo
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </header>

                <SeccionFicha titulo="Quién la envió">
                    <Datos>
                        <Dato etiqueta="Correo">
                            <a
                                href={`mailto:${solicitud.correo}`}
                                className="break-all hover:underline"
                            >
                                {solicitud.correo}
                            </a>
                        </Dato>
                        <Dato etiqueta="Teléfono">
                            {solicitud.telefono && (
                                <a
                                    href={`tel:${solicitud.telefono}`}
                                    className="hover:underline"
                                >
                                    {solicitud.telefono}
                                </a>
                            )}
                        </Dato>
                        <Dato etiqueta="Cargo">{solicitud.cargo}</Dato>
                        <Dato etiqueta="Institución">
                            {solicitud.institucion}
                        </Dato>
                        {solicitud.interes && (
                            <Dato etiqueta="Jornada de interés">
                                {solicitud.interes}
                            </Dato>
                        )}
                    </Datos>
                </SeccionFicha>

                {respuestas.length > 0 && (
                    <SeccionFicha titulo="Respuestas adicionales">
                        <Datos>
                            {respuestas.map((r) => (
                                <Dato key={r.pregunta} etiqueta={r.pregunta}>
                                    {r.respuesta}
                                </Dato>
                            ))}
                        </Datos>
                    </SeccionFicha>
                )}

                <SeccionFicha titulo="Fechas">
                    <Datos>
                        <Dato etiqueta="Llegó">{solicitud.llego}</Dato>
                        <Dato etiqueta="Programa enviado">
                            {solicitud.programa_enviado ?? 'Todavía no'}
                        </Dato>
                        <Dato etiqueta="Se inscribió">
                            {solicitud.se_inscribio ?? 'Todavía no'}
                        </Dato>
                        <Dato etiqueta="Campaña">
                            {solicitud.campana ?? 'Sin campaña'}
                        </Dato>
                        <Dato etiqueta="Desde qué página la envió" ancho>
                            {solicitud.desde ?? 'Entró directo'}
                        </Dato>
                    </Datos>
                </SeccionFicha>

                <NotasConFecha
                    url={SolicitudController.guardarNota.url(solicitud.id)}
                    notas={notas}
                />

                {solicitud.internal_notes && (
                    <SeccionFicha titulo="Notas anteriores">
                        <p className="text-sm whitespace-pre-line">
                            {solicitud.internal_notes}
                        </p>
                    </SeccionFicha>
                )}
            </div>
        </>
    );
}
