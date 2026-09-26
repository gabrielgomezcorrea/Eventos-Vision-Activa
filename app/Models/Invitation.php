<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invitación personal a un evento: un enlace de un solo uso, atado a un correo,
 * que inscribe a la persona como invitada (sin pago) con el acceso que fijó
 * quien la invitó. El tipo y el evento salen siempre de esta fila, nunca de la URL.
 *
 * @property int $id
 * @property int $event_id
 * @property int $access_type_id
 * @property string $email
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 */
class Invitation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<AccessType, $this> */
    public function accessType(): BelongsTo
    {
        return $this->belongsTo(AccessType::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Crea la invitación y devuelve el token en claro, que solo existe aquí y
     * en el correo. En la base queda únicamente su hash.
     *
     * @return array{0: self, 1: string}
     */
    public static function emitir(Event $event, AccessType $acceso, string $email, \DateTimeInterface $venceEl, ?User $creador = null): array
    {
        $token = Str::random(64);

        $invitacion = self::create([
            'event_id' => $event->getKey(),
            'access_type_id' => $acceso->getKey(),
            'email' => mb_strtolower(trim($email)),
            'token_hash' => self::hash($token),
            'expires_at' => $venceEl,
            'created_by' => $creador?->getKey(),
        ]);

        return [$invitacion, $token];
    }

    /** Busca por token en claro sin exigir que siga vigente: la página dice por qué ya no sirve. */
    public static function buscar(string $token): ?self
    {
        return self::query()->where('token_hash', self::hash($token))->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Sigue esperando a que la persona se inscriba. */
    public function estaPendiente(): bool
    {
        return $this->estado() === 'pending';
    }

    /** @return 'used'|'revoked'|'expired'|'pending' */
    public function estado(): string
    {
        return match (true) {
            $this->used_at !== null => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /** Cómo se lee el estado en el panel. */
    public function estadoEtiqueta(): string
    {
        return match ($this->estado()) {
            'used' => 'Usada',
            'revoked' => 'Anulada',
            'expired' => 'Vencida',
            default => 'Enviada',
        };
    }
}
