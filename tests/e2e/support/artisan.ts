import { execFileSync } from 'node:child_process';

/**
 * Puente mínimo hacia Artisan para leer estado real de la app durante el
 * recorrido de Playwright. Solo para jugar en local: nunca corre en CI.
 */
function tinker(codigo: string): string {
    return execFileSync('php', ['artisan', 'tinker', `--execute=${codigo}`], {
        cwd: process.cwd(),
        encoding: 'utf-8',
    });
}

export type OrdenDelConjunto = {
    id: number;
    numero: string;
    establecimiento: string;
};

export function ordenesDelConjunto(
    email: string,
    eventoSlug: string,
): OrdenDelConjunto[] {
    const php = `
        $evento = \\App\\Models\\Event::where('slug', '${eventoSlug}')->firstOrFail();
        $ordenes = \\App\\Models\\Order::where('event_id', $evento->id)
            ->where('responsible_email', '${email}')
            ->with('establishments')
            ->orderBy('id')
            ->get()
            ->map(fn ($o) => ['id' => $o->id, 'numero' => $o->number, 'establecimiento' => $o->establishments->first()?->name]);
        echo $ordenes->toJson();
    `.trim();

    return JSON.parse(tinker(php));
}

export function limpiarEventoE2e(): void {
    execFileSync('php', ['artisan', 'db:seed', '--class=E2eColegiosSeeder'], {
        cwd: process.cwd(),
    });

    // Cada corrida pide un enlace de acceso desde 127.0.0.1: sin esto, tras
    // unas pocas corridas locales el rate limit de magic links lo bloquea.
    execFileSync('php', ['artisan', 'cache:clear'], { cwd: process.cwd() });
}
