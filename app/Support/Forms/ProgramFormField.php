<?php

namespace App\Support\Forms;

use App\Support\ReglasDeContacto;

/**
 * Definición de un campo del formulario público de captación.
 *
 * Los campos base tienen columna propia en program_requests. Cualquier campo
 * adicional que se configure se guarda en la columna JSON `extra`, de modo que
 * agregar o quitar preguntas no requiere migración.
 */
class ProgramFormField
{
    /** Campos con columna propia en la tabla. El resto va a `extra`. */
    public const CAMPOS_BASE = [
        'first_name', 'last_name', 'email', 'position',
        'institution', 'phone', 'access_type_id',
    ];

    /** El correo es el único campo que nunca puede desactivarse. */
    public const OBLIGATORIO_SIEMPRE = 'email';

    /**
     * Cargos estandarizados. Lista cerrada porque el objetivo del campo es
     * segmentar después: con texto libre conviven "Director", "director/a" y
     * "DIRECTOR" y no se puede filtrar por nada.
     */
    public const CARGOS = [
        'Sostenedor/a',
        'Directivo',
        'Administrativo',
        'Docente',
        'Particular',
        self::CARGO_OTRO,
    ];

    /**
     * Positions of the people who attend. Only for the participants step and
     * the panel forms that edit a participant: whoever fills the public form or
     * buys is a different person and keeps CARGOS. Order set by the boss.
     */
    public const CARGOS_PARTICIPANTE = [
        'Sostenedor/a',
        'Director/a',
        'Jefe de UTP',
        'Inspector General',
        'Coordinador de Convivencia Escolar',
        'Coordinador PIE',
        'Coordinador nivel o especialidad',
        'Educadora de Párvulos',
        'Docente Enseñanza Básica',
        'Docente Enseñanza Media',
        'Docente Diferencial',
        'Asistente de aula',
        'Asistente de la Educación Profesional',
        'Asistente de la Educación Administrativo',
        'Asistente de la Educación de Servicios',
        'Profesional de la Educación',
        self::CARGO_OTRO,
    ];

    /**
     * Both lists, for filtering and exporting: a contact may come from either.
     *
     * @return array<int, string>
     */
    public static function todosLosCargos(): array
    {
        return array_values(array_unique([...self::CARGOS, ...self::CARGOS_PARTICIPANTE]));
    }

    /** Elegir esta opción abre un campo de texto que pasa a ser obligatorio. */
    public const CARGO_OTRO = 'Otro';

    /** Tipos de pregunta que se pueden elegir al armar el formulario. */
    public const TIPOS = [
        'text' => 'Texto corto',
        'textarea' => 'Texto largo',
        'email' => 'Correo',
        'tel' => 'Teléfono',
        'number' => 'Número',
        'select' => 'Lista de opciones',
    ];

    /** @param  array<int, string>  $options */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $enabled = true,
        public bool $required = false,
        public ?string $placeholder = null,
        public ?string $helpText = null,
        public array $options = [],
    ) {}

    /** @param  array<string, mixed>  $data  Definición guardada como JSON en el evento. */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            label: $data['label'] ?? $data['key'],
            type: $data['type'] ?? 'text',
            enabled: (bool) ($data['enabled'] ?? true),
            required: (bool) ($data['required'] ?? false),
            placeholder: $data['placeholder'] ?? null,
            helpText: $data['help_text'] ?? null,
            options: $data['options'] ?? [],
        );
    }

    /** @return array{key: string, label: string, type: string, enabled: bool, required: bool, placeholder: string|null, help_text: string|null, options: array<int, string>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'enabled' => $this->enabled,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'help_text' => $this->helpText,
            'options' => $this->options,
        ];
    }

    public function esCampoBase(): bool
    {
        return in_array($this->key, self::CAMPOS_BASE, true);
    }

    /**
     * Reglas de validación de este campo.
     *
     * Los campos base llevan reglas propias: lo que se pide en cada uno se sabe
     * de antemano y dejar pasar "aaa" o "123" arruina el dato justo donde
     * después se filtra y se le habla a la persona por su nombre.
     *
     * @return array<int, mixed>
     */
    public function reglas(): array
    {
        $compartidas = match ($this->key) {
            'first_name' => ReglasDeContacto::nombres($this->required),
            'last_name' => ReglasDeContacto::apellidos($this->required),
            'position' => ReglasDeContacto::cargo($this->required, $this->options ?: self::CARGOS),
            'email' => ReglasDeContacto::correo($this->required),
            'institution' => ReglasDeContacto::establecimiento($this->required),
            default => $this->type === 'tel' ? ReglasDeContacto::telefono($this->required) : null,
        };

        if ($compartidas !== null) {
            return $compartidas;
        }

        $reglas = [$this->required ? 'required' : 'nullable'];

        $reglas[] = match ($this->type) {
            'email' => 'email:rfc',
            'tel' => 'string',
            'number' => 'numeric',
            'select', 'radio' => 'string',
            'textarea' => 'string',
            default => 'string',
        };

        if (in_array($this->type, ['text', 'email', 'tel', 'select', 'radio'], true)) {
            $reglas[] = 'max:255';
        }

        if ($this->type === 'textarea') {
            $reglas[] = 'max:2000';
        }

        return [...$reglas, ...$this->reglasPropias()];
    }

    /** @return array<int, string> */
    private function reglasPropias(): array
    {
        return $this->type === 'text' ? ['min:2'] : [];
    }

    /**
     * Conjunto base que pide la empresa hoy. Un evento sin configuración
     * propia usa exactamente esto.
     *
     * @return array<int, self>
     */
    /**
     * Los campos con los que arranca un formulario nuevo.
     *
     * "Jornada de interés" no está a propósito: depende de que el evento tenga
     * accesos configurados, y no todos los eventos preguntan eso. Quien lo
     * necesite lo agrega como una pregunta más de tipo lista.
     *
     * Todos nacen obligatorios aunque en la base sean opcionales: la empresa
     * quiere la ficha completa, y el evento que necesite soltar un campo lo
     * desmarca sin migración ni riesgo para los registros ya guardados.
     *
     * @return array<int, self>
     */
    public static function porDefecto(): array
    {
        return [
            new self('first_name', 'Nombre', 'text', required: true),
            new self('last_name', 'Apellidos', 'text', required: true),
            new self('email', 'Correo electrónico', 'email', required: true),
            new self('phone', 'Teléfono', 'tel', required: true, placeholder: '56912345678'),
            new self('position', 'Cargo', 'select', required: true, options: self::CARGOS),
            new self('institution', 'Establecimiento', 'text', required: true),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function porDefectoComoArray(): array
    {
        return array_map(fn (self $f) => $f->toArray(), self::porDefecto());
    }
}
