<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Credencial de acceso de un participante.
 *
 * El QR no lleva datos personales: contiene una URL con un identificador
 * opaco. El token se guarda cifrado para poder volver a dibujar el QR, y su
 * hash aparte con índice único para resolver un escaneo en una sola consulta.
 *
 * @property Carbon|null $issued_at
 * @property Carbon|null $emailed_at
 * @property Carbon|null $revoked_at
 */
class Ticket extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Alfabeto del código de respaldo, sin caracteres que se confundan al
     * dictarlos o leerlos: 0/O, 1/I/L, 5/S, 8/B.
     */
    public const ALFABETO = 'ACDEFGHJKMNPQRTUVWXY234679';

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return HasOne<Accreditation, $this> */
    public function accreditation(): HasOne
    {
        return $this->hasOne(Accreditation::class);
    }

    public function yaFueAcreditado(): bool
    {
        return $this->accreditation()->exists();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** Resuelve un ticket a partir del token escaneado. */
    public static function resolver(string $token): ?self
    {
        return self::query()->where('token_hash', self::hash($token))->first();
    }

    public static function generarToken(): string
    {
        return Str::random(48);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Código de respaldo con formato XXXX-XXXX. */
    public static function generarCodigo(): string
    {
        $letras = str_split(self::ALFABETO);
        $codigo = '';

        for ($i = 0; $i < 8; $i++) {
            $codigo .= $letras[random_int(0, count($letras) - 1)];
        }

        return substr($codigo, 0, 4).'-'.substr($codigo, 4);
    }

    /** Normaliza lo que un operador escribe a mano en la búsqueda. */
    public static function normalizarCodigo(string $codigo): string
    {
        $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? '');

        return strlen($limpio) === 8
            ? substr($limpio, 0, 4).'-'.substr($limpio, 4)
            : $limpio;
    }

    public function estaVigente(): bool
    {
        return $this->revoked_at === null;
    }

    public function url(): string
    {
        return route('ticket.mostrar', ['token' => $this->token]);
    }

    public function revocar(string $motivo): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $motivo,
        ])->save();
    }
}
