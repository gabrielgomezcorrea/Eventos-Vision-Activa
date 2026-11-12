<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Console\Command;

/** Lists what an agent needs to know about an event before building an import. */
class VerEventoParaImportar extends Command
{
    protected $signature = 'inscripciones:evento {slug? : Event slug; without it, lists published events}';

    protected $description = 'Prints published events, or one event with accesses, positions, regions and codes, as JSON';

    public function handle(): int
    {
        $slug = $this->argument('slug');

        if ($slug === null) {
            $this->emitir(Event::query()->publicados()->orderBy('starts_on')->get()
                ->map(fn (Event $e): array => ['slug' => $e->slug, 'name' => $e->name, 'starts_on' => $e->starts_on?->toDateString()])
                ->all());

            return self::SUCCESS;
        }

        $evento = Event::query()->where('slug', $slug)->first();

        if ($evento === null) {
            $this->emitir(['error' => "No existe el evento «{$slug}»."]);

            return self::FAILURE;
        }

        $ordenVacia = new Order(['event_id' => $evento->getKey(), 'responsible_email' => '']);

        $this->emitir([
            'slug' => $evento->slug,
            'name' => $evento->name,
            'status' => $evento->status->value,
            'accesses' => $evento->accessTypes()->where('is_active', true)->orderBy('position')->get()
                ->map(fn ($a): array => ['name' => $a->name, 'price' => (int) $a->price, 'current_price' => $a->precioVigente()])
                ->all(),
            'positions' => $evento->cargosDeParticipante(),
            'regions' => array_keys(config('chile')),
            'codes' => $evento->discountCodes()->orderBy('id')->get()
                ->map(function ($c) use ($ordenVacia): array {
                    $motivo = $c->impedimentoPara($ordenVacia);

                    return [
                        'code' => $c->code,
                        'type' => $c->type->value,
                        'expires_on' => $c->expires_at?->toDateString(),
                        'usable' => $motivo === null,
                        'reason' => $motivo,
                    ];
                })->all(),
        ]);

        return self::SUCCESS;
    }

    private function emitir(array $datos): void
    {
        $this->line(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
