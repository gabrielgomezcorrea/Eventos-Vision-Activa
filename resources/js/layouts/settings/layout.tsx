import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { index as cuentasBancarias } from '@/routes/cuentas-bancarias';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as usuarios } from '@/routes/usuarios';
import { index as webs } from '@/routes/webs';
import type { NavItem } from '@/types';

/**
 * Menú interno de Configuración, a la izquierda como en el kit.
 * Cada opción pide el mismo permiso que su pantalla en el servidor.
 */
const settingsNavItems: NavItem[] = [
    { title: 'Mi perfil', href: edit(), icon: null },
    { title: 'Contraseña', href: editSecurity(), icon: null },
    {
        title: 'Cuentas bancarias',
        href: cuentasBancarias(),
        icon: null,
        permiso: 'gestionar_cuentas_bancarias',
    },
    {
        title: 'Usuarios',
        href: usuarios(),
        icon: null,
        permiso: 'gestionar_usuarios',
    },
    {
        title: 'Websites',
        href: webs(),
        icon: null,
        permiso: 'gestionar_usuarios',
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { permisos } = usePage().props.auth;

    const opciones = settingsNavItems.filter(
        (item) => !item.permiso || permisos.includes(item.permiso),
    );

    return (
        <div className="px-4 py-6">
            <Heading title="Configuración" />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48 lg:shrink-0">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Configuración"
                    >
                        {opciones.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>{item.title}</Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="min-w-0 flex-1">
                    <section className="max-w-5xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
