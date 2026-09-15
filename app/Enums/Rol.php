<?php

namespace App\Enums;

/**
 * Roles internos del sistema. Los permisos concretos se manejan con
 * spatie/laravel-permission; este enum fija los nombres canónicos.
 */
enum Rol: string
{
    case Administrador = 'administrador';
    case Coordinacion = 'coordinacion';
    case Contabilidad = 'contabilidad';
    case Acreditacion = 'acreditacion';

    public function label(): string
    {
        return match ($this) {
            self::Administrador => 'Administrador',
            self::Coordinacion => 'Coordinación',
            self::Contabilidad => 'Contabilidad',
            self::Acreditacion => 'Acreditación',
        };
    }

    /**
     * Permisos que se asignan a este rol al sembrar la base.
     *
     * @return array<int, Permiso>
     */
    public function permisos(): array
    {
        return match ($this) {
            self::Administrador => Permiso::todos(),

            self::Coordinacion => [
                Permiso::VerEventos,
                Permiso::VerSolicitudes,
                Permiso::GestionarEventos,
                Permiso::VerOrdenes,
                Permiso::GestionarOrdenes,
                Permiso::CargarComprobante,
                Permiso::VerComprobantes,
                Permiso::VerAcreditacion,
                Permiso::VerAuditoria,
            ],

            // Contabilidad no configura eventos ni hace seguimiento comercial:
            // conserva Inscripciones porque para revisar un pago necesita ver
            // monto, participantes y estado en su contexto.
            self::Contabilidad => [
                Permiso::VerOrdenes,
                Permiso::VerComprobantes,
                Permiso::CargarComprobante,
                Permiso::ValidarPagos,
                Permiso::GestionarFacturas,
                Permiso::VerAuditoria,
            ],

            // Acreditación no accede a comprobantes, facturas ni antecedentes bancarios.
            self::Acreditacion => [
                Permiso::VerAcreditacion,
                Permiso::AcreditarParticipantes,
            ],
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
