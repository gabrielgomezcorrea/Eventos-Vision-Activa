/** Estado con su etiqueta y color semántico, tal como lo manda el servidor. */
export type Estado = {
    value: string;
    label: string;
    color: string;
};

/** Paginador de Laravel serializado. */
export type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
