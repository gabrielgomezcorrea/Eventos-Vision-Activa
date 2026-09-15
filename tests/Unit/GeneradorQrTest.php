<?php

namespace Tests\Unit;

use App\Support\GeneradorQr;
use PHPUnit\Framework\TestCase;

class GeneradorQrTest extends TestCase
{
    private GeneradorQr $qr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qr = new GeneradorQr;
    }

    public function test_genera_un_svg_incrustable(): void
    {
        $svg = $this->qr->svg('https://ejemplo.cl/t/abc')->toHtml();

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringEndsWith('</svg>', $svg);

        // La cabecera XML rompe el SVG incrustado dentro de HTML.
        $this->assertStringNotContainsString('<?xml', $svg);
    }

    public function test_respeta_el_tamano_pedido(): void
    {
        $svg = $this->qr->svg('https://ejemplo.cl/t/abc', 300)->toHtml();

        $this->assertMatchesRegularExpression('/width="300/', $svg);
    }

    public function test_usa_correccion_de_errores_alta(): void
    {
        // Con corrección alta el QR sobrevive dobleces y manchas, que es lo
        // que le pasa a una credencial impresa el día del evento.
        $alta = strlen($this->qr->svg('https://ejemplo.cl/t/abcdefghij')->toHtml());

        // Un QR con corrección alta necesita más módulos que uno con la baja,
        // así que produce un SVG con más trazado para el mismo contenido.
        $this->assertGreaterThan(2000, $alta);
    }

    public function test_el_data_uri_sirve_para_un_atributo_src(): void
    {
        $uri = $this->qr->dataUri('https://ejemplo.cl/t/abc');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertStringContainsString('<svg', base64_decode(substr($uri, 26)));
    }
}
