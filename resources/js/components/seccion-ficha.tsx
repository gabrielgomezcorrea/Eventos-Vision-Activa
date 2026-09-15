import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    titulo: string;
    accion?: ReactNode;
    className?: string;
    children: ReactNode;
};

/** Un bloque de la ficha: título, su lápiz a la derecha y el contenido. */
export function SeccionFicha({ titulo, accion, className, children }: Props) {
    return (
        <section
            className={cn('bg-card rounded-xl border shadow-xs', className)}
        >
            <header className="flex min-h-14 items-center justify-between gap-2 border-b px-5 py-2">
                <h2 className="text-base font-semibold">{titulo}</h2>
                {accion}
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

/**
 * Rejilla de datos de solo lectura.
 *
 * `columnas` se baja a 2 cuando el bloque tiene datos largos o una cantidad que
 * en tres columnas deja una fila coja: cinco datos en tres columnas quedan 3 + 1
 * y se ve a medio terminar.
 */
export function Datos({
    children,
    columnas = 3,
}: {
    children: ReactNode;
    columnas?: 2 | 3;
}) {
    return (
        <dl
            className={cn(
                'grid gap-x-6 gap-y-4 sm:grid-cols-2',
                columnas === 3 && 'lg:grid-cols-3',
            )}
        >
            {children}
        </dl>
    );
}

export function Dato({
    etiqueta,
    children,
    ancho = false,
}: {
    etiqueta: string;
    children: ReactNode;
    ancho?: boolean;
}) {
    const vacio =
        children === null || children === undefined || children === '';

    return (
        <div className={cn('min-w-0', ancho && 'col-span-full')}>
            <dt className="text-muted-foreground text-sm">{etiqueta}</dt>
            <dd
                className={cn(
                    'mt-0.5 text-sm break-words',
                    vacio && 'text-muted-foreground',
                )}
            >
                {vacio ? 'Sin definir' : children}
            </dd>
        </div>
    );
}
