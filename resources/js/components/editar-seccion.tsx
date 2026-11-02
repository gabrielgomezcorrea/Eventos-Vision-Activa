import { useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
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
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

export type Valores = Record<string, string>;

export type FormularioSeccion = {
    data: Valores;
    set: (campo: string, valor: string) => void;
    errors: Partial<Record<string, string>>;
};

type Props = {
    titulo: string;
    url: string;
    inicial: Valores;
    children: (form: FormularioSeccion) => ReactNode;
};

/**
 * El lápiz de un bloque de la ficha: abre un panel lateral con solo sus campos
 * y guarda directo. La ficha queda visible detrás, así no se pierde el contexto.
 */
export function EditarSeccion({ titulo, url, inicial, children }: Props) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm<Valores>(inicial);

    function guardar(e: FormEvent) {
        e.preventDefault();
        form.patch(url, {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Sheet
            open={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                // Al abrir parte siempre de lo guardado, no de un intento anterior.
                if (valor) {
                    form.setData(inicial);
                    form.clearErrors();
                }
            }}
        >
            <Tooltip>
                <TooltipTrigger asChild>
                    <SheetTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Editar ${titulo.toLowerCase()}`}
                        >
                            <Pencil />
                        </Button>
                    </SheetTrigger>
                </TooltipTrigger>
                <TooltipContent>Editar {titulo.toLowerCase()}</TooltipContent>
            </Tooltip>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <form onSubmit={guardar} className="flex min-h-full flex-col">
                    <SheetHeader>
                        <SheetTitle>{titulo}</SheetTitle>
                        <SheetDescription className="sr-only">
                            Editar {titulo.toLowerCase()}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex-1 space-y-5 px-4">
                        {Object.keys(form.errors).length > 0 && (
                            <div
                                role="alert"
                                className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800"
                            >
                                <p className="font-medium">
                                    No se guardó. Corrige esto:
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {Object.values(form.errors).map((m) => (
                                        <li key={m}>{m}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {children({
                            data: form.data,
                            set: (campo, valor) => form.setData(campo, valor),
                            errors: form.errors,
                        })}
                    </div>
                    <SheetFooter>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Guardando…' : 'Guardar'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
