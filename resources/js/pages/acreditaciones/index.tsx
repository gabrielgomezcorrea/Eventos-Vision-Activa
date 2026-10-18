import { Head, router, setLayoutProps } from '@inertiajs/react';
import { QrCode, Search, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import ReporteController from '@/actions/App/Http/Controllers/Acreditacion/ReporteController';
import { NativeSelect } from '@/components/native-select';
import { Paginacion } from '@/components/paginacion';
import { Button } from '@/components/ui/button';
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
import { inicio as escaner } from '@/routes/acreditacion';
import type { Paginado } from '@/types';

type Conteo = { esperados: number; llegaron: number; faltan: number };

type Persona = {
    id: number;
    nombre: string;
    rut: string | null;
    colegio: string;
    acceso: string;
    llego: boolean;
    invitado: boolean;
    hora: string | null;
    acreditado_por?: string | null;
    metodo?: string | null;
};

type Reporte = {
    totales: Conteo & { invitados: number; invitados_llegaron: number };
    jornadas: (Conteo & {
        id: number;
        nombre: string;
        cuando: string | null;
    })[];
    llegadas: { hora: string; llegadas: number }[];
    colegios: (Conteo & {
        id: number;
        colegio: string;
        entidad: string | null;
        folio: string | null;
    })[];
    personas: Paginado<Persona>;
    ver_acreditador: boolean;
};

type Filtros = {
    estado: string | null;
    inscripcion: number | null;
    buscar: string | null;
};

type Props = {
    eventos: Record<string, string>;
    evento: number | null;
    filtros: Filtros;
    reporte: Reporte | null;
};

const configGrafico = {
    llegadas: { label: 'Llegadas', color: 'var(--primary)' },
} satisfies ChartConfig;

function Tarjeta({
    titulo,
    valor,
    detalle,
    tono,
}: {
    titulo: string;
    valor: number;
    detalle: string;
    tono?: string;
}) {
    return (
        <Card className="from-primary/5 to-card h-full gap-4 bg-linear-to-t shadow-xs">
            <CardHeader>
                <CardDescription>{titulo}</CardDescription>
                <CardTitle
                    className={cn('text-3xl font-semibold tabular-nums', tono)}
                >
                    {valor}
                </CardTitle>
            </CardHeader>
            <CardFooter className="text-muted-foreground text-sm">
                {detalle}
            </CardFooter>
        </Card>
    );
}

export default function AcreditacionesIndex({
    eventos,
    evento,
    filtros,
    reporte,
}: Props) {
    const [buscar, setBuscar] = useState(filtros.buscar ?? '');

    setLayoutProps({
        breadcrumbs: [
            { title: 'Acreditación', href: ReporteController.index() },
        ],
    });

    function ir(
        cambios: Partial<
            Record<keyof Filtros | 'evento', string | number | null>
        >,
    ) {
        const todos = { evento, ...filtros, ...cambios };

        router.get(
            ReporteController.index.url(),
            Object.fromEntries(
                Object.entries(todos).filter(([, valor]) => valor),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function buscarPersona(e: FormEvent) {
        e.preventDefault();
        ir({ buscar });
    }

    const colegioFiltrado = reporte?.colegios.find(
        (c) => c.id === filtros.inscripcion,
    );

    return (
        <>
            <Head title="Acreditación" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Acreditación
                    </h1>
                    <div className="flex flex-wrap items-center gap-2">
                        <NativeSelect
                            aria-label="Evento"
                            className="w-auto max-w-72"
                            value={evento ?? ''}
                            onChange={(e) =>
                                router.get(
                                    ReporteController.index.url(),
                                    { evento: e.target.value },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {Object.entries(eventos).map(([id, nombre]) => (
                                <option key={id} value={id}>
                                    {nombre}
                                </option>
                            ))}
                        </NativeSelect>
                        <Button asChild variant="outline">
                            <a
                                href={escaner.url()}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <QrCode />
                                Abrir escáner
                            </a>
                        </Button>
                    </div>
                </div>

                {reporte === null ? (
                    <p className="text-muted-foreground py-12 text-center">
                        Todavía no hay eventos.
                    </p>
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                            <Tarjeta
                                titulo="Esperados"
                                valor={reporte.totales.esperados}
                                detalle="Con pago aprobado"
                            />
                            <Tarjeta
                                titulo="Llegaron"
                                valor={reporte.totales.llegaron}
                                detalle="Ya recibieron su pulsera"
                                tono="text-emerald-600"
                            />
                            <Tarjeta
                                titulo="Faltan"
                                valor={reporte.totales.faltan}
                                detalle="Aún no se acreditan"
                                tono="text-amber-600"
                            />
                            <Tarjeta
                                titulo="Invitados"
                                valor={reporte.totales.invitados}
                                detalle={`Llegaron ${reporte.totales.invitados_llegaron} de ${reporte.totales.invitados}`}
                            />
                        </div>

                        {reporte.jornadas.length > 0 && (
                            <Card className="gap-0 py-0">
                                <CardHeader className="border-b py-4">
                                    <CardTitle>Por jornada</CardTitle>
                                </CardHeader>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="pl-5">
                                                Jornada
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Esperados
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Llegaron
                                            </TableHead>
                                            <TableHead className="pr-5 text-right">
                                                Faltan
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {reporte.jornadas.map((j) => (
                                            <TableRow key={j.id}>
                                                <TableCell className="pl-5">
                                                    {j.nombre}
                                                    {j.cuando && (
                                                        <span className="text-muted-foreground block text-xs">
                                                            {j.cuando}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {j.esperados}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {j.llegaron}
                                                </TableCell>
                                                <TableCell className="pr-5 text-right tabular-nums">
                                                    {j.faltan}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Card>
                        )}

                        {reporte.llegadas.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Llegadas por hora</CardTitle>
                                </CardHeader>
                                <CardContent className="px-2 sm:px-6">
                                    <ChartContainer
                                        config={configGrafico}
                                        className="aspect-auto h-60 w-full"
                                    >
                                        <BarChart data={reporte.llegadas}>
                                            <CartesianGrid vertical={false} />
                                            <XAxis
                                                dataKey="hora"
                                                tickLine={false}
                                                axisLine={false}
                                                tickMargin={8}
                                                minTickGap={24}
                                            />
                                            <YAxis
                                                allowDecimals={false}
                                                tickLine={false}
                                                axisLine={false}
                                                width={32}
                                            />
                                            <ChartTooltip
                                                cursor={false}
                                                content={
                                                    <ChartTooltipContent />
                                                }
                                            />
                                            <Bar
                                                dataKey="llegadas"
                                                fill="var(--color-llegadas)"
                                                radius={4}
                                            />
                                        </BarChart>
                                    </ChartContainer>
                                </CardContent>
                            </Card>
                        )}

                        <Card className="gap-0 py-0">
                            <CardHeader className="border-b py-4">
                                <CardTitle>Por colegio</CardTitle>
                                <CardDescription>
                                    Haz clic en una fila para ver solo sus
                                    personas.
                                </CardDescription>
                            </CardHeader>
                            <div className="max-h-96 overflow-y-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="pl-5">
                                                Colegio
                                            </TableHead>
                                            <TableHead>Folio</TableHead>
                                            <TableHead className="text-right">
                                                Esperados
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Llegaron
                                            </TableHead>
                                            <TableHead className="pr-5 text-right">
                                                Faltan
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {reporte.colegios.map((c) => (
                                            <TableRow
                                                key={c.id}
                                                className={cn(
                                                    'cursor-pointer',
                                                    filtros.inscripcion ===
                                                        c.id && 'bg-muted/60',
                                                )}
                                                onClick={() =>
                                                    ir({ inscripcion: c.id })
                                                }
                                            >
                                                <TableCell className="pl-5">
                                                    {c.colegio}
                                                    {c.entidad && (
                                                        <span className="text-muted-foreground block text-xs">
                                                            {c.entidad}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>{c.folio}</TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {c.esperados}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {c.llegaron}
                                                </TableCell>
                                                <TableCell className="pr-5 text-right tabular-nums">
                                                    {c.faltan}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </Card>

                        <div className="space-y-3">
                            <h2 className="text-lg font-semibold">Personas</h2>
                            <div className="flex flex-wrap items-center gap-2">
                                <form
                                    onSubmit={buscarPersona}
                                    className="relative w-full sm:w-80"
                                >
                                    <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                    <Input
                                        type="search"
                                        aria-label="Buscar"
                                        placeholder="Nombre o RUT"
                                        value={buscar}
                                        onChange={(e) =>
                                            setBuscar(e.target.value)
                                        }
                                        className="pl-9"
                                    />
                                </form>
                                <NativeSelect
                                    aria-label="Estado"
                                    className="w-auto"
                                    value={filtros.estado ?? ''}
                                    onChange={(e) =>
                                        ir({ estado: e.target.value || null })
                                    }
                                >
                                    <option value="">Todos</option>
                                    <option value="llegaron">Llegaron</option>
                                    <option value="faltan">Faltan</option>
                                </NativeSelect>
                                {colegioFiltrado && (
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            ir({ inscripcion: null })
                                        }
                                    >
                                        {colegioFiltrado.colegio}
                                        <X />
                                    </Button>
                                )}
                            </div>

                            <div className="bg-card rounded-xl border shadow-xs">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="pl-5">
                                                Nombre
                                            </TableHead>
                                            <TableHead>Colegio</TableHead>
                                            <TableHead>Acceso</TableHead>
                                            <TableHead>Estado</TableHead>
                                            <TableHead>
                                                Hora de llegada
                                            </TableHead>
                                            {reporte.ver_acreditador && (
                                                <TableHead>
                                                    Acreditado por
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {reporte.personas.data.length === 0 && (
                                            <TableRow className="hover:bg-transparent">
                                                <TableCell
                                                    colSpan={6}
                                                    className="text-muted-foreground py-12 text-center"
                                                >
                                                    Nadie coincide con los
                                                    filtros.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {reporte.personas.data.map((p) => (
                                            <TableRow key={p.id}>
                                                <TableCell className="pl-5">
                                                    {p.nombre}
                                                    {p.rut && (
                                                        <span className="text-muted-foreground block text-xs">
                                                            {p.rut}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="max-w-48 truncate">
                                                    {p.colegio}
                                                </TableCell>
                                                <TableCell>
                                                    {p.acceso}
                                                </TableCell>
                                                <TableCell>
                                                    <span
                                                        className={cn(
                                                            'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
                                                            p.llego
                                                                ? 'bg-emerald-100 text-emerald-800'
                                                                : 'bg-amber-100 text-amber-800',
                                                        )}
                                                    >
                                                        {p.llego
                                                            ? 'Llegó'
                                                            : 'Falta'}
                                                    </span>
                                                    {p.invitado && (
                                                        <span className="ml-1 inline-flex items-center rounded-md bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800">
                                                            Invitado
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {p.hora ?? '—'}
                                                </TableCell>
                                                {reporte.ver_acreditador && (
                                                    <TableCell>
                                                        {p.acreditado_por ??
                                                            '—'}
                                                        {p.metodo && (
                                                            <span className="text-muted-foreground block text-xs">
                                                                {p.metodo}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <Paginacion pagina={reporte.personas} />
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
