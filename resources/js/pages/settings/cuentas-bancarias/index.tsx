import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CuentaBancariaController from '@/actions/App/Http/Controllers/Configuracion/CuentaBancariaController';
import {
    CamposCuentaBancaria,
    cuentaVacia,
} from '@/components/campos-cuenta-bancaria';
import type { OpcionesCuenta } from '@/components/campos-cuenta-bancaria';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Cuenta = {
    id: number;
    label: string;
    banco: string;
    numero: string;
    titular: string;
    eventos: number;
    activa: boolean;
};

type Props = {
    cuentas: Cuenta[];
    abrirNueva: boolean;
    opciones: OpcionesCuenta;
};

function NuevaCuenta({
    abrirAlCargar,
    opciones,
}: {
    abrirAlCargar: boolean;
    opciones: OpcionesCuenta;
}) {
    const [abierto, setAbierto] = useState(abrirAlCargar);
    const form = useForm<Record<string, string>>({ ...cuentaVacia });

    function crear(e: FormEvent) {
        e.preventDefault();
        form.post(CuentaBancariaController.store.url());
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
                <Button size="sm">
                    <Plus />
                    Nueva cuenta
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={crear} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Nueva cuenta bancaria</DialogTitle>
                        <DialogDescription>
                            Es lo que el cliente ve para transferir.
                        </DialogDescription>
                    </DialogHeader>
                    <CamposCuentaBancaria
                        data={form.data}
                        set={(campo, valor) => form.setData(campo, valor)}
                        errors={form.errors}
                        opciones={opciones}
                    />
                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creando…' : 'Crear cuenta'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function CuentasBancarias({
    cuentas,
    abrirNueva,
    opciones,
}: Props) {
    return (
        <>
            <Head title="Cuentas bancarias" />
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <Heading variant="small" title="Cuentas bancarias" />
                    <NuevaCuenta
                        abrirAlCargar={abrirNueva}
                        opciones={opciones}
                    />
                </div>

                <div className="bg-card rounded-xl border shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Cuenta</TableHead>
                                <TableHead>Titular</TableHead>
                                <TableHead className="text-center">
                                    Eventos que la usan
                                </TableHead>
                                <TableHead>Disponible</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {cuentas.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={4}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        Todavía no hay cuentas. Carga la de la
                                        empresa una vez y los eventos podrán
                                        elegirla.
                                    </TableCell>
                                </TableRow>
                            )}
                            {cuentas.map((cuenta) => (
                                <TableRow
                                    key={cuenta.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.visit(
                                            CuentaBancariaController.show.url(
                                                cuenta.id,
                                            ),
                                        )
                                    }
                                >
                                    <TableCell className="pl-5">
                                        <Link
                                            href={CuentaBancariaController.show(
                                                cuenta.id,
                                            )}
                                            className="font-medium hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {cuenta.label}
                                        </Link>
                                        <span className="text-muted-foreground block text-xs">
                                            {cuenta.banco} · {cuenta.numero}
                                        </span>
                                    </TableCell>
                                    <TableCell>{cuenta.titular}</TableCell>
                                    <TableCell className="text-center tabular-nums">
                                        {cuenta.eventos}
                                    </TableCell>
                                    <TableCell>
                                        {cuenta.activa ? 'Sí' : 'No'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}
