<?php

use App\Support\WebsAutorizadas;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Carries the sites from EMBED_ALLOWED_ORIGINS into Configuración → Webs,
     * so a deploy does not silently stop the forms already embedded.
     */
    public function up(): void
    {
        $desdeEnv = collect(explode(',', (string) config('embed.allowed_origins')))
            ->map(fn (string $origen): ?string => WebsAutorizadas::normalizar($origen))
            ->filter()
            ->all();

        if ($desdeEnv !== []) {
            WebsAutorizadas::guardar([...WebsAutorizadas::lista(), ...$desdeEnv]);
        }
    }

    public function down(): void {}
};
