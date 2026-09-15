<?php

namespace Tests\Feature;

use App\Support\ReglasDeContacto;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The shared rules stop junk without blocking real, unusual data.
 */
class ReglasDeContactoTest extends TestCase
{
    /** @param array<string, mixed> $datos */
    private function falla(array $datos): bool
    {
        return Validator::make(ReglasDeContacto::limpiar($datos), [
            'first_name' => ReglasDeContacto::nombres(),
            'last_name' => ReglasDeContacto::apellidos(),
            'position' => ReglasDeContacto::cargo(),
            'position_otro' => ReglasDeContacto::cargoOtro('position'),
            'email' => ReglasDeContacto::correo(),
        ])->fails();
    }

    /** @return array<string, mixed> */
    private function valido(): array
    {
        return ['first_name' => 'María José', 'last_name' => "Pérez-Cotapos O'Brien", 'position' => 'Directivo', 'email' => 'gabriel.gomez.correa@colegio.cl'];
    }

    public function test_acepta_datos_reales_aunque_sean_largos_o_raros(): void
    {
        $this->assertFalse($this->falla($this->valido()));
        $this->assertFalse($this->falla([...$this->valido(), 'position' => 'Otro', 'position_otro' => 'jefe de utp']));
    }

    public function test_rechaza_basura(): void
    {
        foreach ([
            ['first_name' => 'C4rress'],
            ['first_name' => '.'],
            ['last_name' => '-'],
            ['first_name' => 'Aaaa'],
            ['position' => 'Rector supremo'],
            ['position' => 'Otro', 'position_otro' => ''],
            ['position' => 'Otro', 'position_otro' => '123'],
            ['email' => 'a@b.cl, c@d.cl'],
            ['email' => 'sinarroba.cl'],
        ] as $cambio) {
            $this->assertTrue($this->falla([...$this->valido(), ...$cambio]), json_encode($cambio));
        }
    }

    public function test_normaliza_para_guardar(): void
    {
        $datos = ReglasDeContacto::normalizar(
            ['first_name' => 'juan  DE la cruz', 'email' => ' Juan@Colegio.CL ', 'position' => 'Otro', 'position_otro' => 'DiREctivo de utp'],
            ['first_name' => 'nombre', 'email' => 'correo', 'position' => 'cargo'],
        );

        $this->assertSame(['first_name' => 'Juan de la Cruz', 'email' => 'juan@colegio.cl', 'position' => 'Directivo de Utp'], $datos);
    }

    public function test_separa_un_cargo_guardado_para_editarlo(): void
    {
        $this->assertSame(['position' => 'Docente'], ReglasDeContacto::separarCargo('Docente'));
        $this->assertSame(['position' => 'Otro', 'position_otro' => 'Jefe de UTP'], ReglasDeContacto::separarCargo('Jefe de UTP'));
        $this->assertSame(['position' => null], ReglasDeContacto::separarCargo(null));
    }
}
