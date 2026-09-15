<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Registro de auditoría. Todo cambio relevante de estado debe pasar por aquí.
 */
class Auditor
{
    /**
     * @param  Model  $sobre  entidad afectada
     * @param  string  $accion  ej. 'pago.aprobado'
     * @param  array<string, mixed>  $propiedades
     */
    public static function registrar(
        Model $sobre,
        string $accion,
        ?string $estadoAnterior = null,
        ?string $estadoNuevo = null,
        ?string $comentario = null,
        array $propiedades = [],
        ?string $actorLabel = null,
    ): AuditLog {
        $usuario = Auth::user();

        return AuditLog::create([
            'user_id' => $usuario?->getKey(),
            'actor_label' => $actorLabel ?? $usuario->name ?? 'Sistema',
            'auditable_type' => $sobre->getMorphClass(),
            'auditable_id' => $sobre->getKey(),
            'action' => $accion,
            'old_status' => $estadoAnterior,
            'new_status' => $estadoNuevo,
            'comment' => $comentario,
            'properties' => $propiedades ?: null,
            'ip_address' => Request::ip(),
        ]);
    }
}
