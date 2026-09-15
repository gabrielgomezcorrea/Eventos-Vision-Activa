import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Página fuera de Inertia (Blade): se abre en pestaña nueva con un enlace normal. */
    newTab?: boolean;
    /** Permiso necesario para ver el ítem; sin él, el ítem no se dibuja. */
    permiso?: string;
};
