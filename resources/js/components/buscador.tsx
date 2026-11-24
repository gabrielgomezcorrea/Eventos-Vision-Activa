import { ChevronsUpDown, Search } from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

export type OpcionBuscable = {
    id: number;
    /** Main line: what gets shown once chosen. */
    nombre: string;
    /** Small second line, also searched (email, school). */
    detalle?: string;
};

type Props = {
    opciones: OpcionBuscable[];
    valor: number | null;
    onElegir: (id: number) => void;
    placeholder?: string;
    disabled?: boolean;
    'aria-label'?: string;
    className?: string;
};

/** "José Pérez" is found typing "jose perez": no accents, no case. */
function plano(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const MAXIMO = 50;

/**
 * Select you can type into: filters by name and detail as you write, arrows
 * and Enter to choose, Escape to close. Built here to avoid adding cmdk and a
 * popover package for one field.
 */
export function Buscador({
    opciones,
    valor,
    onElegir,
    placeholder = 'Buscar…',
    disabled = false,
    className,
    ...resto
}: Props) {
    const elegida = opciones.find((o) => o.id === valor) ?? null;
    const [abierto, setAbierto] = useState(false);
    const [texto, setTexto] = useState('');
    const [activa, setActiva] = useState(0);
    const caja = useRef<HTMLDivElement>(null);
    const lista = useRef<HTMLUListElement>(null);

    const filtradas = useMemo(() => {
        const palabras = plano(texto).split(/\s+/).filter(Boolean);

        if (palabras.length === 0) {
            return opciones;
        }

        return opciones.filter((o) => {
            const donde = plano(`${o.nombre} ${o.detalle ?? ''}`);

            return palabras.every((p) => donde.includes(p));
        });
    }, [opciones, texto]);

    const visibles = filtradas.slice(0, MAXIMO);

    // The list is drawn over the whole page (portal): inside a table or a card
    // with its own scroll it would be cut off.
    const [posicion, setPosicion] = useState<CSSProperties>({});

    useLayoutEffect(() => {
        if (!abierto) {
            return;
        }

        function ubicar() {
            const r = caja.current?.getBoundingClientRect();

            if (!r) {
                return;
            }

            const abajo = window.innerHeight - r.bottom;
            const arriba = r.top;
            const haciaArriba = abajo < 240 && arriba > abajo;
            const alto = Math.min(288, (haciaArriba ? arriba : abajo) - 16);

            setPosicion({
                position: 'fixed',
                left: r.left,
                width: Math.max(r.width, 256),
                maxHeight: Math.max(alto, 120),
                ...(haciaArriba
                    ? { bottom: window.innerHeight - r.top + 4 }
                    : { top: r.bottom + 4 }),
            });
        }

        ubicar();
        window.addEventListener('scroll', ubicar, true);
        window.addEventListener('resize', ubicar);

        return () => {
            window.removeEventListener('scroll', ubicar, true);
            window.removeEventListener('resize', ubicar);
        };
    }, [abierto]);

    useEffect(() => {
        function fuera(e: MouseEvent) {
            const dentro =
                caja.current?.contains(e.target as Node) ||
                lista.current?.contains(e.target as Node);

            if (!dentro) {
                setAbierto(false);
            }
        }

        document.addEventListener('mousedown', fuera);

        return () => document.removeEventListener('mousedown', fuera);
    }, []);

    useEffect(() => {
        lista.current
            ?.querySelector(`[data-indice="${activa}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [activa]);

    function abrir() {
        setTexto('');
        setActiva(0);
        setAbierto(true);
    }

    function elegir(o: OpcionBuscable) {
        setAbierto(false);

        if (o.id !== valor) {
            onElegir(o.id);
        }
    }

    function teclas(e: KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setAbierto(true);
            setActiva((i) => Math.min(i + 1, visibles.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiva((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter' && abierto && visibles[activa]) {
            e.preventDefault();
            elegir(visibles[activa]);
        } else if (e.key === 'Escape') {
            setAbierto(false);
        }
    }

    return (
        <div ref={caja} className={cn('relative', className)}>
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <input
                    type="text"
                    role="combobox"
                    aria-expanded={abierto}
                    aria-label={resto['aria-label']}
                    disabled={disabled}
                    className="border-input focus-visible:border-ring focus-visible:ring-ring/50 flex h-9 w-full min-w-0 rounded-md border bg-white py-1 pr-8 pl-9 text-sm shadow-xs outline-none placeholder:text-slate-500 focus-visible:ring-[3px] disabled:opacity-50"
                    placeholder={elegida ? elegida.nombre : placeholder}
                    value={abierto ? texto : (elegida?.nombre ?? '')}
                    onFocus={abrir}
                    onClick={() => !abierto && abrir()}
                    onChange={(e) => {
                        setTexto(e.target.value);
                        setActiva(0);
                        setAbierto(true);
                    }}
                    onKeyDown={teclas}
                />
                <ChevronsUpDown className="text-muted-foreground pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2" />
            </div>

            {abierto &&
                createPortal(
                    <ul
                        ref={lista}
                        role="listbox"
                        style={posicion}
                        className="bg-popover text-popover-foreground z-50 overflow-y-auto rounded-md border p-1 shadow-lg"
                    >
                        {visibles.length === 0 ? (
                            <li className="text-muted-foreground px-2 py-3 text-sm">
                                Nadie coincide con «{texto}».
                            </li>
                        ) : (
                            visibles.map((o, i) => (
                                <li
                                    key={o.id}
                                    role="option"
                                    data-indice={i}
                                    aria-selected={o.id === valor}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => elegir(o)}
                                    onMouseEnter={() => setActiva(i)}
                                    className={cn(
                                        'cursor-pointer rounded-sm px-2 py-1.5 text-sm',
                                        i === activa && 'bg-accent',
                                        o.id === valor && 'font-medium',
                                    )}
                                >
                                    <span className="block">{o.nombre}</span>
                                    {o.detalle && (
                                        <span className="text-muted-foreground block truncate text-xs">
                                            {o.detalle}
                                        </span>
                                    )}
                                </li>
                            ))
                        )}
                        {filtradas.length > MAXIMO && (
                            <li className="text-muted-foreground px-2 py-1.5 text-xs">
                                Y {filtradas.length - MAXIMO} más: escribe para
                                acotar.
                            </li>
                        )}
                    </ul>,
                    document.body,
                )}
        </div>
    );
}
