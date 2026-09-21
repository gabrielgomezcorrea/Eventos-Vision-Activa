import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import {
    ArrowRight,
    Ban,
    ChevronDown,
    FileText,
    Paperclip,
    RefreshCw,
    RotateCcw,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ComprobanteController from '@/actions/App/Http/Controllers/Comprobantes/ComprobanteController';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import InscripcionController from '@/actions/App/Http/Controllers/Inscripciones/InscripcionController';
import { Campo } from '@/components/campo';
import { Confirmar } from '@/components/confirmar';
import { EditarSeccion } from '@/components/editar-seccion';
import { EstadoBadge } from '@/components/estado-badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { NativeSelect } from '@/components/native-select';
import { RutInput } from '@/components/rut-input';
import { Dato, Datos, SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
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
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { tonoVencimiento } from '@/pages/inscripciones/index';
import type { Vencimiento } from '@/pages/inscripciones/index';
import type { Estado } from '@/types';

type Participante = {
    id: number;
    nombre: string;
    first_name: string;
    last_name: string | null;
    correo: string | null;
    cargo: string | null;
    rut: string | null;
    establecimiento: string | null;
    establishment_id: number | null;
    acceso: string | null;
    valor: number;
    estado: Estado;
    reemplazado_por: string | null;
    no_reemplazable: string | null;
};

type Props = {
    orden: {
        id: number;
        numero: string | null;
        estado: Estado;
        pago: Estado;
        tipo: string;
        evento: { id: number; nombre: string };
        confirmada: string | null;
        vence: string | null;
        vencimiento: Vencimiento;
        total: number;
        subtotal: number;
        descuento: number;
        descuento_etiqueta: string | null;
        internal_notes: string | null;
        responsable: {
            nombre: string | null;
            cargo: string | null;
            correo: string | null;
            telefono: string | null;
            institucion: string | null;
        };
        entidad: {
            nombre: string;
            rut: string | null;
            direccion: string | null;
            correo: string | null;
        } | null;
    };
    participantes: Participante[];
    establecimientos: Record<string, string>;
    conjunto:
        | {
              id: number;
              colegio: string;
              numero: string | null;
              total: number;
              saldo: number;
              estado: Estado;
          }[]
        | null;
    cargos: string[];
    credenciales: {
        id: number;
        participante: string | null;
        codigo: string | null;
        url: string | null;
        enviada: string | null;
        estado: Estado;
        acreditado_el: string | null;
    }[];
    cobranza: { total: number; pagado: number; saldo: number };
    pagos:
        | {
              id: number;
              estado: Estado;
              monto: number;
              recibido: string;
              informado_por: string | null;
              observaciones_factura: string | null;
          }[]
        | null;
    facturacion: {
        estado: Estado;
        documentos: {
            id: number;
            tipo: string;
            numero: string;
            emitido: string | null;
            monto: number;
            enviada: string | null;
            enviada_a: string | null;
        }[];
    } | null;
    puede: {
        cargarComprobante: boolean;
        registrarFactura: boolean;
        reemplazar: boolean;
        editarNotas: boolean;
        verCredenciales: boolean;
    };
    cancelacion: { impedimento: string | null } | null;
    reactivacion: { impedimento: string | null; plazo: string } | null;
    opciones: {
        abonos: Record<string, string>;
        tiposDocumento: Record<string, string>;
        correoFacturacion: string | null;
        hoy: string;
    };
};

const clp = (valor: number): string => `$${valor.toLocaleString('es-CL')}`;

/** Panel lateral con un formulario propio y su botón de enviar. */
export function Panel({
    titulo,
    descripcion,
    trigger,
    abierto,
    onOpenChange,
    onSubmit,
    procesando,
    textoEnviar,
    children,
}: {
    titulo: string;
    descripcion: string;
    trigger: ReactNode;
    abierto: boolean;
    onOpenChange: (valor: boolean) => void;
    onSubmit: (e: FormEvent) => void;
    procesando: boolean;
    textoEnviar: string;
    children: ReactNode;
}) {
    return (
        <Sheet open={abierto} onOpenChange={onOpenChange}>
            <SheetTrigger asChild>{trigger}</SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <form onSubmit={onSubmit} className="flex min-h-full flex-col">
                    <SheetHeader>
                        <SheetTitle>{titulo}</SheetTitle>
                        <SheetDescription>{descripcion}</SheetDescription>
                    </SheetHeader>
                    <div className="flex-1 space-y-5 px-4">{children}</div>
                    <SheetFooter>
                        <Button type="submit" disabled={procesando}>
                            {procesando ? 'Enviando…' : textoEnviar}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function CargarComprobante({
    ordenId,
    total,
}: {
    ordenId: number;
    total: number;
}) {
    const [abierto, setAbierto] = useState(false);
    const [errorArchivo, setErrorArchivo] = useState<string | null>(null);
    const form = useForm<{
        amount: string;
        paid_on: string;
        bank_name: string;
        payer_name: string;
        payer_rut: string;
        proof: File | null;
        notes: string;
    }>({
        amount: String(total),
        paid_on: '',
        bank_name: '',
        payer_name: '',
        payer_rut: '',
        proof: null,
        notes: '',
    });

    function enviar(e: FormEvent) {
        e.preventDefault();
        form.post(InscripcionController.cargarComprobante.url(ordenId), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Panel
            titulo="Cargar un comprobante en nombre del cliente"
            descripcion="Para cuando el comprobante llegó por correo. Queda registrado que lo cargaste tú."
            trigger={
                <Button>
                    <Paperclip />
                    Cargar comprobante
                </Button>
            }
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                    setErrorArchivo(null);
                }
            }}
            onSubmit={enviar}
            procesando={form.processing}
            textoEnviar="Registrar comprobante"
        >
            <Campo
                label="Monto informado"
                htmlFor="amount"
                error={form.errors.amount}
            >
                <div className="relative w-48">
                    <span className="text-muted-foreground absolute top-1/2 left-3 -translate-y-1/2 text-sm">
                        $
                    </span>
                    <Input
                        id="amount"
                        type="number"
                        min={1}
                        className="pl-7"
                        required
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                    />
                </div>
            </Campo>
            <Campo
                label="Fecha de la transferencia"
                htmlFor="paid_on"
                error={form.errors.paid_on}
            >
                <Input
                    id="paid_on"
                    type="date"
                    className="w-auto"
                    required
                    max={new Date().toISOString().slice(0, 10)}
                    value={form.data.paid_on}
                    onChange={(e) => form.setData('paid_on', e.target.value)}
                />
            </Campo>
            <Campo
                label="Banco de origen"
                htmlFor="bank_name"
                error={form.errors.bank_name}
            >
                <Input
                    id="bank_name"
                    required
                    value={form.data.bank_name}
                    onChange={(e) => form.setData('bank_name', e.target.value)}
                />
            </Campo>
            <Campo
                label="Nombre de quien pagó"
                htmlFor="payer_name"
                error={form.errors.payer_name}
            >
                <Input
                    id="payer_name"
                    required
                    value={form.data.payer_name}
                    onChange={(e) => form.setData('payer_name', e.target.value)}
                />
            </Campo>
            <Campo
                label="RUT de quien pagó"
                htmlFor="payer_rut"
                error={form.errors.payer_rut}
            >
                <Input
                    id="payer_rut"
                    required
                    placeholder="12.345.678-9"
                    value={form.data.payer_rut}
                    onChange={(e) => form.setData('payer_rut', e.target.value)}
                />
            </Campo>
            <Campo
                label="Comprobante (PDF o imagen, máximo 10 MB)"
                htmlFor="proof"
                error={errorArchivo ?? form.errors.proof}
            >
                <Input
                    id="proof"
                    type="file"
                    required
                    accept="application/pdf,image/jpeg,image/png,image/webp"
                    onChange={(e) => {
                        const archivo = e.target.files?.[0] ?? null;
                        const muyGrande =
                            archivo !== null && archivo.size > 10 * 1024 * 1024;

                        setErrorArchivo(
                            muyGrande
                                ? 'El comprobante no puede pesar más de 10 MB.'
                                : null,
                        );
                        form.setData('proof', muyGrande ? null : archivo);

                        if (muyGrande) {
                            e.target.value = '';
                        }
                    }}
                />
            </Campo>
            <Campo
                label="Observaciones"
                htmlFor="notes"
                error={form.errors.notes}
            >
                <Textarea
                    id="notes"
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </Campo>
        </Panel>
    );
}

function RegistrarFactura({
    ordenId,
    total,
    opciones,
}: {
    ordenId: number;
    total: number;
    opciones: Props['opciones'];
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm<{
        document_type: string;
        number: string;
        issued_on: string;
        amount: string;
        payment_id: string;
        archivo: File | null;
        enviar: boolean;
        notes: string;
    }>({
        document_type: 'invoice',
        number: '',
        issued_on: opciones.hoy,
        amount: String(total),
        payment_id: '',
        archivo: null,
        enviar: true,
        notes: '',
    });

    function enviar(e: FormEvent) {
        e.preventDefault();
        form.post(InscripcionController.registrarFactura.url(ordenId), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Panel
            titulo="Registrar un documento tributario"
            descripcion="La factura se emite en el sistema contable. Aquí solo se deja constancia."
            trigger={
                <Button variant="outline">
                    <FileText />
                    Registrar factura
                </Button>
            }
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
            onSubmit={enviar}
            procesando={form.processing}
            textoEnviar="Registrar"
        >
            <Campo
                label="Tipo de documento"
                htmlFor="document_type"
                error={form.errors.document_type}
            >
                <NativeSelect
                    id="document_type"
                    value={form.data.document_type}
                    onChange={(e) =>
                        form.setData('document_type', e.target.value)
                    }
                >
                    {Object.entries(opciones.tiposDocumento).map(
                        ([valor, texto]) => (
                            <option key={valor} value={valor}>
                                {texto}
                            </option>
                        ),
                    )}
                </NativeSelect>
            </Campo>
            <Campo
                label="Número del documento"
                htmlFor="number"
                error={form.errors.number}
            >
                <Input
                    id="number"
                    required
                    maxLength={50}
                    value={form.data.number}
                    onChange={(e) => form.setData('number', e.target.value)}
                />
            </Campo>
            <div className="grid gap-5 sm:grid-cols-2">
                <Campo
                    label="Fecha de emisión"
                    htmlFor="issued_on"
                    error={form.errors.issued_on}
                >
                    <Input
                        id="issued_on"
                        type="date"
                        required
                        max={opciones.hoy}
                        value={form.data.issued_on}
                        onChange={(e) =>
                            form.setData('issued_on', e.target.value)
                        }
                    />
                </Campo>
                <Campo
                    label="Abono que cubre (opcional)"
                    htmlFor="factura-payment"
                    error={form.errors.payment_id}
                >
                    <NativeSelect
                        id="factura-payment"
                        value={form.data.payment_id}
                        onChange={(e) =>
                            form.setData('payment_id', e.target.value)
                        }
                    >
                        <option value="">Sin abono asociado</option>
                        {Object.entries(opciones.abonos).map(
                            ([id, etiqueta]) => (
                                <option key={id} value={id}>
                                    {etiqueta}
                                </option>
                            ),
                        )}
                    </NativeSelect>
                </Campo>
                <Campo
                    label="Monto"
                    htmlFor="factura-amount"
                    error={form.errors.amount}
                >
                    <Input
                        id="factura-amount"
                        type="number"
                        min={0}
                        required
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                    />
                </Campo>
            </div>
            <Campo
                label="PDF del documento (opcional)"
                htmlFor="archivo"
                error={form.errors.archivo}
            >
                <Input
                    id="archivo"
                    type="file"
                    accept="application/pdf"
                    onChange={(e) =>
                        form.setData('archivo', e.target.files?.[0] ?? null)
                    }
                />
            </Campo>
            <label className="has-checked:border-primary has-checked:bg-accent/50 flex cursor-pointer items-start gap-3 rounded-lg border p-4">
                <input
                    type="checkbox"
                    className="mt-1"
                    checked={form.data.enviar}
                    onChange={(e) => form.setData('enviar', e.target.checked)}
                />
                <span className="text-sm">
                    <span className="font-medium">Enviar al cliente</span>
                    <span className="text-muted-foreground block">
                        Va al responsable
                        {opciones.correoFacturacion
                            ? ` y a ${opciones.correoFacturacion}`
                            : ''}
                        , con copia a administración.
                    </span>
                </span>
            </label>
            <Campo
                label="Observaciones"
                htmlFor="factura-notes"
                error={form.errors.notes}
            >
                <Textarea
                    id="factura-notes"
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </Campo>
        </Panel>
    );
}

const CARGO_OTRO = 'Otro';

/** Stored position back into list value plus "Otro" text, to prefill the form. */
function separarCargo(
    cargo: string | null,
    cargos: string[],
): { position: string; position_otro: string } {
    if (!cargo || cargos.includes(cargo)) {
        return { position: cargo ?? '', position_otro: '' };
    }

    return { position: CARGO_OTRO, position_otro: cargo };
}

/** Closed position list, same as the public form; "Otro" asks which one. */
function CampoCargo({
    id,
    cargo,
    otro,
    errorCargo,
    errorOtro,
    onCargo,
    onOtro,
}: {
    id: string;
    cargo: string;
    otro: string;
    errorCargo?: string;
    errorOtro?: string;
    onCargo: (valor: string) => void;
    onOtro: (valor: string) => void;
}) {
    const { cargos } = usePage<{ cargos: string[] }>().props;

    return (
        <>
            <Campo label="Cargo" htmlFor={id} error={errorCargo}>
                <NativeSelect
                    id={id}
                    value={cargo}
                    onChange={(e) => onCargo(e.target.value)}
                >
                    <option value="">Elige un cargo</option>
                    {cargos.map((opcion) => (
                        <option key={opcion} value={opcion}>
                            {opcion === CARGO_OTRO
                                ? 'Otro (especificar)'
                                : opcion}
                        </option>
                    ))}
                </NativeSelect>
            </Campo>
            {cargo === CARGO_OTRO && (
                <Campo
                    label="¿Cuál cargo?"
                    htmlFor={`${id}-otro`}
                    error={errorOtro}
                >
                    <Input
                        id={`${id}-otro`}
                        value={otro}
                        maxLength={120}
                        placeholder="Jefe de UTP"
                        onChange={(e) => onOtro(e.target.value)}
                    />
                </Campo>
            )}
        </>
    );
}

function Reemplazar({
    ordenId,
    participante,
    establecimientos,
}: {
    ordenId: number;
    participante: Participante;
    establecimientos: Record<string, string>;
}) {
    const [abierto, setAbierto] = useState(false);
    const inicial = {
        first_name: '',
        last_name: '',
        rut: '',
        position: '',
        position_otro: '',
        email: '',
        establishment_id: participante.establishment_id
            ? String(participante.establishment_id)
            : '',
        motivo: '',
    };
    const form = useForm(inicial);

    function enviar(e: FormEvent) {
        e.preventDefault();
        form.post(
            InscripcionController.reemplazar.url({
                order: ordenId,
                participant: participante.id,
            }),
            { preserveScroll: true, onSuccess: () => setAbierto(false) },
        );
    }

    if (participante.no_reemplazable) {
        return participante.estado.value === 'replaced' ? null : (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span>
                        <Button variant="ghost" size="sm" disabled>
                            <RefreshCw />
                            Reemplazar
                        </Button>
                    </span>
                </TooltipTrigger>
                <TooltipContent>{participante.no_reemplazable}</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Panel
            titulo={`Reemplazar a ${participante.nombre}`}
            descripcion="Conserva el mismo acceso y el mismo valor. La credencial anterior queda anulada."
            trigger={
                <Button variant="ghost" size="sm">
                    <RefreshCw />
                    Reemplazar
                </Button>
            }
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.setData(inicial);
                    form.clearErrors();
                }
            }}
            onSubmit={enviar}
            procesando={form.processing}
            textoEnviar="Reemplazar"
        >
            <div className="grid gap-5 sm:grid-cols-2">
                <Campo
                    label="Nombre de quien entra"
                    htmlFor="r-first_name"
                    error={form.errors.first_name}
                >
                    <Input
                        id="r-first_name"
                        required
                        autoComplete="off"
                        value={form.data.first_name}
                        onChange={(e) =>
                            form.setData('first_name', e.target.value)
                        }
                    />
                </Campo>
                <Campo
                    label="Apellidos"
                    htmlFor="r-last_name"
                    error={form.errors.last_name}
                >
                    <Input
                        id="r-last_name"
                        autoComplete="off"
                        value={form.data.last_name}
                        onChange={(e) =>
                            form.setData('last_name', e.target.value)
                        }
                    />
                </Campo>
            </div>
            <Campo label="RUT" htmlFor="r-rut" error={form.errors.rut}>
                <RutInput
                    id="r-rut"
                    className="w-48"
                    value={form.data.rut}
                    onChange={(valor) => form.setData('rut', valor)}
                />
            </Campo>
            <CampoCargo
                id="r-position"
                cargo={form.data.position}
                otro={form.data.position_otro}
                errorCargo={form.errors.position}
                errorOtro={form.errors.position_otro}
                onCargo={(valor) => form.setData('position', valor)}
                onOtro={(valor) => form.setData('position_otro', valor)}
            />
            <Campo label="Correo" htmlFor="r-email" error={form.errors.email}>
                <Input
                    id="r-email"
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
            </Campo>
            {Object.keys(establecimientos).length > 0 && (
                <Campo
                    label="Establecimiento"
                    htmlFor="r-establishment"
                    error={form.errors.establishment_id}
                >
                    <NativeSelect
                        id="r-establishment"
                        value={form.data.establishment_id}
                        onChange={(e) =>
                            form.setData('establishment_id', e.target.value)
                        }
                    >
                        <option value="">Sin establecimiento</option>
                        {Object.entries(establecimientos).map(
                            ([id, nombre]) => (
                                <option key={id} value={id}>
                                    {nombre}
                                </option>
                            ),
                        )}
                    </NativeSelect>
                </Campo>
            )}
            <Campo
                label="Motivo del cambio"
                htmlFor="r-motivo"
                error={form.errors.motivo}
            >
                <Textarea
                    id="r-motivo"
                    rows={2}
                    value={form.data.motivo}
                    onChange={(e) => form.setData('motivo', e.target.value)}
                />
            </Campo>
        </Panel>
    );
}

function CorregirParticipante({
    ordenId,
    participante,
    establecimientos,
}: {
    ordenId: number;
    participante: Participante;
    establecimientos: Record<string, string>;
}) {
    const { cargos } = usePage<{ cargos: string[] }>().props;

    return (
        <EditarSeccion
            titulo={`Corregir datos de ${participante.nombre}`}
            url={InscripcionController.corregirParticipante.url({
                order: ordenId,
                participant: participante.id,
            })}
            inicial={{
                first_name: participante.first_name,
                last_name: participante.last_name ?? '',
                rut: participante.rut ?? '',
                ...separarCargo(participante.cargo, cargos),
                email: participante.correo ?? '',
                establishment_id: participante.establishment_id
                    ? String(participante.establishment_id)
                    : '',
            }}
        >
            {(f) => (
                <>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Campo
                            label="Nombre"
                            htmlFor="c-first_name"
                            error={f.errors.first_name}
                        >
                            <Input
                                id="c-first_name"
                                required
                                value={f.data.first_name}
                                onChange={(e) =>
                                    f.set('first_name', e.target.value)
                                }
                            />
                        </Campo>
                        <Campo
                            label="Apellidos"
                            htmlFor="c-last_name"
                            error={f.errors.last_name}
                        >
                            <Input
                                id="c-last_name"
                                value={f.data.last_name}
                                onChange={(e) =>
                                    f.set('last_name', e.target.value)
                                }
                            />
                        </Campo>
                    </div>
                    <Campo label="RUT" htmlFor="c-rut" error={f.errors.rut}>
                        <RutInput
                            id="c-rut"
                            className="w-48"
                            value={f.data.rut}
                            onChange={(valor) => f.set('rut', valor)}
                        />
                    </Campo>
                    <CampoCargo
                        id="c-position"
                        cargo={f.data.position}
                        otro={f.data.position_otro}
                        errorCargo={f.errors.position}
                        errorOtro={f.errors.position_otro}
                        onCargo={(valor) => f.set('position', valor)}
                        onOtro={(valor) => f.set('position_otro', valor)}
                    />
                    <Campo
                        label="Correo"
                        htmlFor="c-email"
                        error={f.errors.email}
                    >
                        <Input
                            id="c-email"
                            type="email"
                            value={f.data.email}
                            onChange={(e) => f.set('email', e.target.value)}
                        />
                    </Campo>
                    {Object.keys(establecimientos).length > 0 && (
                        <Campo
                            label="Establecimiento"
                            htmlFor="c-establishment"
                            error={f.errors.establishment_id}
                        >
                            <NativeSelect
                                id="c-establishment"
                                value={f.data.establishment_id}
                                onChange={(e) =>
                                    f.set('establishment_id', e.target.value)
                                }
                            >
                                <option value="">Sin establecimiento</option>
                                {Object.entries(establecimientos).map(
                                    ([id, nombre]) => (
                                        <option key={id} value={id}>
                                            {nombre}
                                        </option>
                                    ),
                                )}
                            </NativeSelect>
                        </Campo>
                    )}
                </>
            )}
        </EditarSeccion>
    );
}

function ReactivarReserva({
    ordenId,
    titulo,
    reactivacion,
}: {
    ordenId: number;
    titulo: string;
    reactivacion: { impedimento: string | null; plazo: string };
}) {
    if (reactivacion.impedimento) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span>
                        <Button disabled>
                            <RotateCcw />
                            Reactivar reserva
                        </Button>
                    </span>
                </TooltipTrigger>
                <TooltipContent>{reactivacion.impedimento}</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Confirmar
            titulo={`Reactivar la reserva ${titulo}`}
            descripcion={`Vuelve a tomar sus cupos y el cliente tiene hasta el ${reactivacion.plazo} para pagar. Si ya no quedan cupos, no se reactiva y te avisamos.`}
            textoAccion="Reactivar reserva"
            destructivo={false}
            onConfirmar={() =>
                router.post(
                    InscripcionController.reactivar.url(ordenId),
                    {},
                    { preserveScroll: true },
                )
            }
        >
            <Button>
                <RotateCcw />
                Reactivar reserva
            </Button>
        </Confirmar>
    );
}

function CancelarInscripcion({
    ordenId,
    titulo,
    impedimento,
}: {
    ordenId: number;
    titulo: string;
    impedimento: string | null;
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm({ motivo: '' });

    if (impedimento) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span>
                        <Button variant="destructive" disabled>
                            <Ban />
                            Cancelar inscripción
                        </Button>
                    </span>
                </TooltipTrigger>
                <TooltipContent>{impedimento}</TooltipContent>
            </Tooltip>
        );
    }

    function enviar(e: FormEvent) {
        e.preventDefault();
        form.post(InscripcionController.cancelar.url(ordenId), {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Dialog
            open={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="destructive">
                    <Ban />
                    Cancelar inscripción
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={enviar} className="space-y-4">
                    <DialogTitle>Cancelar la inscripción {titulo}</DialogTitle>
                    <DialogDescription>
                        Sus cupos quedan libres para otros y el cliente ya no
                        podrá continuarla. No se puede deshacer.
                    </DialogDescription>
                    <Campo
                        label="Motivo"
                        htmlFor="motivo"
                        error={form.errors.motivo}
                    >
                        <Textarea
                            id="motivo"
                            rows={3}
                            required
                            value={form.data.motivo}
                            onChange={(e) =>
                                form.setData('motivo', e.target.value)
                            }
                        />
                    </Campo>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Volver
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Cancelar inscripción
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function InscripcionShow({
    orden,
    participantes,
    establecimientos,
    conjunto,
    credenciales,
    cobranza,
    pagos,
    facturacion,
    puede,
    cancelacion,
    reactivacion,
    opciones,
}: Props) {
    const titulo = orden.numero ?? 'Inscripción en borrador';

    setLayoutProps({
        breadcrumbs: [
            { title: 'Inscripciones', href: InscripcionController.index() },
            { title: titulo, href: InscripcionController.show(orden.id) },
        ],
    });

    return (
        <>
            <Head title={titulo} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {titulo}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <EstadoBadge estado={orden.estado} />
                            <EstadoBadge estado={orden.pago} />
                            <Link
                                href={EventoController.show(orden.evento.id)}
                                className="text-muted-foreground hover:underline"
                            >
                                {orden.evento.nombre}
                            </Link>
                        </div>
                        {conjunto && (
                            <Collapsible>
                                <CollapsibleTrigger className="group text-muted-foreground flex items-center gap-1 text-sm hover:underline">
                                    <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                                    Parte de una inscripción de{' '}
                                    {conjunto.length + 1} colegios
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <Table className="mt-2 w-auto">
                                        <TableBody>
                                            {conjunto.map((hermana) => (
                                                <TableRow key={hermana.id}>
                                                    <TableCell className="font-medium">
                                                        <Link
                                                            href={InscripcionController.show(
                                                                hermana.id,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {hermana.colegio}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell>
                                                        {clp(hermana.total)}
                                                    </TableCell>
                                                    <TableCell>
                                                        Saldo{' '}
                                                        {clp(hermana.saldo)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <EstadoBadge
                                                            estado={
                                                                hermana.estado
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CollapsibleContent>
                            </Collapsible>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {puede.cargarComprobante && (
                            <CargarComprobante
                                ordenId={orden.id}
                                total={orden.total}
                            />
                        )}
                        {puede.registrarFactura && (
                            <RegistrarFactura
                                ordenId={orden.id}
                                total={orden.total}
                                opciones={opciones}
                            />
                        )}
                        {reactivacion && (
                            <ReactivarReserva
                                ordenId={orden.id}
                                titulo={titulo}
                                reactivacion={reactivacion}
                            />
                        )}
                        {cancelacion && (
                            <CancelarInscripcion
                                ordenId={orden.id}
                                titulo={titulo}
                                impedimento={cancelacion.impedimento}
                            />
                        )}
                    </div>
                </header>

                <SeccionFicha titulo="Inscripción">
                    <Datos>
                        <Dato etiqueta="Total">
                            <span className="font-semibold tabular-nums">
                                {clp(orden.total)}
                            </span>
                            {orden.descuento > 0 && (
                                <span className="text-muted-foreground block text-xs">
                                    {clp(orden.subtotal)} menos{' '}
                                    {clp(orden.descuento)} ·{' '}
                                    {orden.descuento_etiqueta}
                                </span>
                            )}
                        </Dato>
                        <Dato etiqueta="Tipo">{orden.tipo}</Dato>
                        <Dato etiqueta="Confirmada">{orden.confirmada}</Dato>
                        <Dato etiqueta="Reserva vence">
                            {orden.vence && (
                                <span
                                    className={cn(
                                        orden.vencimiento &&
                                            tonoVencimiento[
                                                orden.vencimiento.tono
                                            ],
                                    )}
                                >
                                    {orden.vence}
                                    {orden.vencimiento?.nota &&
                                        ` · ${orden.vencimiento.nota}`}
                                </span>
                            )}
                        </Dato>
                    </Datos>
                </SeccionFicha>

                <div className="grid gap-6 lg:grid-cols-2">
                    <SeccionFicha titulo="Responsable">
                        <Datos>
                            <Dato etiqueta="Nombre completo">
                                {orden.responsable.nombre}
                            </Dato>
                            <Dato etiqueta="Cargo">
                                {orden.responsable.cargo}
                            </Dato>
                            <Dato etiqueta="Correo">
                                {orden.responsable.correo && (
                                    <a
                                        href={`mailto:${orden.responsable.correo}`}
                                        className="break-all hover:underline"
                                    >
                                        {orden.responsable.correo}
                                    </a>
                                )}
                            </Dato>
                            <Dato etiqueta="Teléfono">
                                {orden.responsable.telefono}
                            </Dato>
                            <Dato etiqueta="Institución">
                                {orden.responsable.institucion}
                            </Dato>
                        </Datos>
                    </SeccionFicha>

                    <SeccionFicha titulo="Entidad pagadora">
                        {orden.entidad === null ? (
                            <p className="text-muted-foreground text-sm">
                                Sin entidad pagadora.
                            </p>
                        ) : (
                            <Datos>
                                <Dato etiqueta="Razón social">
                                    {orden.entidad.nombre}
                                </Dato>
                                <Dato etiqueta="RUT">{orden.entidad.rut}</Dato>
                                <Dato etiqueta="Dirección">
                                    {orden.entidad.direccion}
                                </Dato>
                                <Dato etiqueta="Correo de facturación">
                                    {orden.entidad.correo}
                                </Dato>
                            </Datos>
                        )}
                    </SeccionFicha>
                </div>

                <SeccionFicha titulo="Participantes" className="[&>div]:p-0">
                    {participantes.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-10 text-center text-sm">
                            Sin participantes.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    {puede.reemplazar && (
                                        <TableHead className="w-44 pl-5">
                                            <span className="sr-only">
                                                Acciones
                                            </span>
                                        </TableHead>
                                    )}
                                    <TableHead
                                        className={cn(
                                            !puede.reemplazar && 'pl-5',
                                        )}
                                    >
                                        Participante
                                    </TableHead>
                                    <TableHead>RUT</TableHead>
                                    <TableHead>Establecimiento</TableHead>
                                    <TableHead>Acceso</TableHead>
                                    <TableHead className="text-right">
                                        Valor
                                    </TableHead>
                                    <TableHead>Estado</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {participantes.map((p) => (
                                    <TableRow
                                        key={p.id}
                                        className={cn(
                                            p.estado.value === 'replaced' &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {puede.reemplazar && (
                                            <TableCell className="pl-3">
                                                <div className="flex items-center gap-1">
                                                    {p.estado.value !==
                                                        'replaced' && (
                                                        <CorregirParticipante
                                                            ordenId={orden.id}
                                                            participante={p}
                                                            establecimientos={
                                                                establecimientos
                                                            }
                                                        />
                                                    )}
                                                    <Reemplazar
                                                        ordenId={orden.id}
                                                        participante={p}
                                                        establecimientos={
                                                            establecimientos
                                                        }
                                                    />
                                                </div>
                                            </TableCell>
                                        )}
                                        <TableCell
                                            className={cn(
                                                !puede.reemplazar && 'pl-5',
                                            )}
                                        >
                                            <span className="font-medium">
                                                {p.nombre}
                                            </span>
                                            {(p.cargo || p.reemplazado_por) && (
                                                <span className="text-muted-foreground block text-xs">
                                                    {p.reemplazado_por
                                                        ? `Reemplazado por ${p.reemplazado_por}`
                                                        : p.cargo}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>{p.rut ?? '—'}</TableCell>
                                        <TableCell className="max-w-48 truncate">
                                            {p.establecimiento ?? '—'}
                                        </TableCell>
                                        <TableCell>{p.acceso ?? '—'}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {clp(p.valor)}
                                        </TableCell>
                                        <TableCell>
                                            <EstadoBadge estado={p.estado} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SeccionFicha>

                {pagos !== null && pagos.length > 0 && (
                    <SeccionFicha titulo="Pagos informados">
                        <div className="mb-3 flex flex-wrap gap-6 text-sm">
                            <span>
                                Total:{' '}
                                <strong className="tabular-nums">
                                    {clp(cobranza.total)}
                                </strong>
                            </span>
                            <span>
                                Pagado:{' '}
                                <strong className="tabular-nums">
                                    {clp(cobranza.pagado)}
                                </strong>
                            </span>
                            <span>
                                Saldo:{' '}
                                <strong className="tabular-nums">
                                    {clp(cobranza.saldo)}
                                </strong>
                            </span>
                        </div>
                        <ul className="divide-y text-sm">
                            {pagos.map((pago) => (
                                <li
                                    key={pago.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-2"
                                >
                                    <span className="flex flex-wrap items-center gap-3">
                                        <Link
                                            href={ComprobanteController.show(
                                                pago.id,
                                            )}
                                            className="font-medium tabular-nums hover:underline"
                                        >
                                            {clp(pago.monto)}
                                        </Link>
                                        <EstadoBadge estado={pago.estado} />
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {pago.recibido}
                                        {pago.informado_por &&
                                            ` · ${pago.informado_por}`}
                                        {pago.observaciones_factura &&
                                            ` · Factura: ${pago.observaciones_factura}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </SeccionFicha>
                )}

                {credenciales.length > 0 && (
                    <SeccionFicha
                        titulo="Credenciales"
                        className="[&>div]:p-0"
                        accion={
                            puede.verCredenciales && (
                                <a
                                    href={InscripcionController.credenciales.url(
                                        orden.id,
                                    )}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    title="Ver todas las credenciales de esta inscripción"
                                    className="text-muted-foreground hover:text-foreground inline-flex size-8 items-center justify-center rounded-md transition-colors"
                                >
                                    <ArrowRight className="size-4" />
                                    <span className="sr-only">
                                        Ver todas las credenciales
                                    </span>
                                </a>
                            )
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">
                                        Participante
                                    </TableHead>
                                    {puede.verCredenciales && (
                                        <TableHead>
                                            Código de respaldo
                                        </TableHead>
                                    )}
                                    <TableHead>
                                        Enviada al participante
                                    </TableHead>
                                    <TableHead>Estado</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {credenciales.map((c) => (
                                    <TableRow
                                        key={c.id}
                                        className={
                                            c.url ? 'cursor-pointer' : undefined
                                        }
                                        title={
                                            c.url
                                                ? 'Ver la credencial con su código QR'
                                                : undefined
                                        }
                                        onClick={() =>
                                            c.url &&
                                            window.open(
                                                c.url,
                                                '_blank',
                                                'noopener',
                                            )
                                        }
                                    >
                                        <TableCell className="pl-5 font-medium">
                                            {c.participante}
                                        </TableCell>
                                        {puede.verCredenciales && (
                                            <TableCell className="font-mono tracking-wider">
                                                {c.codigo}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-muted-foreground">
                                            {c.enviada ?? 'Sin correo válido'}
                                        </TableCell>
                                        <TableCell>
                                            <EstadoBadge estado={c.estado} />
                                            {c.acreditado_el && (
                                                <span className="text-muted-foreground block text-xs">
                                                    el {c.acreditado_el}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </SeccionFicha>
                )}

                {/* Solo cuando hay algo que mostrar. Un bloque que dice "sin
                    documentos" no informa nada: el botón para registrar la
                    factura vive en el encabezado de la ficha. */}
                {facturacion !== null && facturacion.documentos.length > 0 && (
                    <SeccionFicha
                        titulo="Facturación"
                        accion={<EstadoBadge estado={facturacion.estado} />}
                    >
                        <ul className="divide-y text-sm">
                            {facturacion.documentos.map((doc) => (
                                <li
                                    key={doc.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-2"
                                >
                                    <span className="font-medium">
                                        {doc.tipo} N° {doc.numero}
                                    </span>
                                    <span className="text-muted-foreground text-right tabular-nums">
                                        {doc.emitido} · {clp(doc.monto)}
                                        <span className="block text-xs">
                                            {doc.enviada
                                                ? `Enviada el ${doc.enviada} a ${doc.enviada_a}`
                                                : 'No enviada al cliente'}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </SeccionFicha>
                )}

                <SeccionFicha
                    titulo="Notas internas"
                    accion={
                        puede.editarNotas && (
                            <EditarSeccion
                                titulo="Notas internas"
                                url={InscripcionController.actualizarNotas.url(
                                    orden.id,
                                )}
                                inicial={{
                                    internal_notes: orden.internal_notes ?? '',
                                }}
                            >
                                {(f) => (
                                    <Campo
                                        label="Solo las ve el equipo. No se envían al cliente."
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
                            !orden.internal_notes && 'text-muted-foreground',
                        )}
                    >
                        {orden.internal_notes || 'Sin notas.'}
                    </p>
                </SeccionFicha>
            </div>
        </>
    );
}
