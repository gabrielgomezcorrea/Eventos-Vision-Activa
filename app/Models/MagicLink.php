<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Enlace seguro con el que el cliente accede a su inscripción sin contraseña.
 *
 * Solo se guarda el hash del token. El token en claro se genera una vez, viaja
 * en el correo y no vuelve a existir en el sistema: ni siquiera un usuario con
 * acceso a la base de datos puede reconstruir un enlace ajeno.
 *
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property int $uses
 */
class MagicLink extends Model
{
    protected $guarded = [];

    /** Longitud del token en claro, en bytes de entropía. */
    private const BYTES = 32;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'uses' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Emite un enlace nuevo y devuelve [modelo, token en claro].
     *
     * @return array{0: self, 1: string}
     */
    public static function emitir(Event $event, string $email, ?Order $order = null): array
    {
        $token = Str::random(self::BYTES * 2);

        $link = self::create([
            'event_id' => $event->getKey(),
            'order_id' => $order?->getKey(),
            'email' => mb_strtolower(trim($email)),
            'token_hash' => self::hash($token),
            'expires_at' => now()->addMinutes(config('magic_links.ttl_minutes')),
            'uses' => 0,
            'created_ip' => Request::ip(),
        ]);

        return [$link, $token];
    }

    /** Busca un enlace utilizable a partir del token en claro. */
    public static function resolver(string $token): ?self
    {
        return self::query()
            ->where('token_hash', self::hash($token))
            ->utilizables()
            ->first();
    }

    /**
     * Busca el enlace sin exigir que siga vigente. Sirve para saber a que
     * evento pertenece un enlace vencido y ofrecerle al cliente el formulario
     * correcto para pedir uno nuevo, en vez de dejarlo en un callejon sin salida.
     */
    public static function porToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hash($token))->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUtilizables(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function estaVigente(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function registrarUso(): void
    {
        $this->forceFill([
            'uses' => $this->uses + 1,
            'last_used_at' => now(),
        ])->save();
    }

    public function revocar(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Hash determinista sin sal, para poder buscar por token. No se usa bcrypt
     * porque requeriría recorrer toda la tabla; el token tiene entropía
     * suficiente para que SHA-256 sea seguro aquí.
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
