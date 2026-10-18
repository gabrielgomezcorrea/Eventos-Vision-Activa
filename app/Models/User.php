<?php

namespace App\Models;

use App\Enums\Permiso;
use App\Enums\Rol;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 * @property bool $notify_program_requests
 * @property bool $notify_confirmed_orders
 * @property bool $notify_payment_proofs
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'is_active', 'notify_program_requests', 'notify_confirmed_orders', 'notify_payment_proofs'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Avisos internos por correo: columna, etiqueta y permiso que da derecho a
     * recibirlo. Sin el permiso el aviso mostraría datos que la persona no ve.
     */
    public const AVISOS = [
        'notify_program_requests' => ['etiqueta' => 'Nueva solicitud de programa', 'permiso' => Permiso::VerSolicitudes],
        'notify_confirmed_orders' => ['etiqueta' => 'Nueva inscripción confirmada', 'permiso' => Permiso::VerOrdenes],
        'notify_payment_proofs' => ['etiqueta' => 'Comprobante por revisar', 'permiso' => Permiso::ValidarPagos],
    ];

    // La base los trae en falso; un modelo recién creado no los tiene en memoria.
    protected $attributes = [
        'notify_program_requests' => false,
        'notify_confirmed_orders' => false,
        'notify_payment_proofs' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notify_program_requests' => 'boolean',
            'notify_confirmed_orders' => 'boolean',
            'notify_payment_proofs' => 'boolean',
        ];
    }

    /**
     * Solo usuarios internos activos y con algún rol asignado entran al panel.
     * Los clientes externos no tienen usuario: acceden por magic link.
     */
    public function puedeEntrarAlPanel(): bool
    {
        return $this->is_active && $this->roles()->exists();
    }

    /**
     * Los avisos que esta persona puede encender, con su estado actual.
     *
     * @return list<array{campo: string, etiqueta: string, activo: bool}>
     */
    public function avisosDisponibles(): array
    {
        $disponibles = [];

        foreach (self::AVISOS as $campo => $aviso) {
            if ($this->can($aviso['permiso']->value)) {
                $disponibles[] = ['campo' => $campo, 'etiqueta' => $aviso['etiqueta'], 'activo' => (bool) $this->getAttribute($campo)];
            }
        }

        return $disponibles;
    }

    /**
     * Reglas de las tres casillas para este destinatario: encender una exige el
     * permiso de lo que avisa.
     *
     * @return array<string, array<int, mixed>>
     */
    public function reglasDeAvisos(): array
    {
        $reglas = [];

        foreach (self::AVISOS as $campo => $aviso) {
            $reglas[$campo] = ['sometimes', 'boolean', function (string $atributo, mixed $valor, \Closure $falla) use ($aviso): void {
                if (filter_var($valor, FILTER_VALIDATE_BOOLEAN) && ! $this->can($aviso['permiso']->value)) {
                    $falla('Esta persona no tiene acceso a lo que avisa "'.$aviso['etiqueta'].'".');
                }
            }];
        }

        return $reglas;
    }

    public function esAdministrador(): bool
    {
        return $this->hasRole(Rol::Administrador->value);
    }
}
