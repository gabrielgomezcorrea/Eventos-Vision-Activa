<?php

namespace Tests\Unit;

use App\Enums\ReservationDurationUnit;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReservationDurationUnitTest extends TestCase
{
    public function test_horas_suma_horas(): void
    {
        $desde = CarbonImmutable::parse('2026-09-02 10:00:00');

        $this->assertSame(
            '2026-09-02 16:00:00',
            ReservationDurationUnit::Horas->vencimientoDesde($desde, 6)->format('Y-m-d H:i:s'),
        );
    }

    public function test_dias_corridos_no_salta_fin_de_semana(): void
    {
        // Viernes 4 de septiembre + 3 días corridos = lunes 7.
        $viernes = CarbonImmutable::parse('2026-09-04 10:00:00');

        $this->assertSame(
            '2026-09-07',
            ReservationDurationUnit::DiasCorridos->vencimientoDesde($viernes, 3)->format('Y-m-d'),
        );
    }

    public function test_dias_habiles_salta_fin_de_semana(): void
    {
        // Viernes 4 de septiembre + 3 días hábiles = miércoles 9,
        // porque sábado y domingo no cuentan.
        $viernes = CarbonImmutable::parse('2026-09-04 10:00:00');

        $this->assertSame(
            '2026-09-09',
            ReservationDurationUnit::DiasHabiles->vencimientoDesde($viernes, 3)->format('Y-m-d'),
        );
    }

    public function test_dias_habiles_conserva_la_hora(): void
    {
        $desde = CarbonImmutable::parse('2026-09-04 15:30:00');

        $this->assertSame(
            '15:30',
            ReservationDurationUnit::DiasHabiles->vencimientoDesde($desde, 1)->format('H:i'),
        );
    }
}
