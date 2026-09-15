import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import CuentaBancariaController from '@/actions/App/Http/Controllers/Configuracion/CuentaBancariaController';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import { CamposCuentaBancaria } from '@/components/campos-cuenta-bancaria';
import type { OpcionesCuenta } from '@/components/campos-cuenta-bancaria';
import { Confirmar } from '@/components/confirmar';
import { EditarSeccion } from '@/components/editar-seccion';
import { Dato, Datos, SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Props = {
    cuenta: {
        id: number;
        label: string;
        holder_name: string;
        holder_rut: string | null;
        bank_name: string;
        account_type: string | null;
        account_number: string;
        email: string | null;
        payment_instructions: string | null;
        is_active: boolean;
    };
    eventos: { id: number; nombre: string }[];
    opciones: OpcionesCuenta;
};

export default function CuentaBancaria({ cuenta, eventos, opciones }: Props) {
    const enUso = eventos.length > 0;

    const botonEliminar = (
        <Button variant="destructive" size="sm" disabled={enUso}>
            <Trash2 />
            Eliminar
        </Button>
    );

    return (
        <>
            <Head title={cuenta.label} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Button variant="ghost" size="sm" asChild className="-ml-3">
                        <Link href={CuentaBancariaController.index()}>
                            <ArrowLeft />
                            Cuentas bancarias
                        </Link>
                    </Button>
                    {enUso ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span>{botonEliminar}</span>
                            </TooltipTrigger>
                            <TooltipContent>
                                No se puede eliminar: hay eventos usando esta
                                cuenta. Desactívala para que deje de ofrecerse.
                            </TooltipContent>
                        </Tooltip>
                    ) : (
                        <Confirmar
                            titulo={`Eliminar «${cuenta.label}»`}
                            descripcion="Ningún evento la usa. Se borra para siempre."
                            textoAccion="Eliminar cuenta"
                            onConfirmar={() =>
                                router.delete(
                                    CuentaBancariaController.destroy.url(
                                        cuenta.id,
                                    ),
                                )
                            }
                        >
                            {botonEliminar}
                        </Confirmar>
                    )}
                </div>

                <SeccionFicha
                    titulo={cuenta.label}
                    accion={
                        <EditarSeccion
                            titulo="Datos de la cuenta"
                            url={CuentaBancariaController.update.url(cuenta.id)}
                            inicial={{
                                label: cuenta.label,
                                holder_name: cuenta.holder_name,
                                holder_rut: cuenta.holder_rut ?? '',
                                bank_name: cuenta.bank_name,
                                account_type: cuenta.account_type ?? '',
                                account_number: cuenta.account_number,
                                email: cuenta.email ?? '',
                                payment_instructions:
                                    cuenta.payment_instructions ?? '',
                                is_active: cuenta.is_active ? '1' : '0',
                            }}
                        >
                            {(f) => (
                                <CamposCuentaBancaria
                                    {...f}
                                    opciones={opciones}
                                />
                            )}
                        </EditarSeccion>
                    }
                >
                    <Datos>
                        <Dato etiqueta="Banco">{cuenta.bank_name}</Dato>
                        <Dato etiqueta="Tipo de cuenta">
                            {cuenta.account_type}
                        </Dato>
                        <Dato etiqueta="Número de cuenta">
                            {cuenta.account_number}
                        </Dato>
                        <Dato etiqueta="Titular">{cuenta.holder_name}</Dato>
                        <Dato etiqueta="RUT del titular">
                            {cuenta.holder_rut}
                        </Dato>
                        <Dato etiqueta="Correo para avisos de pago">
                            {cuenta.email}
                        </Dato>
                        <Dato etiqueta="Instrucciones de pago" ancho>
                            {cuenta.payment_instructions}
                        </Dato>
                        <Dato etiqueta="Disponible para nuevos eventos">
                            {cuenta.is_active ? 'Sí' : 'No'}
                        </Dato>
                    </Datos>
                </SeccionFicha>

                <SeccionFicha titulo="Eventos que la usan">
                    {enUso ? (
                        <ul className="space-y-1 text-sm">
                            {eventos.map((evento) => (
                                <li key={evento.id}>
                                    <Link
                                        href={EventoController.show(evento.id)}
                                        className="hover:underline"
                                    >
                                        {evento.nombre}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Ningún evento la usa todavía.
                        </p>
                    )}
                </SeccionFicha>
            </div>
        </>
    );
}
