import { cn } from '@/lib/utils';
import type { Estado } from '@/types';

const colores: Record<string, string> = {
    gray: 'bg-muted text-muted-foreground',
    success:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    warning:
        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    danger: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
};

/** Estado con color semántico y siempre con texto: el color solo no basta. */
export function EstadoBadge({ estado }: { estado: Estado }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
                colores[estado.color] ?? colores.gray,
            )}
        >
            {estado.label}
        </span>
    );
}
