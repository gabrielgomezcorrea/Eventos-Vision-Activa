import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export type Aviso = { campo: string; etiqueta: string; activo: boolean };

/** Casillas de los avisos internos por correo. Solo llegan las que la persona puede recibir. */
export function ListaDeAvisos({
    avisos,
    valores,
    onChange,
    errores,
    prefijo,
}: {
    avisos: Aviso[];
    valores: Record<string, boolean>;
    onChange: (campo: string, activo: boolean) => void;
    errores: Partial<Record<string, string>>;
    prefijo: string;
}) {
    return (
        <div className="space-y-3">
            {avisos.map((aviso) => (
                <div key={aviso.campo} className="space-y-1">
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`${prefijo}-${aviso.campo}`}
                            checked={valores[aviso.campo] ?? false}
                            onCheckedChange={(valor) =>
                                onChange(aviso.campo, valor === true)
                            }
                        />
                        <Label htmlFor={`${prefijo}-${aviso.campo}`}>
                            {aviso.etiqueta}
                        </Label>
                    </div>
                    <InputError message={errores[aviso.campo]} />
                </div>
            ))}
        </div>
    );
}
