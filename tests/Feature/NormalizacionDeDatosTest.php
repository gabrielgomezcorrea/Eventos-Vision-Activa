<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Support\Texto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Lo que la gente escribe se corrige antes de guardarse.
 *
 * Importa porque es el dato con el que después se segmenta y se le habla a la
 * persona por su nombre: con "JUAN carlos" y "liceo a-12" en la base, el mismo
 * colegio aparece escrito de tres formas y ningún filtro sirve.
 */
class NormalizacionDeDatosTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->event = Event::create([
            'name' => 'Seminario 2026',
            'slug' => 'seminario-2026',
            'status' => EventStatus::Publicado,
        ]);
    }

    public function test_capitaliza_respetando_particulas_y_apellidos_compuestos(): void
    {
        $this->assertSame('Juan Carlos', Texto::capitalizar('JUAN carlos'));
        $this->assertSame('María José', Texto::capitalizar('  maría   josé  '));
        $this->assertSame('Juan de la Cruz', Texto::capitalizar('JUAN DE LA CRUZ'));
        $this->assertSame("O'Higgins", Texto::capitalizar("o'higgins"));
        $this->assertSame('Mac-Iver', Texto::capitalizar('MAC-IVER'));
        $this->assertSame('De la Fuente', Texto::capitalizar('de la fuente'));
    }

    public function test_el_telefono_queda_en_un_solo_formato(): void
    {
        foreach (['+56 9 1234 5678', '56912345678', '912345678', '9 1234-5678', '09 1234 5678'] as $escrito) {
            $this->assertSame('+56912345678', Texto::telefono($escrito), "falló con: {$escrito}");
        }

        $this->assertNull(Texto::telefono('222345678'), 'un fijo no es un móvil');
        $this->assertNull(Texto::telefono('123'));
    }

    public function test_la_solicitud_se_guarda_normalizada(): void
    {
        $this->post("/f/{$this->event->slug}", [
            'first_name' => 'jUAN   carlos',
            'last_name' => 'de la CRUZ',
            'email' => '  JUAN@Colegio.CL ',
            'phone' => '9 1234 5678',
            'position' => 'Docente',
            'institution' => 'liceo   bicentenario a-12',
        ])->assertOk();

        $solicitud = ProgramRequest::sole();

        $this->assertSame('Juan Carlos', $solicitud->first_name);
        $this->assertSame('De la Cruz', $solicitud->last_name);
        $this->assertSame('juan@colegio.cl', $solicitud->email);
        $this->assertSame('+56912345678', $solicitud->phone);
        $this->assertSame('Liceo Bicentenario A-12', $solicitud->institution);
    }

    public function test_rechaza_lo_que_no_es_un_nombre(): void
    {
        $base = [
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@colegio.cl',
            'phone' => '+56912345678',
            'position' => 'Docente',
            'institution' => 'Liceo A-12',
        ];

        // Números en el nombre, nombre de una letra y el apellido metido en el
        // campo del nombre: los tres casos que ensucian la base.
        foreach ([
            ['first_name' => 'Juan123'],
            ['first_name' => 'a'],
            ['first_name' => 'Juan Carlos Pérez Soto'],
            ['phone' => '223456789'],
        ] as $malo) {
            $this->post("/f/{$this->event->slug}", [...$base, ...$malo])->assertOk();
        }

        $this->assertSame(0, ProgramRequest::count());
    }
}
