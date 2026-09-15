<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function evento(): Event
    {
        return Event::create([
            'name' => 'Seminario',
            'slug' => 'seminario',
            'status' => EventStatus::Borrador,
        ]);
    }

    public function test_registra_el_cambio_de_estado_con_el_usuario_autenticado(): void
    {
        $user = User::factory()->create(['name' => 'Flor Contabilidad']);
        $this->actingAs($user);

        $evento = $this->evento();

        Auditor::registrar(
            sobre: $evento,
            accion: 'evento.publicado',
            estadoAnterior: 'draft',
            estadoNuevo: 'published',
            comentario: 'Publicado tras revisión',
        );

        $log = AuditLog::sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Flor Contabilidad', $log->actor_label);
        $this->assertSame('evento.publicado', $log->action);
        $this->assertSame('draft', $log->old_status);
        $this->assertSame('published', $log->new_status);
        $this->assertSame('Publicado tras revisión', $log->comment);
        $this->assertTrue($evento->is($log->auditable));
    }

    public function test_sin_usuario_autenticado_queda_como_sistema(): void
    {
        Auditor::registrar($this->evento(), 'reserva.vencida');

        $log = AuditLog::sole();

        $this->assertNull($log->user_id);
        $this->assertSame('Sistema', $log->actor_label);
    }

    public function test_permite_un_actor_externo_sin_usuario(): void
    {
        Auditor::registrar(
            sobre: $this->evento(),
            accion: 'comprobante.cargado',
            actorLabel: 'cliente@colegio.cl',
        );

        $this->assertSame('cliente@colegio.cl', AuditLog::sole()->actor_label);
    }

    public function test_guarda_propiedades_adicionales_como_json(): void
    {
        Auditor::registrar(
            sobre: $this->evento(),
            accion: 'pago.aprobado',
            propiedades: ['monto' => 450000, 'banco' => 'BancoEstado'],
        );

        $this->assertSame(['monto' => 450000, 'banco' => 'BancoEstado'], AuditLog::sole()->properties);
    }

    public function test_el_registro_de_auditoria_no_se_actualiza(): void
    {
        // La tabla no tiene updated_at: el historial solo se agrega, nunca se modifica.
        Auditor::registrar($this->evento(), 'evento.creado');

        $this->assertNull(AuditLog::sole()->updated_at ?? null);
        $this->assertArrayNotHasKey('updated_at', AuditLog::sole()->getAttributes());
    }
}
