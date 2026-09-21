<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ajustes globales en clave/valor.
 *
 * Se cachean porque se leen en cada correo que sale y casi nunca cambian.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    private const CACHE = 'settings.todos';

    /** @return array<string, string|null> */
    public static function todos(): array
    {
        return Cache::rememberForever(self::CACHE, fn (): array => self::query()->pluck('value', 'key')->all());
    }

    public static function valor(string $clave): ?string
    {
        return self::todos()[$clave] ?? null;
    }

    /** @param  array<string, string|null>  $valores */
    public static function guardar(array $valores): void
    {
        foreach ($valores as $clave => $valor) {
            self::updateOrCreate(['key' => $clave], ['value' => $valor ?: null]);
        }

        Cache::forget(self::CACHE);
    }
}
