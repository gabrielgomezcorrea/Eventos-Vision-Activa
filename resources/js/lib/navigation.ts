import {
    Banknote,
    CalendarDays,
    ClipboardList,
    Inbox,
    LayoutGrid,
    QrCode,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { inicio as acreditacion } from '@/routes/acreditacion';
import { index as comprobantes } from '@/routes/comprobantes';
import { index as eventos } from '@/routes/eventos';
import { index as inscripciones } from '@/routes/inscripciones';
import { index as solicitudes } from '@/routes/solicitudes';
import type { NavItem } from '@/types';

/**
 * Menú lateral: solo el trabajo diario. Lo esporádico (perfil, cuentas
 * bancarias, usuarios) vive en Configuración, bajo el avatar. Cada ítem pide
 * el mismo permiso que su policy en el servidor.
 */
export const mainNavItems: NavItem[] = [
    { title: 'Escritorio', href: dashboard(), icon: LayoutGrid },
    {
        title: 'Eventos',
        href: eventos(),
        icon: CalendarDays,
        permiso: 'ver_eventos',
    },
    {
        title: 'Solicitudes',
        href: solicitudes(),
        icon: Inbox,
        permiso: 'ver_solicitudes',
    },
    {
        title: 'Inscripciones',
        href: inscripciones(),
        icon: ClipboardList,
        permiso: 'ver_ordenes',
    },
    {
        title: 'Comprobantes',
        href: comprobantes(),
        icon: Banknote,
        permiso: 'ver_comprobantes',
    },
    // En la puerta del evento se escanea sin perder el panel que se venía usando.
    {
        title: 'Acreditar en terreno',
        href: acreditacion(),
        icon: QrCode,
        newTab: true,
        permiso: 'ver_acreditacion',
    },
];
