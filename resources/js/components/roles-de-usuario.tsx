import { Check, Eye, Minus } from 'lucide-react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

export type OpcionRol = { value: string; label: string };

export type FilaMatriz = {
    que: string;
    marcas: Record<string, 'si' | 'mirar' | 'no'>;
};

/** Casillas de roles: sin al menos uno, la persona no puede entrar. */
export function ElegirRoles({
    roles,
    elegidos,
    onChange,
    error,
}: {
    roles: OpcionRol[];
    elegidos: string[];
    onChange: (roles: string[]) => void;
    error?: string;
}) {
    return (
        <fieldset className="space-y-2">
            <legend className="mb-2 text-sm font-medium">Rol</legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {roles.map((rol) => (
                    <label
                        key={rol.value}
                        className="has-data-[state=checked]:border-primary flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                    >
                        <Checkbox
                            checked={elegidos.includes(rol.value)}
                            onCheckedChange={(valor) =>
                                onChange(
                                    valor === true
                                        ? [...elegidos, rol.value]
                                        : elegidos.filter(
                                              (r) => r !== rol.value,
                                          ),
                                )
                            }
                        />
                        {rol.label}
                    </label>
                ))}
            </div>
            <InputError message={error} />
        </fieldset>
    );
}

/**
 * Qué puede hacer cada rol, en el lenguaje del trabajo. Se muestra al elegir el
 * rol de alguien: la pregunta en ese momento es siempre «¿y qué va a poder
 * hacer con eso?». Resalta las columnas de los roles elegidos.
 */
export function MatrizRoles({
    roles,
    filas,
    resaltar = [],
}: {
    roles: OpcionRol[];
    filas: FilaMatriz[];
    resaltar?: string[];
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b">
                        <th className="text-muted-foreground px-3 py-2 text-left font-medium">
                            Qué puede hacer
                        </th>
                        {roles.map((rol) => (
                            <th
                                key={rol.value}
                                className={cn(
                                    'px-2 py-2 text-center font-medium',
                                    resaltar.includes(rol.value)
                                        ? 'bg-accent'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {rol.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {filas.map((fila) => (
                        <tr key={fila.que} className="border-b last:border-0">
                            <td className="px-3 py-2">{fila.que}</td>
                            {roles.map((rol) => {
                                const marca = fila.marcas[rol.value];

                                return (
                                    <td
                                        key={rol.value}
                                        className={cn(
                                            'px-2 py-2 text-center',
                                            resaltar.includes(rol.value) &&
                                                'bg-accent',
                                        )}
                                    >
                                        {marca === 'si' && (
                                            <Check
                                                className="mx-auto size-4 text-emerald-600"
                                                aria-label="Sí"
                                            />
                                        )}
                                        {marca === 'mirar' && (
                                            <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                <Eye className="size-3.5" />
                                                Solo ver
                                            </span>
                                        )}
                                        {marca === 'no' && (
                                            <Minus
                                                className="text-muted-foreground/50 mx-auto size-4"
                                                aria-label="No"
                                            />
                                        )}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
