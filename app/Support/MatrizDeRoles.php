<?php

namespace App\Support;

use App\Enums\Permiso;
use App\Enums\Rol;

/**
 * Qué puede hacer cada rol, en el lenguaje del trabajo y no en el de los
 * permisos.
 *
 * Las marcas se calculan desde `Rol::permisos()`, no se escriben a mano: una
 * tabla copiada empieza a mentir en cuanto alguien agrega un permiso, y esta
 * se muestra justo cuando se está decidiendo el rol de una persona.
 */
class MatrizDeRoles
{
    /**
     * Cada capacidad con el permiso que la habilita y, cuando existe, el que
     * da solo mirar sin poder actuar.
     *
     * @return array<int, array{que: string, hacer: Permiso, mirar?: Permiso}>
     */
    public static function capacidades(): array
    {
        return [
            ['que' => 'Crear y configurar eventos', 'hacer' => Permiso::GestionarEventos, 'mirar' => Permiso::VerEventos],
            ['que' => 'Ver solicitudes de programa', 'hacer' => Permiso::VerSolicitudes],
            ['que' => 'Exportar contactos', 'hacer' => Permiso::ExportarContactos],
            ['que' => 'Ver inscripciones', 'hacer' => Permiso::VerOrdenes],
            ['que' => 'Editar inscripciones y reemplazar participantes', 'hacer' => Permiso::GestionarOrdenes],
            ['que' => 'Ver credenciales con su QR', 'hacer' => Permiso::VerCredenciales],
            ['que' => 'Cargar un comprobante por el cliente', 'hacer' => Permiso::CargarComprobante],
            ['que' => 'Aprobar, observar o rechazar un pago', 'hacer' => Permiso::ValidarPagos, 'mirar' => Permiso::VerComprobantes],
            ['que' => 'Registrar facturas', 'hacer' => Permiso::GestionarFacturas],
            ['que' => 'Escanear y acreditar en la puerta', 'hacer' => Permiso::AcreditarParticipantes, 'mirar' => Permiso::VerAcreditacion],
            ['que' => 'Crear usuarios y cuentas bancarias', 'hacer' => Permiso::GestionarUsuarios],
        ];
    }

    /**
     * La tabla lista para dibujar.
     *
     * @return array<int, array{que: string, marcas: array<string, 'si'|'mirar'|'no'>}>
     */
    public static function filas(): array
    {
        return array_map(fn (array $capacidad): array => [
            'que' => $capacidad['que'],
            'marcas' => self::marcas($capacidad),
        ], self::capacidades());
    }

    /**
     * @param  array{que: string, hacer: Permiso, mirar?: Permiso}  $capacidad
     * @return array<string, 'si'|'mirar'|'no'>
     */
    private static function marcas(array $capacidad): array
    {
        $marcas = [];

        foreach (Rol::cases() as $rol) {
            $marcas[$rol->value] = self::marca($rol, $capacidad);
        }

        return $marcas;
    }

    /**
     * @param  array{que: string, hacer: Permiso, mirar?: Permiso}  $capacidad
     * @return 'si'|'mirar'|'no'
     */
    private static function marca(Rol $rol, array $capacidad): string
    {
        $permisos = $rol->permisos();

        if (in_array($capacidad['hacer'], $permisos, strict: true)) {
            return 'si';
        }

        if (isset($capacidad['mirar']) && in_array($capacidad['mirar'], $permisos, strict: true)) {
            return 'mirar';
        }

        return 'no';
    }
}
