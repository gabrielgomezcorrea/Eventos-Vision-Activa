<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * The boss's enrollment report: an Excel with one sheet per enrollment and one
 * per participant. It hands out personal data and amounts, so it needs the
 * same permission as the enrollment list.
 */
class InformeDeInscritosTest extends TestCase
{
    use RefreshDatabase;

    public function test_descarga_un_excel_con_inscripciones_y_una_fila_por_participante(): void
    {
        $evento = Event::factory()->conJornadasYAccesos(50)->create();
        $acceso = $evento->accessTypes()->first();
        $orden = Order::factory()->create(['event_id' => $evento->id, 'number' => 'SI-2026-0001']);
        $colegio = Establishment::factory()->create(['name' => 'Colegio San José']);
        $orden->establishments()->attach($colegio);

        foreach (['Ana', 'Beto'] as $nombre) {
            Participant::factory()->create([
                'order_id' => $orden->id,
                'establishment_id' => $colegio->id,
                'access_type_id' => $acceso->id,
                'first_name' => $nombre,
                'email' => mb_strtolower($nombre).'@colegio.cl',
            ]);
        }

        // An empty draft (link opened, nothing written) is not an enrollment.
        Order::factory()->create([
            'event_id' => $evento->id, 'number' => 'SI-2026-0002',
            'payer_entity_id' => null, 'responsible_name' => '',
        ]);

        $respuesta = $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('inscripciones.informe', ['evento' => $evento->id]));

        $respuesta->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $respuesta->headers->get('content-disposition'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->getFile()->getPathname()));

        $libro = (string) $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('name="Inscripciones"', $libro);
        $this->assertStringContainsString('name="Participantes"', $libro);

        $inscripciones = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('SI-2026-0001', $inscripciones);
        $this->assertStringNotContainsString('SI-2026-0002', $inscripciones);

        // Both participants share the folio and still get a row each.
        $participantes = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $this->assertSame(3, substr_count($participantes, '<row '));
        $this->assertStringContainsString('ana@colegio.cl', $participantes);
        $this->assertStringContainsString('beto@colegio.cl', $participantes);
        $zip->close();
    }

    public function test_sin_permiso_para_ver_inscripciones_no_descarga(): void
    {
        $this->actingAs($this->usuarioConRol(Rol::Acreditacion))
            ->get(route('inscripciones.informe'))
            ->assertForbidden();
    }
}
