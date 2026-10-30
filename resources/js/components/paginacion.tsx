import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginado } from '@/types';

const estilo = buttonVariants({ variant: 'outline', size: 'sm' });

function Paso({
    href,
    children,
}: {
    href: string | null;
    children: React.ReactNode;
}) {
    if (!href) {
        return (
            <span
                aria-disabled="true"
                className={cn(estilo, 'pointer-events-none opacity-50')}
            >
                {children}
            </span>
        );
    }

    return (
        <Link href={href} preserveScroll className={estilo}>
            {children}
        </Link>
    );
}

export function Paginacion<T>({ pagina }: { pagina: Paginado<T> }) {
    // Con 15 o menos no hay otra página: no se dibuja nada.
    if (pagina.total === 0 || pagina.last_page <= 1) {
        return pagina.total === 0 ? null : (
            <div className="text-muted-foreground text-sm">
                {pagina.from}–{pagina.to} de {pagina.total}
            </div>
        );
    }

    return (
        <div className="text-muted-foreground flex items-center justify-between gap-4 text-sm">
            <span>
                {pagina.from}–{pagina.to} de {pagina.total}
            </span>
            <div className="flex gap-2">
                <Paso href={pagina.prev_page_url}>
                    <ChevronLeft />
                    Anterior
                </Paso>
                <Paso href={pagina.next_page_url}>
                    Siguiente
                    <ChevronRight />
                </Paso>
            </div>
        </div>
    );
}
