<?php

namespace Tests\Unit;

use App\Support\Rut;
use PHPUnit\Framework\TestCase;

class RutTest extends TestCase
{
    public function test_valida_ruts_correctos(): void
    {
        foreach (['11.111.111-1', '12345678-5', '5.126.663-3', '76.086.428-5', '12.345.670-K'] as $rut) {
            $this->assertTrue(Rut::esValido($rut), "{$rut} deberia ser valido");
        }
    }

    public function test_rechaza_digito_verificador_incorrecto(): void
    {
        foreach (['11.111.111-2', '12345678-9', '76.086.428-1'] as $rut) {
            $this->assertFalse(Rut::esValido($rut), "{$rut} no deberia ser valido");
        }
    }

    public function test_rechaza_ruts_demasiado_cortos(): void
    {
        $this->assertFalse(Rut::esValido('1-9'));
        $this->assertFalse(Rut::esValido('123-6'));
    }

    public function test_un_rut_vacio_no_es_valido_pero_tampoco_falla(): void
    {
        $this->assertFalse(Rut::esValido(null));
        $this->assertFalse(Rut::esValido(''));
        $this->assertNull(Rut::normalizar(null));
        $this->assertNull(Rut::normalizar('   '));
    }

    public function test_normaliza_a_una_sola_forma(): void
    {
        // La acreditación busca por RUT: si cada persona lo escribe distinto,
        // la búsqueda falla. Por eso se guarda siempre igual.
        foreach (['76.086.428-5', '76086428-5', '760864285', ' 76.086.428 - 5 '] as $entrada) {
            $this->assertSame('76086428-5', Rut::normalizar($entrada));
        }
    }

    public function test_normaliza_la_k_a_mayuscula(): void
    {
        $this->assertSame('12345670-K', Rut::normalizar('12.345.670-k'));
    }

    public function test_calcula_el_digito_verificador(): void
    {
        $this->assertSame('5', Rut::digitoVerificador('76086428'));
        $this->assertSame('K', Rut::digitoVerificador('12345670'));
        $this->assertSame('1', Rut::digitoVerificador('11111111'));
    }
}
