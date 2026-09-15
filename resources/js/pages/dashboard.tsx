import { Head, Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    Banknote,
    CheckCircle2,
    Clock,
    Inbox,
    QrCode,
    ScanLine,
} from 'lucide-react';
import { useState } from 'react';
import { Area, AreaChart, CartesianGrid, XAxis } from 'recharts';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

type Tarjeta = {
    titulo: string;
    valor: number;
    detalle: string;
    tono: string;
    icono: string;
    href: string | null;
};

type Jornada = {
    id: number;
    evento_id: number;
    evento: string;
    jornada: string;
    fecha: string | null;
    capacidad: number;
    tomados: number;
    disponibles: number | null;
    ocupacion: number | null;
};

type Dia = { fecha: string; inscripciones: number; pagadas: number };

type EventoPuerta = {
    id: number;
    nombre: string;
    fecha: string | null;
    es_hoy: boolean;
    acreditados: number;
    esperados: number;
    escaner: string;
};

type Acreditacion = {
    id: number;
    participante: string | null;
    evento: string | null;
    pulsera: string | null;
    color: string | null;
    hora: string;
};

type Props = {
    tarjetas: Tarjeta[];
    puerta: { eventos: EventoPuerta[]; ultimas: Acreditacion[] } | null;
    jornadas: Jornada[] | null;
    actividad: Dia[] | null;
};

const iconos: Record<string, LucideIcon> = {
    banknote: Banknote,
    clock: Clock,
    alert: AlertTriangle,
    inbox: Inbox,
    check: CheckCircle2,
    qr: QrCode,
};

const tonos: Record<string, string> = {
    warning: 'text-amber-600',
    danger: 'text-red-600',
    success: 'text-emerald-600',
    info: 'text-sky-600',
    gray: 'text-foreground',
};

/** Verde con holgura, ámbar con 10 o menos, rojo agotado. */
function colorDisponibles(disponibles: number | null): string {
    if (disponibles === null) {
        return 'bg-muted text-muted-foreground';
    }

    if (disponibles === 0) {
        return 'bg-red-100 text-red-800';
    }

    if (disponibles <= 10) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-emerald-100 text-emerald-800';
}

function TarjetaResumen({ tarjeta }: { tarjeta: Tarjeta }) {
    const Icono = iconos[tarjeta.icono] ?? Inbox;

    const tarjetaUi = (
        <Card
            className={cn(
                'from-primary/5 to-card @container/card h-full gap-4 bg-linear-to-t shadow-xs',
                tarjeta.href && 'hover:border-primary/40 transition-colors',
            )}
        >
            <CardHeader>
                <CardDescription className="flex items-center justify-between">
                    {tarjeta.titulo}
                    <Icono className="size-4" />
                </CardDescription>
                <CardTitle
                    className={cn(
                        'text-2xl font-semibold tabular-nums @[250px]/card:text-3xl',
                        tonos[tarjeta.tono] ?? tonos.gray,
                    )}
                >
                    {tarjeta.valor}
                </CardTitle>
            </CardHeader>
            <CardFooter className="text-muted-foreground text-sm">
                {tarjeta.detalle}
            </CardFooter>
        </Card>
    );

    return tarjeta.href ? (
        <Link href={tarjeta.href}>{tarjetaUi}</Link>
    ) : (
        tarjetaUi
    );
}

const configGrafico = {
    inscripciones: { label: 'Inscripciones', color: 'var(--primary)' },
    pagadas: { label: 'Pagadas', color: '#059669' },
} satisfies ChartConfig;

const rangos = [
    { valor: '90', etiqueta: '3 meses' },
    { valor: '30', etiqueta: '30 días' },
    { valor: '7', etiqueta: '7 días' },
];

/** La fecha llega como `2026-09-10`: sin hora se leería en UTC y mostraría el día anterior. */
function fechaCorta(fecha: string): string {
    return new Date(`${fecha}T00:00:00`).toLocaleDateString('es-CL', {
        day: 'numeric',
        month: 'short',
    });
}

function GraficoActividad({ actividad }: { actividad: Dia[] }) {
    const [rango, setRango] = useState('30');
    const datos = actividad.slice(-Number(rango));

    return (
        <Card className="@container/card">
            <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1.5">
                    <CardTitle>Inscripciones</CardTitle>
                    <CardDescription>
                        Confirmadas y pagadas por día
                    </CardDescription>
                </div>
                <ToggleGroup
                    type="single"
                    value={rango}
                    onValueChange={(valor) => valor && setRango(valor)}
                    variant="outline"
                    size="sm"
                >
                    {rangos.map((r) => (
                        <ToggleGroupItem
                            key={r.valor}
                            value={r.valor}
                            className="px-3"
                        >
                            {r.etiqueta}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </CardHeader>
            <CardContent className="px-2 pt-2 sm:px-6">
                <ChartContainer
                    config={configGrafico}
                    className="aspect-auto h-62.5 w-full"
                >
                    <AreaChart data={datos}>
                        <defs>
                            {(['inscripciones', 'pagadas'] as const).map(
                                (serie) => (
                                    <linearGradient
                                        key={serie}
                                        id={`relleno-${serie}`}
                                        x1="0"
                                        y1="0"
                                        x2="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="5%"
                                            stopColor={`var(--color-${serie})`}
                                            stopOpacity={0.6}
                                        />
                                        <stop
                                            offset="95%"
                                            stopColor={`var(--color-${serie})`}
                                            stopOpacity={0.05}
                                        />
                                    </linearGradient>
                                ),
                            )}
                        </defs>
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="fecha"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            minTickGap={32}
                            tickFormatter={fechaCorta}
                        />
                        <ChartTooltip
                            cursor={false}
                            content={
                                <ChartTooltipContent
                                    labelFormatter={(valor) =>
                                        fechaCorta(String(valor))
                                    }
                                    indicator="dot"
                                />
                            }
                        />
                        <Area
                            dataKey="inscripciones"
                            type="monotone"
                            fill="url(#relleno-inscripciones)"
                            stroke="var(--color-inscripciones)"
                        />
                        <Area
                            dataKey="pagadas"
                            type="monotone"
                            fill="url(#relleno-pagadas)"
                            stroke="var(--color-pagadas)"
                        />
                    </AreaChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

function CuposPorJornada({ jornadas }: { jornadas: Jornada[] }) {
    return (
        <Card className="gap-0 py-0">
            <CardHeader className="border-b py-4">
                <CardTitle>Cupos por jornada</CardTitle>
            </CardHeader>
            {jornadas.length === 0 ? (
                <p className="text-muted-foreground px-6 py-8 text-center text-sm">
                    Sin jornadas con cupo limitado en eventos publicados.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-6">Evento</TableHead>
                            <TableHead>Jornada</TableHead>
                            <TableHead>Fecha</TableHead>
                            <TableHead className="text-center">
                                Tomados
                            </TableHead>
                            <TableHead className="text-center">
                                Disponibles
                            </TableHead>
                            <TableHead className="pr-6 text-right">
                                Ocupación
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {jornadas.map((j) => (
                            <TableRow key={j.id}>
                                <TableCell className="pl-6 font-medium">
                                    {j.evento}
                                </TableCell>
                                <TableCell>{j.jornada}</TableCell>
                                <TableCell className="text-muted-foreground">
                                    {j.fecha ?? '—'}
                                </TableCell>
                                <TableCell className="text-center tabular-nums">
                                    {j.tomados} de {j.capacidad}
                                </TableCell>
                                <TableCell className="text-center">
                                    <span
                                        className={cn(
                                            'inline-block rounded-md px-2 py-0.5 text-xs font-medium tabular-nums',
                                            colorDisponibles(j.disponibles),
                                        )}
                                    >
                                        {j.disponibles ?? '—'}
                                    </span>
                                </TableCell>
                                <TableCell className="pr-6 text-right tabular-nums">
                                    {j.ocupacion === null
                                        ? '—'
                                        : `${j.ocupacion} %`}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Card>
    );
}

function AvanceEvento({ evento }: { evento: EventoPuerta }) {
    const faltan = Math.max(evento.esperados - evento.acreditados, 0);
    const avance =
        evento.esperados > 0
            ? Math.round((evento.acreditados / evento.esperados) * 100)
            : 0;

    return (
        <Card className="gap-4">
            <CardHeader className="gap-1">
                <CardDescription className="flex items-center gap-2">
                    {evento.es_hoy ? (
                        <span className="rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                            Hoy
                        </span>
                    ) : (
                        (evento.fecha ?? 'Sin fecha')
                    )}
                </CardDescription>
                <CardTitle className="text-lg leading-snug">
                    {evento.nombre}
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3">
                <div className="flex items-baseline justify-between gap-3">
                    <p className="text-3xl font-semibold tabular-nums">
                        {evento.acreditados}
                        <span className="text-muted-foreground text-base font-normal">
                            {' '}
                            de {evento.esperados}
                        </span>
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {evento.esperados === 0
                            ? 'Sin credenciales emitidas'
                            : faltan === 0
                              ? 'Todos acreditados'
                              : `Faltan ${faltan}`}
                    </p>
                </div>
                <div
                    className="bg-muted h-2 overflow-hidden rounded-full"
                    role="progressbar"
                    aria-valuenow={avance}
                    aria-valuemin={0}
                    aria-valuemax={100}
                >
                    <div
                        className="h-full rounded-full bg-emerald-600 transition-all"
                        style={{ width: `${avance}%` }}
                    />
                </div>
            </CardContent>
            <CardFooter>
                <Button asChild size="lg" className="w-full">
                    <a href={evento.escaner} target="_blank" rel="noopener">
                        <ScanLine className="size-5" />
                        Abrir escáner
                    </a>
                </Button>
            </CardFooter>
        </Card>
    );
}

function Puerta({
    eventos,
    ultimas,
}: {
    eventos: EventoPuerta[];
    ultimas: Acreditacion[];
}) {
    return (
        <div className="mx-auto grid w-full max-w-3xl gap-4 md:gap-6">
            {eventos.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground py-6 text-center text-sm">
                        No hay eventos próximos para acreditar.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {eventos.map((evento) => (
                        <AvanceEvento key={evento.id} evento={evento} />
                    ))}
                </div>
            )}

            <Card className="gap-0 py-0">
                <CardHeader className="border-b py-4">
                    <CardTitle>Últimas acreditaciones</CardTitle>
                </CardHeader>
                {ultimas.length === 0 ? (
                    <p className="text-muted-foreground px-6 py-6 text-center text-sm">
                        Todavía nadie ha retirado su pulsera.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {ultimas.map((a) => (
                            <li
                                key={a.id}
                                className="flex items-center gap-3 px-4 py-3 sm:px-6"
                            >
                                <span
                                    className="size-3 shrink-0 rounded-full border"
                                    style={{
                                        background: a.color ?? 'transparent',
                                    }}
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        {a.participante}
                                    </p>
                                    <p className="text-muted-foreground truncate text-sm">
                                        {[a.pulsera, a.evento]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <span className="text-muted-foreground text-sm tabular-nums">
                                    {a.hora}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

export default function Dashboard({
    tarjetas,
    puerta,
    jornadas,
    actividad,
}: Props) {
    return (
        <>
            <Head title="Escritorio" />
            <div className="@container/main flex flex-1 flex-col gap-4 p-4 md:gap-6 md:p-6">
                {puerta !== null && (
                    <Puerta eventos={puerta.eventos} ultimas={puerta.ultimas} />
                )}

                {tarjetas.length > 0 && (
                    <div className="grid grid-cols-1 gap-4 @xl/main:grid-cols-2 @5xl/main:grid-cols-3">
                        {tarjetas.map((tarjeta) => (
                            <TarjetaResumen
                                key={tarjeta.titulo}
                                tarjeta={tarjeta}
                            />
                        ))}
                    </div>
                )}

                {actividad !== null && (
                    <GraficoActividad actividad={actividad} />
                )}

                {jornadas !== null && <CuposPorJornada jornadas={jornadas} />}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Escritorio',
            href: dashboard(),
        },
    ],
};
