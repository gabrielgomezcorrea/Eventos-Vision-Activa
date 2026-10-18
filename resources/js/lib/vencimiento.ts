export type Vencimiento = { tono: string; nota: string | null } | null;

export const tonoVencimiento: Record<string, string> = {
    info: 'text-sky-700 dark:text-sky-400',
    warning: 'text-amber-700 dark:text-amber-400',
    danger: 'text-red-700 dark:text-red-400',
};
