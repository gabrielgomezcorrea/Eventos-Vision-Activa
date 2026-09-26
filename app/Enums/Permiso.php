<?php

namespace App\Enums;

/**
 * Permisos del sistema. Agregar aquí y asignarlos en Rol::permisos().
 */
enum Permiso: string
{
    case VerEventos = 'ver_eventos';
    case GestionarEventos = 'gestionar_eventos';

    case VerSolicitudes = 'ver_solicitudes';

    // A contact list leaves the system with personal data: only Administración.
    case ExportarContactos = 'exportar_contactos';

    case VerOrdenes = 'ver_ordenes';
    case GestionarOrdenes = 'gestionar_ordenes';

    // La credencial completa (QR y código de respaldo) sirve para entrar al
    // evento: solo Administración la abre desde el panel.
    case VerCredenciales = 'ver_credenciales';

    case VerComprobantes = 'ver_comprobantes';
    case CargarComprobante = 'cargar_comprobante';
    case ValidarPagos = 'validar_pagos';
    case GestionarFacturas = 'gestionar_facturas';

    case VerAcreditacion = 'ver_acreditacion';
    case AcreditarParticipantes = 'acreditar_participantes';

    // Códigos de descuento e invitaciones sin costo: regalan cupos, solo Administración.
    case GestionarDescuentos = 'gestionar_descuentos';

    case GestionarCuentasBancarias = 'gestionar_cuentas_bancarias';

    case GestionarUsuarios = 'gestionar_usuarios';
    case VerAuditoria = 'ver_auditoria';

    /** @return array<int, self> */
    public static function todos(): array
    {
        return self::cases();
    }
}
