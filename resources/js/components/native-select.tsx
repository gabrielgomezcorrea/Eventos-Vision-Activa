import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Selector nativo con el aspecto de los inputs de shadcn.
 *
 * Nativo a propósito: en listas largas (346 comunas) se escribe la primera
 * letra y salta, y en el celular abre la rueda del sistema que la gente ya
 * conoce.
 */
export function NativeSelect({
    className,
    ...props
}: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'border-input focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 h-9 w-full rounded-md border bg-white px-3 py-1 text-base shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                className,
            )}
            {...props}
        />
    );
}
