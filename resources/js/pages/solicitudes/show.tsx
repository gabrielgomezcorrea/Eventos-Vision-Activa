import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { CheckCircle2, Mail, Trash2, Undo2 } from 'lucide-react';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import SolicitudController from '@/actions/App/Http/Controllers/Solicitudes/SolicitudController';
import { Campo } from '@/components/campo';
import { Confirmar } from '@/components/confirmar';
import { EditarSeccion } from '@/components/editar-seccion';
import { EstadoBadge } from '@/components/estado-badge';
import { Dato, Datos, SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
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
    puede: { gestionar: boolean; eliminar: boolean };
};

export default function SolicitudShow({ solicitud, respuestas, puede }: Props) {
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
                                titulo={
                                    solicitud.inscrita
                                        ? 'Deshacer la marca'
                                        : 'Marcar que se inscribió'
                                }
                                descripcion={
                                    solicitud.inscrita
                                        ? 'La solicitud vuelve a quedar como pendiente de seguimiento.'
                                        : `Queda registrado que ${solicitud.persona} ya hizo su inscripción.`
                                }
                                textoAccion={
                                    solicitud.inscrita
                                        ? 'Deshacer'
                                        : 'Confirmar'
                                }
                                destructivo={false}
                                onConfirmar={() =>
                                    router.post(
                                        SolicitudController.alternarInscrita.url(
                                            solicitud.id,
                                        ),
                                        {},
                                        opciones,
                                    )
                                }
                            >
                                <Button
                                    variant={
                                        solicitud.inscrita
                                            ? 'outline'
                                            : 'default'
                                    }
                                >
                                    {solicitud.inscrita ? (
                                        <Undo2 />
                                    ) : (
                                        <CheckCircle2 />
                                    )}
                                    {solicitud.inscrita
                                        ? 'Deshacer: no se inscribió'
                                        : 'Marcar que se inscribió'}
                                </Button>
                            </Confirmar>
                        )}
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
                                descripcion="Se borra el contacto y sus respuestas. No se puede recuperar."
                                textoAccion="Eliminar solicitud"
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

                <SeccionFicha titulo="Seguimiento">
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

                <SeccionFicha
                    titulo="Notas internas"
                    accion={
                        puede.gestionar && (
                            <EditarSeccion
                                titulo="Notas internas"
                                url={SolicitudController.actualizarNotas.url(
                                    solicitud.id,
                                )}
                                inicial={{
                                    internal_notes:
                                        solicitud.internal_notes ?? '',
                                }}
                            >
                                {(f) => (
                                    <Campo
                                        label="Lo que convenga recordar de este contacto"
                                        htmlFor="internal_notes"
                                        error={f.errors.internal_notes}
                                    >
                                        <Textarea
                                            id="internal_notes"
                                            rows={6}
                                            value={f.data.internal_notes}
                                            onChange={(e) =>
                                                f.set(
                                                    'internal_notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Campo>
                                )}
                            </EditarSeccion>
                        )
                    }
                >
                    <p
                        className={cn(
                            'text-sm whitespace-pre-line',
                            !solicitud.internal_notes &&
                                'text-muted-foreground',
                        )}
                    >
                        {solicitud.internal_notes || 'Sin notas.'}
                    </p>
                </SeccionFicha>
            </div>
        </>
    );
}
