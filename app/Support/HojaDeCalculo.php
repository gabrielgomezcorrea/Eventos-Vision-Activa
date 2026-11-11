<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Writes a minimal .xlsx with several sheets, no library needed: an xlsx is a
 * zip of a few XML files. CSV cannot hold more than one sheet, and the report
 * is read in Excel by people who expect tabs, a bold header and filters.
 */
class HojaDeCalculo
{
    /**
     * @param  array<string, array<int, array<int, string|int|null>>>  $hojas  name => rows (first row is the header)
     * @return string path of a temporary file; the caller deletes it after sending
     */
    public static function xlsx(array $hojas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($ruta === false || $zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear la planilla.');
        }

        $nombres = array_keys($hojas);

        $zip->addFromString('[Content_Types].xml', self::tiposDeContenido(count($nombres)));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', self::libro($nombres));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::relacionesDelLibro(count($nombres)));
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');

        foreach (array_values($hojas) as $i => $filas) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::hoja($filas));
        }

        $zip->close();

        return $ruta;
    }

    private static function tiposDeContenido(int $cantidad): string
    {
        $hojas = '';

        for ($i = 1; $i <= $cantidad; $i++) {
            $hojas .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$hojas
            .'</Types>';
    }

    /** @param  array<int, string>  $nombres */
    private static function libro(array $nombres): string
    {
        $hojas = '';

        foreach ($nombres as $i => $nombre) {
            // Excel caps sheet names at 31 characters.
            $hojas .= '<sheet name="'.self::xml(mb_substr($nombre, 0, 31)).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$hojas.'</sheets></workbook>';
    }

    private static function relacionesDelLibro(int $cantidad): string
    {
        $relaciones = '';

        for ($i = 1; $i <= $cantidad; $i++) {
            $relaciones .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        $relaciones .= '<Relationship Id="rId'.($cantidad + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relaciones.'</Relationships>';
    }

    /** @param  array<int, array<int, string|int|null>>  $filas */
    private static function hoja(array $filas): string
    {
        $columnas = max(1, count($filas[0] ?? []));
        $anchos = array_fill(0, $columnas, 10);
        $cuerpo = '';

        foreach (array_values($filas) as $f => $fila) {
            $celdas = '';

            foreach (array_values($fila) as $c => $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }

                $ref = self::columna($c).($f + 1);
                $anchos[$c] = min(50, max($anchos[$c] ?? 10, mb_strlen((string) $valor) + 2));

                $celdas .= is_int($valor)
                    ? '<c r="'.$ref.'" s="2"><v>'.$valor.'</v></c>'
                    : '<c r="'.$ref.'" t="inlineStr"'.($f === 0 ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::xml((string) $valor).'</t></is></c>';
            }

            $cuerpo .= '<row r="'.($f + 1).'">'.$celdas.'</row>';
        }

        $cols = '';

        foreach ($anchos as $c => $ancho) {
            $cols .= '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.$ancho.'" customWidth="1"/>';
        }

        $ultima = self::columna($columnas - 1).max(1, count($filas));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // Header stays visible while scrolling.
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.$cuerpo.'</sheetData>'
            .'<autoFilter ref="A1:'.$ultima.'"/>'
            .'</worksheet>';
    }

    private static function columna(int $indice): string
    {
        $letras = '';

        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26).$letras;
        }

        return $letras;
    }

    /** Escapes text and drops control characters that make Excel reject the file. */
    private static function xml(string $texto): string
    {
        return htmlspecialchars(
            (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto),
            ENT_XML1 | ENT_QUOTES,
            'UTF-8',
        );
    }
}
