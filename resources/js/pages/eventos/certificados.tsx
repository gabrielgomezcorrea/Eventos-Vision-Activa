import { Head, router, setLayoutProps, usePoll } from '@inertiajs/react';
import { Pause, Play, Send, Upload } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import { toast } from 'sonner';
import CertificadoController from '@/actions/App/Http/Controllers/Eventos/CertificadoController';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import { Confirmar } from '@/components/confirmar';
import { EstadoBadge } from '@/components/estado-badge';
import { Buscador } from '@/components/buscador';
import { SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { Estado } from '@/types';

type Certificado = {
    id: number;
    archivo: string;
    nombre_leido: string | null;
    participant_id: number | null;
    persona: string | null;
    correo: string | null;
    sin_correo: boolean;
    dudoso: boolean;
    estado: Estado;
    se_edita: boolean;
    se_reenvia: boolean;
    enviado: string | null;
    error: string | null;
};

type Persona = { id: number; nombre: string; detalle: string };

type Campana = {
    estado: Estado;
    enviando: boolean;
    pausada: boolean;
    motivo: string | null;
    proximo: string | null;
};

type Props = {
    evento: { id: number; name: string };
    certificados: Certificado[];
    personas: Persona[];
    campana: Campana | null;
    ritmo: { minimo: number; maximo: number; tope: number };
    subida: { por_vez: number; max_mb: number };
};

type Resultado = { subidos: number; asignados: number; repetidos: number };

type VistaId = 'revisar' | 'listos' | 'envio' | 'enviados' | 'fallidos';

const VISTAS: {
    id: VistaId;
    label: string;
    descripcion: string;
    estados: string[];
}[] = [
    {
        id: 'revisar',
        label: 'Por revisar',
        descripcion: 'Sin persona o con dudas',
        estados: ['review'],
    },
    {
        id: 'listos',
        label: 'Listos',
        descripcion: 'Con persona, sin enviar',
        estados: ['ready'],
    },
    {
        id: 'envio',
        label: 'En envío',
        descripcion: 'Esperando su turno',
        estados: ['queued', 'sending'],
    },
    {
        id: 'enviados',
        label: 'Enviados',
        descripcion: 'Ya llegaron al correo',
        estados: ['sent'],
    },
    {
        id: 'fallidos',
        label: 'No enviados',
        descripcion: 'Fallaron; se reintentan',
        estados: ['failed'],
    },
];

function Subir({
    eventoId,
    subida,
}: {
    eventoId: number;
    subida: Props['subida'];
}) {
    const entrada = useRef<HTMLInputElement>(null);
    const [progreso, setProgreso] = useState<{
        hechos: number;
        total: number;
    } | null>(null);

    async function subirTanda(archivos: File[]): Promise<Resultado | null> {
        return new Promise((resolver) => {
            let resultado: Resultado | null = null;

            router.post(
                CertificadoController.store.url(eventoId),
                { certificados: archivos },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    preserveState: true,
                    onFlash: (flash) => {
                        resultado =
                            (flash as { certificados?: Resultado })
                                .certificados ?? null;
                    },
                    onError: (errores) => {
                        toast.error(
                            Object.values(errores)[0] ??
                                'No se pudo subir una tanda.',
                        );
                    },
                    onFinish: () => resolver(resultado),
                },
            );
        });
    }

    async function elegir(e: ChangeEvent<HTMLInputElement>) {
        const archivos = Array.from(e.target.files ?? []);
        e.target.value = '';

        if (archivos.length === 0) {
            return;
        }

        const noPdf = archivos.filter(
            (a) => !a.name.toLowerCase().endsWith('.pdf'),
        );
        const pesados = archivos.filter(
            (a) => a.size > subida.max_mb * 1024 * 1024,
        );

        if (noPdf.length > 0 || pesados.length > 0) {
            toast.error(
                noPdf.length > 0
                    ? `Solo PDF: "${noPdf[0].name}" no lo es.`
                    : `"${pesados[0].name}" pesa más de ${subida.max_mb} MB.`,
            );

            return;
        }

        const total = { subidos: 0, asignados: 0, repetidos: 0 };
        let fallaron = 0;
        setProgreso({ hechos: 0, total: archivos.length });

        // In batches: PHP accepts 20 files per request by default.
        for (let i = 0; i < archivos.length; i += subida.por_vez) {
            const tanda = archivos.slice(i, i + subida.por_vez);
            const resultado = await subirTanda(tanda);

            if (resultado) {
                total.subidos += resultado.subidos;
                total.asignados += resultado.asignados;
                total.repetidos += resultado.repetidos;
            } else {
                fallaron += tanda.length;
            }

            setProgreso({
                hechos: Math.min(i + tanda.length, archivos.length),
                total: archivos.length,
            });
        }

        setProgreso(null);

        const partes = [
            `${total.subidos} subidos`,
            `${total.asignados} asignados solos`,
            total.subidos - total.asignados > 0
                ? `${total.subidos - total.asignados} por revisar`
                : null,
            total.repetidos > 0
                ? `${total.repetidos} ya estaban (no se duplicaron)`
                : null,
        ].filter(Boolean);

        if (fallaron > 0) {
            toast.error(
                `${fallaron} no se pudieron subir. Vuelve a elegirlos: los que ya subieron no se duplican.`,
            );
        }

        if (total.subidos + total.repetidos > 0) {
            toast.success(partes.join(' · ') + '.');
        }
    }

    return (
        <div className="flex items-center gap-3">
            {progreso && (
                <div className="flex items-center gap-2 text-sm">
                    <Spinner />
                    <span className="tabular-nums">
                        Subiendo {progreso.hechos} de {progreso.total}…
                    </span>
                    <div className="bg-muted hidden h-2 w-32 overflow-hidden rounded-full sm:block">
                        <div
                            className="bg-primary h-full transition-all"
                            style={{
                                width: `${(progreso.hechos / progreso.total) * 100}%`,
                            }}
                        />
                    </div>
                </div>
            )}
            <input
                ref={entrada}
                type="file"
                accept="application/pdf,.pdf"
                multiple
                className="hidden"
                onChange={elegir}
            />
            <Button
                size="sm"
                disabled={progreso !== null}
                onClick={() => entrada.current?.click()}
            >
                <Upload />
                Subir certificados
            </Button>
        </div>
    );
}

function Envio({
    eventoId,
    certificados,
    campana,
    ritmo,
}: {
    eventoId: number;
    certificados: Certificado[];
    campana: Campana | null;
    ritmo: Props['ritmo'];
}) {
    const cuenta = (estados: string[]) =>
        certificados.filter((c) => estados.includes(c.estado.value as string))
            .length;
    const listos = certificados.filter(
        (c) => c.estado.value === 'ready' && !c.sin_correo,
    ).length;
    const sinCorreo = certificados.filter(
        (c) => c.estado.value === 'ready' && c.sin_correo,
    ).length;
    const porRevisar = cuenta(['review']);
    const enEnvio = cuenta(['queued', 'sending']);
    const enviados = cuenta(['sent']);
    const total = enviados + enEnvio;
    const segundos = (listos * (ritmo.minimo + ritmo.maximo)) / 2;
    const duracion =
        segundos < 120
            ? `${Math.max(1, Math.round(segundos))} segundos`
            : segundos < 3600
              ? `${Math.round(segundos / 60)} minutos`
              : `${Math.round((segundos / 3600) * 10) / 10} horas`;
    // Seconds in local tests, minutes in production.
    const espaciado =
        ritmo.maximo < 120
            ? `${ritmo.minimo} a ${ritmo.maximo} segundos`
            : `${Math.round(ritmo.minimo / 60)} a ${Math.round(ritmo.maximo / 60)} minutos`;

    return (
        <SeccionFicha titulo="Envío">
            <div className="space-y-4">
                {enEnvio === 0 && enviados > 0 && (
                    <div className="flex items-center gap-2">
                        <EstadoBadge
                            estado={{
                                value: 'finished',
                                label: 'Envío terminado',
                                color: 'success',
                            }}
                        />
                        <span className="text-sm tabular-nums">
                            {enviados} {enviados === 1 ? 'enviado' : 'enviados'}
                        </span>
                    </div>
                )}
                {/* Pause and resume only while something is still waiting. */}
                {campana &&
                    (campana.enviando || campana.pausada) &&
                    enEnvio > 0 && (
                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <EstadoBadge estado={campana.estado} />
                                    <span className="text-sm tabular-nums">
                                        {enviados} de {total} enviados
                                    </span>
                                    {campana.enviando && campana.proximo && (
                                        <span className="text-muted-foreground text-sm">
                                            · el próximo sale{' '}
                                            {campana.proximo === 'en un momento'
                                                ? 'en un momento'
                                                : `a las ${campana.proximo}`}
                                        </span>
                                    )}
                                </div>
                                {campana.enviando ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                CertificadoController.pausar.url(
                                                    eventoId,
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Pause />
                                        Pausar
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                CertificadoController.reanudar.url(
                                                    eventoId,
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Play />
                                        Reanudar
                                    </Button>
                                )}
                            </div>
                            <div className="bg-muted h-2 overflow-hidden rounded-full">
                                <div
                                    className={cn(
                                        'h-full transition-all',
                                        campana.pausada
                                            ? 'bg-amber-500'
                                            : 'bg-emerald-600',
                                    )}
                                    style={{
                                        width: `${total ? (enviados / total) * 100 : 0}%`,
                                    }}
                                />
                            </div>
                            {campana.pausada && campana.motivo && (
                                <p className="text-sm text-amber-700">
                                    {campana.motivo}
                                </p>
                            )}
                        </div>
                    )}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-sm">
                        Salen de a uno, cada {espaciado}, hasta {ritmo.tope} por
                        día.
                        {porRevisar > 0 &&
                            ` Los ${porRevisar} por revisar no salen hasta asignarles una persona.`}
                        {sinCorreo > 0 &&
                            ` ${sinCorreo} sin correo válido no salen hasta corregirlo.`}
                    </p>
                    <Confirmar
                        titulo={`Enviar ${listos} ${listos === 1 ? 'certificado' : 'certificados'}`}
                        descripcion={`Cada persona recibe su certificado por correo. Salen de a uno para no caer en spam: terminan en unos ${duracion}. Puedes pausar cuando quieras.`}
                        textoAccion="Empezar envío"
                        destructivo={false}
                        onConfirmar={() =>
                            router.post(
                                CertificadoController.enviar.url(eventoId),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Button size="sm" disabled={listos === 0}>
                            <Send />
                            {listos === 0
                                ? 'Nada listo para enviar'
                                : `Enviar ${listos} listos`}
                        </Button>
                    </Confirmar>
                </div>
            </div>
        </SeccionFicha>
    );
}

function ElegirPersona({
    eventoId,
    certificado,
    personas,
}: {
    eventoId: number;
    certificado: Certificado;
    personas: Persona[];
}) {
    const [guardando, setGuardando] = useState(false);

    function asignar(id: number) {
        setGuardando(true);
        router.patch(
            CertificadoController.asignar.url([eventoId, certificado.id]),
            { participant_id: id },
            { preserveScroll: true, onFinish: () => setGuardando(false) },
        );
    }

    return (
        <div className="flex items-center gap-2">
            <Buscador
                aria-label={`Persona del certificado ${certificado.archivo}`}
                className="w-full max-w-72"
                opciones={personas}
                valor={certificado.participant_id}
                disabled={guardando}
                placeholder="Buscar por nombre, correo o colegio…"
                onElegir={asignar}
            />
            {guardando && <Spinner />}
        </div>
    );
}

export default function Certificados({
    evento,
    certificados,
    personas,
    campana,
    ritmo,
    subida,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Eventos', href: EventoController.index() },
            { title: evento.name, href: EventoController.show(evento.id) },
            { title: 'Certificados', href: '#' },
        ],
    });

    const detalle = useMemo(
        () => new Map(personas.map((p) => [p.id, p.detalle])),
        [personas],
    );
    const conteo = (v: (typeof VISTAS)[number]) =>
        certificados.filter((c) => v.estados.includes(c.estado.value as string))
            .length;

    const [vista, setVista] = useState<VistaId>(() => {
        const primera = VISTAS.find((v) => conteo(v) > 0);

        return primera?.id ?? 'revisar';
    });
    const elegida = VISTAS.find((v) => v.id === vista) ?? VISTAS[0];
    const filas = certificados.filter((c) =>
        elegida.estados.includes(c.estado.value as string),
    );

    // While sending, the screen refreshes itself so the progress moves.
    const enCurso = certificados.some((c) =>
        ['queued', 'sending'].includes(c.estado.value as string),
    );
    const { start, stop } = usePoll(
        20000,
        { only: ['certificados', 'campana'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (enCurso) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [enCurso, start, stop]);

    return (
        <>
            <Head title={`Certificados · ${evento.name}`} />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                {certificados.length > 0 && (
                    <Envio
                        eventoId={evento.id}
                        certificados={certificados}
                        campana={campana}
                        ritmo={ritmo}
                    />
                )}

                {certificados.length > 0 && (
                    <div
                        role="group"
                        aria-label="Vista"
                        className="grid grid-cols-2 gap-2 lg:grid-cols-5"
                    >
                        {VISTAS.map((v) => (
                            <button
                                key={v.id}
                                type="button"
                                aria-pressed={v.id === vista}
                                onClick={() => setVista(v.id)}
                                className={cn(
                                    'bg-card flex items-center justify-between gap-2 rounded-xl border px-4 py-3 text-left shadow-xs transition-colors',
                                    v.id === vista
                                        ? 'border-primary ring-primary ring-1'
                                        : 'hover:bg-muted/50',
                                )}
                            >
                                <span className="min-w-0">
                                    <span className="block font-medium">
                                        {v.label}
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {v.descripcion}
                                    </span>
                                </span>
                                <span className="text-2xl font-semibold tabular-nums">
                                    {conteo(v)}
                                </span>
                            </button>
                        ))}
                    </div>
                )}

                <SeccionFicha
                    titulo="Certificados"
                    className="[&>div]:p-0"
                    accion={<Subir eventoId={evento.id} subida={subida} />}
                >
                    {certificados.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            Todavía no hay certificados. Sube los PDF: el
                            sistema lee el nombre de cada uno y lo asigna a su
                            participante.
                        </p>
                    ) : filas.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            Nada en «{elegida.label}».
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Certificado</TableHead>
                                    <TableHead>Persona</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead className="w-0" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filas.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell className="max-w-72 align-top">
                                            <span className="block font-medium whitespace-normal">
                                                {c.nombre_leido ??
                                                    'Nombre no legible'}
                                            </span>
                                            <a
                                                href={CertificadoController.archivo.url(
                                                    [evento.id, c.id],
                                                )}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-muted-foreground block truncate text-xs underline-offset-2 hover:underline"
                                            >
                                                Ver {c.archivo}
                                            </a>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            {c.se_edita ? (
                                                <ElegirPersona
                                                    eventoId={evento.id}
                                                    certificado={c}
                                                    personas={personas}
                                                />
                                            ) : (
                                                <span className="block font-medium">
                                                    {c.persona ?? '-'}
                                                </span>
                                            )}
                                            {c.participant_id && (
                                                <span
                                                    className={cn(
                                                        'mt-1 block text-xs',
                                                        c.sin_correo
                                                            ? 'text-red-600'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {c.sin_correo
                                                        ? 'Sin correo válido: corrígelo en su inscripción'
                                                        : (detalle.get(
                                                              c.participant_id,
                                                          ) ?? c.correo)}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-64 align-top">
                                            <EstadoBadge estado={c.estado} />
                                            {c.dudoso && (
                                                <span className="mt-1 block text-xs text-amber-700">
                                                    Parecido, no seguro:
                                                    confirma la persona
                                                </span>
                                            )}
                                            {c.enviado && (
                                                <span className="text-muted-foreground mt-1 block text-xs whitespace-normal">
                                                    {c.enviado}
                                                </span>
                                            )}
                                            {c.error && (
                                                <span className="mt-1 block text-xs whitespace-normal text-red-600">
                                                    {c.error}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right align-top">
                                            <div className="flex justify-end gap-1">
                                                {c.dudoso &&
                                                    c.participant_id && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.patch(
                                                                    CertificadoController.asignar.url(
                                                                        [
                                                                            evento.id,
                                                                            c.id,
                                                                        ],
                                                                    ),
                                                                    {
                                                                        participant_id:
                                                                            c.participant_id,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Confirmar
                                                        </Button>
                                                    )}
                                                {c.se_reenvia && (
                                                    <Confirmar
                                                        titulo={`${c.estado.value === 'sent' ? 'Reenviar' : 'Reintentar'} el certificado de ${c.persona}`}
                                                        descripcion="Vuelve a la fila y sale en su turno, al correo que tenga hoy la persona."
                                                        textoAccion={
                                                            c.estado.value ===
                                                            'sent'
                                                                ? 'Reenviar'
                                                                : 'Reintentar'
                                                        }
                                                        destructivo={false}
                                                        onConfirmar={() =>
                                                            router.post(
                                                                CertificadoController.reenviar.url(
                                                                    [
                                                                        evento.id,
                                                                        c.id,
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
                                                            size="sm"
                                                            variant="ghost"
                                                        >
                                                            {c.estado.value ===
                                                            'sent'
                                                                ? 'Reenviar'
                                                                : 'Reintentar'}
                                                        </Button>
                                                    </Confirmar>
                                                )}
                                                {c.se_edita && (
                                                    <Confirmar
                                                        titulo={`Quitar el certificado "${c.archivo}"`}
                                                        descripcion="Se borra el archivo subido. No se envía nada."
                                                        textoAccion="Quitar"
                                                        onConfirmar={() =>
                                                            router.delete(
                                                                CertificadoController.destroy.url(
                                                                    [
                                                                        evento.id,
                                                                        c.id,
                                                                    ],
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="text-red-600 hover:text-red-700"
                                                        >
                                                            Quitar
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
