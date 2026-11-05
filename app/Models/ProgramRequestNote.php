<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Anotación con fecha sobre una solicitud ("llamé, no contestó"). No se edita
 * ni se borra: es el registro de lo que se hizo.
 *
 * @property int $id
 * @property int $program_request_id
 * @property int|null $user_id
 * @property string $body
 * @property string|null $kind null = nota de seguimiento, `declined` o `resumed`
 * @property Carbon $created_at
 */
class ProgramRequestNote extends Model
{
    public const NO_PARTICIPARA = 'declined';

    public const VUELVE_A_SEGUIMIENTO = 'resumed';

    protected $guarded = [];

    public function etiquetaDelTipo(): ?string
    {
        return match ($this->kind) {
            self::NO_PARTICIPARA => 'No participará',
            self::VUELVE_A_SEGUIMIENTO => 'Vuelve a seguimiento',
            default => null,
        };
    }

    /** @return BelongsTo<ProgramRequest, $this> */
    public function programRequest(): BelongsTo
    {
        return $this->belongsTo(ProgramRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
