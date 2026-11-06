<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\Rol;
use App\Mail\ProgramaDelEvento;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\ProgramRequest;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Eventos en el panel: quién puede tocarlos y lo que protege cupos y dinero.
 */
class PanelEventosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function usuario(Rol $rol): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($rol->value);

        return $usuario->fresh();
    }

    /** @param  array<string, mixed>  $atributos */
    private function evento(array $atributos = []): Event
    {
        return Event::create([
            'name' => 'Seminario 2026',
            'slug' => 'seminario-2026',
            ...$atributos,
        ]);
    }

    private function jornada(Event $evento, string $nombre, int $posicion, int $tomados = 0): EventSession
    {
        return $evento->sessions()->create([
            'name' => $nombre,
            'position' => $posicion,
            'capacity' => 100,
            'reserved_seats' => $tomados,
        ]);
    }

    public function test_las_pantallas_de_eventos_cargan(): void
    {
        $evento = $this->evento();
        $evento->sessions()->create(['name' => 'Jornada 1', 'position' => 1, 'capacity' => 300]);
        $evento->accessTypes()->create(['name' => 'Ambas jornadas', 'position' => 1, 'price' => 150000]);

        $this->actingAs($this->usuario(Rol::Administrador));

        $this->get(route('dashboard'))->assertOk();
        // Opens on the published ones; a new event is a draft until published.
        $this->get(route('eventos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('eventos/index')->has('eventos.data', 0)->where('estados.0.cantidad', 1));
        $this->get(route('eventos.index', ['estado' => 'draft']))
            ->assertInertia(fn (Assert $page) => $page->has('eventos.data', 1)->where('eventos.data.0.inscritos', 0));
        $this->get(route('eventos.show', $evento))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('eventos/show')
                ->where('jornadas.0', fn (string $texto) => str_contains($texto, '300 cupos, quedan 300'))
                ->where('accesos.0', fn (string $texto) => str_contains($texto, 'Ambas jornadas — $150.000')));
        $this->get(route('eventos.cupos', $evento))->assertOk();
        $this->get(route('eventos.formulario', $evento))->assertOk();
    }

    public function test_crear_un_evento_calcula_el_identificador_y_nace_en_borrador(): void
    {
        $this->actingAs($this->usuario(Rol::Coordinacion));

        $datos = ['name' => 'Seminario Ñandú: 2ª versión', 'modality' => 'presencial', 'starts_on' => '2026-11-05'];

        $this->post(route('eventos.store'), $datos)->assertRedirect();
        $this->post(route('eventos.store'), $datos)->assertRedirect();

        $this->assertSame(
            ['seminario-nandu-2a-version', 'seminario-nandu-2a-version-2'],
            Event::orderBy('id')->pluck('slug')->all(),
        );
        $this->assertSame(EventStatus::Borrador, Event::firstOrFail()->status);
    }

    public function test_contabilidad_ve_eventos_pero_no_los_modifica(): void
    {
        $evento = $this->evento();
        $jornada = $this->jornada($evento, 'Jornada 1', 1);

        $this->actingAs($this->usuario(Rol::Contabilidad));

        // Contabilidad no configura eventos: no le aparece en el menú y
        // tampoco entra escribiendo la URL.
        $this->get(route('eventos.index'))->assertForbidden();
        $this->get(route('eventos.show', $evento))->assertForbidden();

        $this->post(route('eventos.store'), ['name' => 'Otro', 'modality' => 'presencial', 'starts_on' => '2026-11-05'])->assertForbidden();
        $this->patch(route('eventos.update', $evento), ['name' => 'Otro nombre'])->assertForbidden();
        $this->patch(route('eventos.estado', $evento), ['status' => 'closed'])->assertForbidden();
        $this->delete(route('eventos.destroy', $evento))->assertForbidden();
        $this->get(route('eventos.cupos', $evento))->assertForbidden();
        $this->get(route('eventos.formulario', $evento))->assertForbidden();
        $this->post(route('eventos.jornadas.store', $evento), ['name' => 'Jornada 2'])->assertForbidden();
        $this->delete(route('eventos.jornadas.destroy', [$evento, $jornada]))->assertForbidden();

        $this->assertSame('Seminario 2026', $evento->fresh()->name);
        $this->assertNotNull($jornada->fresh());
    }

    public function test_acreditacion_no_entra_a_eventos(): void
    {
        $this->actingAs($this->usuario(Rol::Acreditacion));

        $this->get(route('eventos.index'))->assertForbidden();
        $this->get(route('eventos.show', $this->evento()))->assertForbidden();
    }

    public function test_no_se_publica_un_evento_incompleto(): void
    {
        $evento = $this->evento();

        $this->actingAs($this->usuario(Rol::Administrador))
            ->patch(route('eventos.estado', $evento), ['status' => EventStatus::Publicado->value])
            ->assertSessionHasErrors(['status' => 'Para publicar falta cargar las jornadas, los tipos de acceso y la cuenta para las transferencias.']);

        $this->assertSame(EventStatus::Borrador, $evento->fresh()->status);
    }

    public function test_marcar_jornadas_deja_el_acceso_consumiendo_un_cupo_en_cada_una(): void
    {
        $evento = $this->evento();
        $primera = $this->jornada($evento, 'Jornada 1', 1);
        $segunda = $this->jornada($evento, 'Jornada 2', 2);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.accesos.store', $evento), [
                'name' => 'Ambas jornadas',
                'price' => 150000,
                'sessions' => [$primera->id, $segunda->id],
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $acceso = AccessType::where('name', 'Ambas jornadas')->firstOrFail();

        $this->assertSame(
            [$primera->id => 1, $segunda->id => 1],
            $acceso->load('sessions')->consumoDeCupos(),
        );
    }

    public function test_un_acceso_sin_jornadas_no_se_guarda(): void
    {
        $evento = $this->evento();
        $this->jornada($evento, 'Jornada 1', 1);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.accesos.store', $evento), [
                'name' => 'Acceso suelto',
                'price' => 1000,
                'sessions' => [],
            ])
            ->assertSessionHasErrors('sessions');

        $this->assertDatabaseMissing('access_types', ['name' => 'Acceso suelto']);
    }

    public function test_un_acceso_no_puede_incluir_jornadas_de_otro_evento(): void
    {
        $evento = $this->evento();
        $this->jornada($evento, 'Jornada 1', 1);

        $otro = Event::create(['name' => 'Otro seminario', 'slug' => 'otro-seminario']);
        $ajena = $this->jornada($otro, 'Jornada ajena', 1);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.accesos.store', $evento), [
                'name' => 'Acceso cruzado',
                'price' => 1000,
                'sessions' => [$ajena->id],
            ])
            ->assertSessionHasErrors('sessions.0');

        $this->assertDatabaseMissing('access_types', ['name' => 'Acceso cruzado']);
    }

    public function test_una_jornada_con_cupos_tomados_no_se_elimina(): void
    {
        $evento = $this->evento();
        $jornada = $this->jornada($evento, 'Jornada 1', 1, tomados: 3);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->delete(route('eventos.jornadas.destroy', [$evento, $jornada]));

        $this->assertNotNull($jornada->fresh());
    }

    public function test_una_jornada_no_puede_empezar_antes_que_el_evento(): void
    {
        $evento = $this->evento(['starts_on' => '2026-11-05']);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.jornadas.store', $evento), [
                'name' => 'Jornada 1',
                'starts_at' => '2026-11-04 09:00',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.jornadas.store', $evento), [
                'name' => 'Jornada 1',
                'starts_at' => '2026-11-05 09:00',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_la_capacidad_no_baja_de_los_cupos_tomados(): void
    {
        $evento = $this->evento();
        $jornada = $this->jornada($evento, 'Jornada 1', 1, tomados: 40);

        $this->actingAs($this->usuario(Rol::Administrador))
            ->patch(route('eventos.jornadas.update', [$evento, $jornada]), ['name' => 'Jornada 1', 'capacity' => 30])
            ->assertSessionHasErrors('capacity');

        $this->assertSame(100, $jornada->fresh()->capacity);
    }

    public function test_el_formulario_publico_no_permite_quitar_el_correo(): void
    {
        $evento = $this->evento();

        $this->actingAs($this->usuario(Rol::Administrador))
            ->put(route('eventos.formulario.update', $evento), [
                'campos' => [
                    ['key' => 'first_name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'options' => []],
                ],
            ])
            ->assertSessionHasErrors('campos');

        $this->assertNull($evento->fresh()->program_form_fields);
    }

    public function test_el_formulario_publico_guarda_el_contacto_del_evento_normalizado(): void
    {
        $evento = $this->evento();
        $campos = [['key' => 'email', 'label' => 'Correo', 'type' => 'email', 'required' => true, 'options' => []]];

        $this->actingAs($this->usuario(Rol::Administrador))
            ->put(route('eventos.formulario.update', $evento), ['campos' => $campos])
            ->assertSessionHasErrors(['contact_name', 'contact_role', 'contact_email', 'contact_phone']);

        $this->put(route('eventos.formulario.update', $evento), [
            'campos' => $campos,
            'contact_name' => 'Flor Vidal Oliva', 'contact_role' => 'Administración y Atención Clientes', 'contact_organization' => 'Corporación Liderazgo y Calidad Educacional',
            'contact_email' => ' Flor@VisionActiva.cl', 'contact_phone' => '9-51887769', 'contact_whatsapp' => '9 3456 7890',
        ])->assertSessionHasNoErrors();

        $evento->refresh();
        $this->assertSame('flor@visionactiva.cl', $evento->contact_email);
        $this->assertSame('56951887769', $evento->contact_phone);
        $this->assertSame('56934567890', $evento->contact_whatsapp);
    }

    public function test_los_archivos_del_evento_aceptan_varios_formatos_pero_no_ejecutables_ni_mas_de_10_mb(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $evento = $this->evento();
        $this->actingAs($this->usuario(Rol::Administrador));

        foreach (['instalador.exe' => 'application/x-msdownload', 'dibujo.svg' => 'image/svg+xml', 'comprimido.zip' => 'application/zip'] as $nombre => $mime) {
            $this->post(route('eventos.programa.store', $evento), [
                'programa' => [UploadedFile::fake()->create($nombre, 5, $mime)],
            ])->assertSessionHasErrors('programa.0');
        }

        $this->post(route('eventos.programa.store', $evento), [
            'programa' => [UploadedFile::fake()->create('grande.pdf', 10 * 1024 + 1, 'application/pdf')],
        ])->assertSessionHasErrors('programa.0');

        $this->assertSame(0, $evento->attachments()->count());
    }

    public function test_se_suben_varios_archivos_a_la_vez_y_se_acumulan(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $evento = $this->evento();
        $this->actingAs($this->usuario(Rol::Administrador));

        $this->post(route('eventos.programa.store', $evento), [
            'programa' => [
                UploadedFile::fake()->create('programa.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('ficha.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ],
        ])->assertSessionHasNoErrors();

        $this->post(route('eventos.programa.store', $evento), [
            'programa' => [UploadedFile::fake()->create('planilla.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['programa.pdf', 'ficha.docx', 'planilla.xlsx'], $evento->attachments()->pluck('original_name')->all());
        $this->assertCount(3, Storage::disk(config('filesystems.private_disk'))->allFiles('programas'));
    }

    public function test_el_correo_adjunta_todos_los_archivos_mientras_quepan(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $evento = $this->evento();
        $disco = config('filesystems.private_disk');

        foreach ([['uno.pdf', 2], ['ficha.docx', 3], ['enorme.pdf', 14], ['final.pdf', 2]] as [$nombre, $mb]) {
            Storage::disk($disco)->put("programas/{$nombre}", str_repeat('a', $mb * 1024 * 1024));
            $evento->attachments()->create(['disk' => $disco, 'path' => "programas/{$nombre}", 'original_name' => $nombre, 'mime_type' => 'application/octet-stream', 'size' => $mb * 1024 * 1024]);
        }

        $solicitud = ProgramRequest::create(['event_id' => $evento->id, 'first_name' => 'Ana', 'last_name' => 'Paz', 'email' => 'ana@colegio.cl', 'consented_at' => now()]);
        $adjuntos = (new ProgramaDelEvento($solicitud->load('event.attachments')))->attachments();

        // 2 + 3 caben; el de 14 MB pasaría de los 15 y se queda fuera; el último sí cabe.
        $this->assertSame(['uno.pdf', 'ficha.docx', 'final.pdf'], array_map(fn ($a) => $a->as, $adjuntos));
    }

    public function test_el_programa_subido_se_descarga_solo_con_permiso_y_desde_su_evento(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $evento = $this->evento();
        $this->actingAs($this->usuario(Rol::Administrador));
        $this->post(route('eventos.programa.store', $evento), [
            'programa' => [UploadedFile::fake()->create('programa.pdf', 50, 'application/pdf')],
        ]);
        $archivo = $evento->attachments()->sole();

        $this->get(route('eventos.programa.download', [$evento, $archivo]))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=programa.pdf');

        // Con el id de otro evento, 404: el archivo no sale por un evento ajeno.
        $this->get(route('eventos.programa.download', [$this->evento(['slug' => 'otro']), $archivo]))->assertNotFound();

        $this->actingAs($this->usuario(Rol::Contabilidad))
            ->get(route('eventos.programa.download', [$evento, $archivo]))
            ->assertForbidden();
    }

    public function test_un_evento_nuevo_parte_con_el_contacto_por_defecto(): void
    {
        $this->actingAs($this->usuario(Rol::Administrador))
            ->post(route('eventos.store'), ['name' => 'Seminario de Liderazgo', 'modality' => 'presencial', 'starts_on' => now()->addMonth()->toDateString()])
            ->assertSessionHasNoErrors();

        $evento = Event::where('name', 'Seminario de Liderazgo')->sole();
        $this->assertSame('Flor Vidal Oliva', $evento->contact_name);
        $this->assertSame('administracion@visionactiva.cl', $evento->contact_email);
        $this->assertSame('56951887769', $evento->contact_phone);
    }

    public function test_varios_archivos_llegan_al_portal_y_solo_el_pdf_se_muestra_en_el_navegador(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $evento = $this->evento(['status' => EventStatus::Publicado]);
        $this->actingAs($this->usuario(Rol::Administrador))->post(route('eventos.programa.store', $evento), [
            'programa' => [
                UploadedFile::fake()->createWithContent('programa.pdf', '%PDF-1.4 programa'),
                UploadedFile::fake()->createWithContent('ficha.docx', 'PK ficha'),
                // Un html que se hace pasar por pdf no debe correr en este dominio.
                UploadedFile::fake()->createWithContent('falso.pdf', '<html><script>alert(1)</script></html>'),
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $evento->attachments()->count());

        $orden = Order::create(['event_id' => $evento->id, 'responsible_name' => 'Ana', 'responsible_lastname' => 'Paz', 'responsible_email' => 'ana@colegio.cl']);
        [, $token] = MagicLink::emitir($evento, 'ana@colegio.cl', $orden);
        [$pdf, $docx, $falso] = $evento->attachments()->get()->all();
        $ruta = fn ($a, $ver = 1) => route('inscripcion.portal.adjunto', ['token' => $token, 'attachment' => $a->id, 'ver' => $ver]);

        $this->get(route('inscripcion.portal.programa', ['token' => $token]))
            ->assertOk()->assertSee('Documentos del evento')->assertSee('programa.pdf')->assertSee('ficha.docx');

        $this->get($ruta($pdf))->assertOk()->assertHeader('content-disposition', 'inline; filename="programa.pdf"');
        $this->get($ruta($docx))->assertOk()->assertDownload('ficha.docx');
        $this->get($ruta($falso))->assertOk()->assertDownload('falso.pdf');
    }
}
