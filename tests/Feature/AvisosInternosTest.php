<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\Rol;
use App\Mail\AvisoNuevaSolicitud;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Models\User;
use Database\Seeders\CuentasDePruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Avisos internos por correo: solo llegan a quien los encendió, está activo y
 * todavía tiene el permiso de lo que avisa.
 */
class AvisosInternosTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
    }

    private function datosDeSolicitud(array $extra = []): array
    {
        return [
            'first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@colegio.cl', 'position' => 'Directivo',
            'institution' => 'Colegio San José', 'phone' => '56912345678', 'marketing' => '1',
            ...$extra,
        ];
    }

    public function test_una_solicitud_nueva_avisa_solo_a_los_suscritos_activos(): void
    {
        $flor = $this->usuarioConRol(Rol::Coordinacion, ['notify_program_requests' => true]);
        $this->usuarioConRol(Rol::Coordinacion, ['notify_program_requests' => false]);
        $this->usuarioConRol(Rol::Coordinacion, ['notify_program_requests' => true, 'is_active' => false]);

        $this->post("/f/{$this->evento->slug}", $this->datosDeSolicitud())->assertOk();

        Mail::assertQueued(AvisoNuevaSolicitud::class, 1);
        Mail::assertQueued(AvisoNuevaSolicitud::class, fn (AvisoNuevaSolicitud $m): bool => $m->hasTo($flor->email)
            && str_contains($m->envelope()->subject, 'Nueva solicitud: Ana Pérez · Seminario 2026')
            && str_contains($m->render(), 'Colegio San José')
            && ! str_contains($m->render(), 'Mi perfil'));
    }

    public function test_quien_perdio_el_permiso_deja_de_recibir_el_aviso(): void
    {
        $this->usuarioConRol(Rol::Acreditacion, ['notify_program_requests' => true]);

        $this->post("/f/{$this->evento->slug}", $this->datosDeSolicitud())->assertOk();

        Mail::assertNotQueued(AvisoNuevaSolicitud::class);
    }

    public function test_un_honeypot_no_avisa_a_nadie(): void
    {
        $this->usuarioConRol(Rol::Coordinacion, ['notify_program_requests' => true]);

        $this->post("/f/{$this->evento->slug}", $this->datosDeSolicitud(['website' => 'http://spam.cl']))->assertOk();

        $this->assertSame(0, ProgramRequest::count());
        Mail::assertNotQueued(AvisoNuevaSolicitud::class);
    }

    public function test_las_cuentas_de_prueba_parten_con_los_avisos_de_su_rol(): void
    {
        $this->seed(CuentasDePruebaSeeder::class);

        $this->assertTrue(User::where('email', 'contabilidad@test.cl')->value('notify_payment_proofs'));
        $this->assertTrue(User::where('email', 'coordinacion@test.cl')->value('notify_program_requests'));
        $this->assertFalse(User::where('email', 'acreditacion@test.cl')->value('notify_payment_proofs'));
    }
}
