<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\HtmlString;

/**
 * Dibuja códigos QR como SVG en línea.
 *
 * SVG y no PNG a propósito: se imprime nítido a cualquier tamaño, no necesita
 * extensiones de imagen en el servidor y no deja archivos que respaldar.
 */
class GeneradorQr
{
    /**
     * Nivel de corrección de errores alto: el QR sigue leyéndose aunque la
     * hoja esté doblada, arrugada o algo manchada. En un evento presencial eso
     * pasa todo el tiempo.
     */
    private static function correccion(): ErrorCorrectionLevel
    {
        return ErrorCorrectionLevel::H();
    }

    /** Margen mínimo obligatorio del estándar para que un lector enganche. */
    private const MARGEN = 4;

    public function svg(string $contenido, int $tamano = 220): HtmlString
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($tamano, self::MARGEN),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($contenido, Encoder::DEFAULT_BYTE_MODE_ECODING, self::correccion());

        // El writer emite una cabecera XML que no sirve incrustada en HTML.
        $svg = preg_replace('/<\?xml[^?]*\?>\s*/', '', $svg) ?? $svg;

        return new HtmlString(trim($svg));
    }

    /** SVG como data URI, para incrustarlo en un correo o un atributo src. */
    public function dataUri(string $contenido, int $tamano = 220): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($contenido, $tamano)->toHtml());
    }
}
