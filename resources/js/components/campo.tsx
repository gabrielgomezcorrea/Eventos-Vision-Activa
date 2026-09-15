import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    htmlFor: string;
    error?: string;
    /** Solo cuando la etiqueta no alcanza: qué es el campo o un ejemplo. */
    ayuda?: string;
    className?: string;
    children: ReactNode;
};

/** Etiqueta, control y error de validación, siempre en el mismo orden. */
export function Campo({
    label,
    htmlFor,
    error,
    ayuda,
    className,
    children,
}: Props) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {ayuda && (
                <p className="text-muted-foreground -mt-1 text-xs">{ayuda}</p>
            )}
            {children}
            <InputError message={error} />
        </div>
    );
}
