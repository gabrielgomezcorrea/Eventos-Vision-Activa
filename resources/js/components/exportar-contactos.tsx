import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import ExportarContactosController from '@/actions/App/Http/Controllers/Solicitudes/ExportarContactosController';
import { Campo } from '@/components/campo';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

export type OpcionesExportacion = {
    cargos: string[];
    tipos: Record<string, string>;
    pagos: Record<string, string>;
};

/** CSV of contacts with filters, ready to clean and import in Brevo or the CRM. */
export function ExportarContactos({
    eventos,
    opciones,
}: {
    eventos: Record<string, string>;
    opciones: OpcionesExportacion;
}) {
    const [evento, setEvento] = useState('');
    const [cargo, setCargo] = useState('');
    const [tipos, setTipos] = useState<string[]>(Object.keys(opciones.tipos));
    const [pago, setPago] = useState('');
    const [marketing, setMarketing] = useState(true);

    const query = {
        ...(evento ? { evento } : {}),
        ...(cargo ? { cargo } : {}),
        ...(pago ? { pago } : {}),
        ...(marketing ? { marketing: 1 } : {}),
        tipos,
    };
    const url = ExportarContactosController.url({ query });
    const urlCantidad = ExportarContactosController.cantidad.url({ query });
    const [cantidad, setCantidad] = useState<number | null>(null);

    // Counts again on every filter change, so the person knows what the file
    // will hold before downloading it.
    useEffect(() => {
        if (tipos.length === 0) {
            setCantidad(0);

            return;
        }

        const control = new AbortController();
        setCantidad(null);

        fetch(urlCantidad, {
            headers: { Accept: 'application/json' },
            signal: control.signal,
        })
            .then((respuesta) => respuesta.json())
            .then((datos: { cantidad: number }) => setCantidad(datos.cantidad))
            .catch(() => {});

        return () => control.abort();
    }, [urlCantidad, tipos.length]);

    function alternarTipo(tipo: string, marcado: boolean) {
        setTipos((actuales) =>
            marcado ? [...actuales, tipo] : actuales.filter((t) => t !== tipo),
        );
    }

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="outline">
                    <Download />
                    Exportar contactos
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Exportar contactos</SheetTitle>
                    <SheetDescription>
                        Una persona sale una sola vez, por su correo.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex-1 space-y-5 px-4">
                    <Campo label="Evento" htmlFor="exp-evento">
                        <NativeSelect
                            id="exp-evento"
                            value={evento}
                            onChange={(e) => setEvento(e.target.value)}
                        >
                            <option value="">Todos los eventos</option>
                            {Object.entries(eventos).map(([id, nombre]) => (
                                <option key={id} value={id}>
                                    {nombre}
                                </option>
                            ))}
                        </NativeSelect>
                    </Campo>
                    <Campo label="Cargo" htmlFor="exp-cargo">
                        <NativeSelect
                            id="exp-cargo"
                            value={cargo}
                            onChange={(e) => setCargo(e.target.value)}
                        >
                            <option value="">Todos los cargos</option>
                            {opciones.cargos.map((c) => (
                                <option key={c} value={c}>
                                    {c === 'Otro'
                                        ? 'Otros (escritos a mano)'
                                        : c}
                                </option>
                            ))}
                        </NativeSelect>
                    </Campo>
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Tipo de contacto
                        </legend>
                        {Object.entries(opciones.tipos).map(
                            ([valor, texto]) => (
                                <label
                                    key={valor}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={tipos.includes(valor)}
                                        onCheckedChange={(marcado) =>
                                            alternarTipo(
                                                valor,
                                                marcado === true,
                                            )
                                        }
                                    />
                                    {texto}
                                </label>
                            ),
                        )}
                    </fieldset>
                    <Campo label="Estado de pago" htmlFor="exp-pago">
                        <NativeSelect
                            id="exp-pago"
                            value={pago}
                            onChange={(e) => setPago(e.target.value)}
                        >
                            <option value="">Cualquier estado</option>
                            {Object.entries(opciones.pagos).map(
                                ([valor, texto]) => (
                                    <option key={valor} value={valor}>
                                        {texto}
                                    </option>
                                ),
                            )}
                        </NativeSelect>
                    </Campo>
                    {pago && tipos.includes('solicitud') && (
                        <p className="text-muted-foreground text-sm">
                            Las solicitudes no tienen pago: no se incluyen con
                            este filtro.
                        </p>
                    )}
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={marketing}
                            onCheckedChange={(marcado) =>
                                setMarketing(marcado === true)
                            }
                        />
                        Solo quienes aceptaron recibir información
                    </label>
                </div>
                <SheetFooter>
                    <p
                        className="text-muted-foreground text-sm"
                        aria-live="polite"
                    >
                        {cantidad === null
                            ? 'Contando contactos…'
                            : cantidad === 1
                              ? 'Se exportará 1 contacto.'
                              : `Se exportarán ${cantidad} contactos.`}
                    </p>
                    {tipos.length === 0 || cantidad === 0 ? (
                        <Button disabled>
                            {tipos.length === 0
                                ? 'Elige al menos un tipo'
                                : 'No hay contactos con estos filtros'}
                        </Button>
                    ) : (
                        <Button asChild>
                            <a href={url}>
                                <Download />
                                Descargar CSV
                            </a>
                        </Button>
                    )}
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
