import { useState } from 'react';
import type { ReactNode } from 'react';
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

type Props = {
    /** Nombra lo que se va a borrar: "¿Estás seguro?" no le dice a nadie qué pierde. */
    titulo: string;
    descripcion: string;
    textoAccion: string;
    onConfirmar: () => void;
    /** Falso para confirmar algo que no borra: el botón deja de ser rojo. */
    destructivo?: boolean;
    /** Segunda salida, en negro, para lo más drástico (por ejemplo "Limpiar solicitud"). */
    accionExtra?: { texto: string; onConfirmar: () => void };
    children: ReactNode;
};

/** Confirmación de una acción; si destruye algo, con el botón en rojo. */
export function Confirmar({
    titulo,
    descripcion,
    textoAccion,
    onConfirmar,
    destructivo = true,
    accionExtra,
    children,
}: Props) {
    const [abierto, setAbierto] = useState(false);

    return (
        <Dialog open={abierto} onOpenChange={setAbierto}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <DialogTitle>{titulo}</DialogTitle>
                <DialogDescription>{descripcion}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant={destructivo ? 'destructive' : 'default'}
                        onClick={() => {
                            onConfirmar();
                            setAbierto(false);
                        }}
                    >
                        {textoAccion}
                    </Button>
                    {accionExtra && (
                        <Button
                            className="bg-black text-white hover:bg-black/80"
                            onClick={() => {
                                accionExtra.onConfirmar();
                                setAbierto(false);
                            }}
                        >
                            {accionExtra.texto}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
