<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Models\Event;
use App\Models\Order;
use App\Models\ProgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La exportación saca datos personales del sistema: solo Administración, sin
 * duplicados y, para campañas, solo quien aceptó recibir información.
 */
class ExportarContactosTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_administracion_exporta(): void
    {
        foreach ([Rol::Coordinacion, Rol::Contabilidad, Rol::Acreditacion] as $rol) {
            $this->actingAs($this->usuarioConRol($rol))
                ->get(route('solicitudes.exportar'))
                ->assertForbidden();
            $this->get(route('solicitudes.exportar.cantidad'))->assertForbidden();
        }
    }

    public function test_exporta_sin_duplicados_filtra_consentimiento_y_audita(): void
    {
        $evento = Event::factory()->create(['name' => 'Seminario Ñuñoa']);

        // La misma persona pidió el programa y además quedó como responsable.
        ProgramRequest::factory()->for($evento)->create([
            'email' => 'ana@colegio.cl', 'first_name' => 'Ana', 'position' => 'Directivo',
            // Stored before the change, with "+": production data is not rewritten.
            'phone' => '+56912345678', 'marketing_consented_at' => now(),
        ]);
        Order::factory()->for($evento)->create([
            'responsible_email' => 'ANA@colegio.cl', 'responsible_name' => 'Ana', 'responsible_lastname' => 'María Peña', 'responsible_position' => 'Directivo',
            'responsible_phone' => null,
        ]);
        // Sin consentimiento: no sale en la lista para campañas.
        ProgramRequest::factory()->for($evento)->create(['email' => 'luis@colegio.cl', 'marketing_consented_at' => null]);

        $admin = $this->usuarioConRol(Rol::Administrador);

        $csv = $this->actingAs($admin)
            ->get(route('solicitudes.exportar', ['evento' => $evento->id, 'marketing' => 1]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(1, substr_count($csv, 'ana@colegio.cl'));
        $this->assertStringNotContainsString('luis@colegio.cl', $csv);
        $this->assertStringContainsString('Seminario Ñuñoa', $csv);
        // Gana el dato del responsable sobre el de la solicitud.
        $this->assertStringContainsString('ana@colegio.cl;Ana;"María Peña"', $csv);
        // The phone comes from the request even though the responsible row wins:
        // it was the field the boss saw missing. Without "+", Excel keeps it as text.
        $this->assertStringContainsString(';56912345678;', $csv);
        $this->assertStringNotContainsString('+56', $csv);

        $this->assertDatabaseHas('audit_logs', ['action' => 'contactos.exportados', 'user_id' => $admin->id]);

        // The live count matches the file.
        $this->get(route('solicitudes.exportar.cantidad', ['evento' => $evento->id, 'marketing' => 1]))
            ->assertOk()
            ->assertJson(['cantidad' => 1]);
    }
}
