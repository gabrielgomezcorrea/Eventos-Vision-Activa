<?php

namespace Database\Faker;

use App\Support\Rut;
use Faker\Provider\Base;

/**
 * Datos chilenos para los seeders y las pruebas.
 *
 * Faker no trae locale es_CL, y varias cosas de este dominio son locales: el
 * RUT con dígito verificador, el RBD de los establecimientos y los nombres de
 * colegios y comunas.
 */
class ChileProvider extends Base
{
    /**
     * Acceso tipado desde factories y seeders. Los métodos que `addProvider` agrega
     * a `fake()` existen solo en ejecución y el análisis estático no los ve.
     */
    public static function fake(): self
    {
        return new self(fake());
    }

    private const COMUNAS = [
        'Providencia', 'Ñuñoa', 'Las Condes', 'Maipú', 'La Florida', 'Puente Alto',
        'Santiago', 'Recoleta', 'Peñalolén', 'San Miguel', 'Quilicura', 'Valparaíso',
        'Viña del Mar', 'Concepción', 'Talcahuano', 'Temuco', 'Antofagasta', 'La Serena',
        'Rancagua', 'Talca', 'Chillán', 'Puerto Montt', 'Osorno', 'Iquique', 'Arica',
    ];

    private const TIPOS_ESTABLECIMIENTO = [
        'Colegio', 'Liceo', 'Escuela', 'Instituto', 'Complejo Educacional',
        'Liceo Bicentenario', 'Centro Educacional',
    ];

    private const NOMBRES_ESTABLECIMIENTO = [
        'San José', 'Santa María', 'Los Andes', 'Bicentenario', 'República de Chile',
        'Gabriela Mistral', 'Pablo Neruda', 'Andrés Bello', 'Manuel Rodríguez',
        'Arturo Prat', 'Diego Portales', 'Bernardo O\'Higgins', 'Las Américas',
        'Padre Hurtado', 'Violeta Parra', 'Claudio Arrau', 'Alonso de Ercilla',
    ];

    private const TIPOS_PAGADOR = [
        'Corporación Municipal de Educación de %s',
        'Fundación Educacional %s',
        'Sostenedor Educacional %s SpA',
        'Ilustre Municipalidad de %s',
        'Servicio Local de Educación %s',
    ];

    private const CARGOS = [
        'Director', 'Directora', 'Jefe de UTP', 'Jefa de UTP', 'Coordinador Académico',
        'Coordinadora Académica', 'Inspector General', 'Inspectora General',
        'Encargado de Convivencia', 'Encargada de Convivencia', 'Profesor', 'Profesora',
        'Educadora de Párvulos', 'Psicopedagoga', 'Orientador', 'Sostenedor',
    ];

    /** RUT válido con su dígito verificador correcto. */
    public function rutChileno(int $min = 5000000, int $max = 25000000): string
    {
        $cuerpo = (string) $this->generator->numberBetween($min, $max);

        return $cuerpo.'-'.Rut::digitoVerificador($cuerpo);
    }

    /** RUT de empresa: el rango alto lo distingue de una persona. */
    public function rutEmpresa(): string
    {
        return $this->rutChileno(60000000, 79000000);
    }

    /** Rol Base de Datos del establecimiento. */
    public function rbd(): string
    {
        return (string) $this->generator->numberBetween(1000, 40000);
    }

    public function comuna(): string
    {
        return static::randomElement(self::COMUNAS);
    }

    public function nombreEstablecimiento(): string
    {
        return static::randomElement(self::TIPOS_ESTABLECIMIENTO).' '
            .static::randomElement(self::NOMBRES_ESTABLECIMIENTO);
    }

    public function nombreEntidadPagadora(): string
    {
        return sprintf(static::randomElement(self::TIPOS_PAGADOR), $this->comuna());
    }

    public function cargoEducacional(): string
    {
        return static::randomElement(self::CARGOS);
    }

    /** Celular chileno en formato 569XXXXXXXX. */
    public function telefonoChileno(): string
    {
        return '569'.$this->generator->numerify('########');
    }
}
