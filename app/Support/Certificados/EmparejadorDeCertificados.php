<?php

namespace App\Support\Certificados;

use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Suggests which participant a certificate belongs to, by name.
 *
 * Candidates are the same people as the attendance report (paid enrollments,
 * guests and anyone accredited). A certificate may print more given names than
 * the person typed ("Ingrid Marlene Itha" vs "Ingrid Marlene"), so the score is
 * how many of the participant's words appear on the certificate, and every
 * surname must be there: two "Paola Andrea" are told apart by their surnames.
 */
class EmparejadorDeCertificados
{
    /** Assigned without asking. */
    public const SEGURO = 90;

    /** Shown as a suggestion that someone must confirm. */
    public const SUGERIDO = 60;

    private const PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'y', 'da', 'do', 'dos', 'van', 'von', 'di', 'el'];

    /** @return Builder<Participant> */
    public function consultaDeCandidatos(Event $evento): Builder
    {
        return Participant::query()
            ->where('status', '!=', ParticipantStatus::Reemplazado->value)
            ->whereHas('order', fn (Builder $q) => $q->where('event_id', $evento->id))
            ->where(fn (Builder $q) => $q
                ->whereHas('order', fn (Builder $o) => $o->where('payment_status', PaymentStatus::Aprobado->value))
                ->orWhereHas('accreditation'));
    }

    /** @return Collection<int, Participant> */
    public function candidatos(Event $evento): Collection
    {
        return $this->consultaDeCandidatos($evento)
            ->with(['establishment', 'order.establishments'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @param  Collection<int, Participant>  $candidatos
     * @return array{participante: Participant|null, puntaje: int, seguro: bool}
     */
    public function emparejar(?string $nombre, Collection $candidatos): array
    {
        if ($nombre === null || $candidatos->isEmpty()) {
            return ['participante' => null, 'puntaje' => 0, 'seguro' => false];
        }

        $puntajes = $candidatos
            ->map(fn (Participant $p): array => ['participante' => $p, 'puntaje' => $this->puntaje($nombre, $p)])
            ->sortByDesc('puntaje')
            ->values();

        $mejor = $puntajes->first();

        if ($mejor['puntaje'] < self::SUGERIDO) {
            return ['participante' => null, 'puntaje' => $mejor['puntaje'], 'seguro' => false];
        }

        // Two people who both fit (same name in two schools): a person decides.
        $empatados = $puntajes->filter(fn (array $p): bool => $p['puntaje'] >= self::SEGURO)->count();

        return [
            'participante' => $mejor['participante'],
            'puntaje' => $mejor['puntaje'],
            'seguro' => $mejor['puntaje'] >= self::SEGURO && $empatados === 1,
        ];
    }

    public function puntaje(string $nombre, Participant $participante): int
    {
        $enCertificado = $this->palabras($nombre);
        $nombres = $this->palabras((string) $participante->first_name);
        $apellidos = $this->palabras((string) $participante->last_name);
        $todas = [...$nombres, ...$apellidos];

        if ($todas === [] || count($enCertificado) < 2) {
            return 0;
        }

        $encontradas = count(array_filter($todas, fn (string $p): bool => $this->aparece($p, $enCertificado)));
        $puntaje = (int) round(100 * $encontradas / count($todas));

        $faltaApellido = array_filter($apellidos, fn (string $p): bool => ! $this->aparece($p, $enCertificado)) !== [];

        return $faltaApellido ? min($puntaje, self::SUGERIDO - 1) : $puntaje;
    }

    /** @param array<int, string> $palabras */
    private function aparece(string $palabra, array $palabras): bool
    {
        foreach ($palabras as $otra) {
            // One typo is forgiven in longer words: "Indrid" is "Ingrid".
            if ($otra === $palabra || (mb_strlen($palabra) >= 5 && levenshtein($otra, $palabra) <= 1)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function palabras(string $texto): array
    {
        $plano = preg_replace('/[^a-z ]+/', ' ', Str::lower(Str::ascii($texto))) ?? '';

        return array_values(array_filter(
            explode(' ', $plano),
            fn (string $p): bool => $p !== '' && ! in_array($p, self::PARTICULAS, true),
        ));
    }
}
