import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Paginado } from '@/types';

export function Paginacion<T>({ pagina }: { pagina: Paginado<T> }) {
    if (pagina.total === 0) {
        return null;
    }

    return (
        <div className="text-muted-foreground flex items-center justify-between gap-4 text-sm">
            <span>
                {pagina.from}–{pagina.to} de {pagina.total}
            </span>
            {pagina.last_page > 1 && (
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!pagina.prev_page_url}
                        asChild={!!pagina.prev_page_url}
                    >
                        {pagina.prev_page_url ? (
                            <Link href={pagina.prev_page_url} preserveScroll>
                                <ChevronLeft />
                                Anterior
                            </Link>
                        ) : (
                            <span>
                                <ChevronLeft />
                                Anterior
                            </span>
                        )}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!pagina.next_page_url}
                        asChild={!!pagina.next_page_url}
                    >
                        {pagina.next_page_url ? (
                            <Link href={pagina.next_page_url} preserveScroll>
                                Siguiente
                                <ChevronRight />
                            </Link>
                        ) : (
                            <span>
                                Siguiente
                                <ChevronRight />
                            </span>
                        )}
                    </Button>
                </div>
            )}
        </div>
    );
}
