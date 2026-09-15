import { usePage } from '@inertiajs/react';
import { mainNavItems } from '@/lib/navigation';
import type { NavItem } from '@/types';

/** El menú lateral con solo lo que el rol del usuario puede abrir. */
export function useMainNavItems(): NavItem[] {
    const { permisos } = usePage().props.auth;

    return mainNavItems.filter(
        (item) => !item.permiso || permisos.includes(item.permiso),
    );
}
