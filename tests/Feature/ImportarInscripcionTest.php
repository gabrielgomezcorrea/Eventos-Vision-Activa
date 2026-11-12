<?php

namespace Tests\Feature;

use App\Actions\ImportarInscripcion;
use App\Enums\EventStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Enums\TipoDescuento;
use App\Exceptions\ImportacionDetenida;
use App\Mail\AvisoComprobantePorRevisar;
use App\Mail\AvisoInscripcionConfirmada;
use App\Mail\ConjuntoConfirmado;
use App\Mail\CredencialParticipante;
use App\Mail\EnlaceDeAcceso;
use App\Mail\OrdenConfirmada;
use App\Mail\PagoAprobado;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Importing an enrollment received by email: money, seats and personal data, so it is tested. */
class ImportarInscripcionTest extends TestCase
{
    use RefreshDatabase;

    private Event $evento;

    private User $usuario;

    private const RUTS = ['10.994.953-1', '10.319.713-9', '8.807.962-0', '18.135.942-0', '17.513.939-7', '6.512.376-1'];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake(config('filesystems.private_disk'));

        $this->evento = Event::create(['name' => 'Seminario 2026', 'slug' => 'seminario-2026', 'status' => EventStatus::Publicado]);
        $jornada = $this->evento->sessions()->create(['name' => 'Jornada 2', 'position' => 1, 'capacity' => 100]);
        $acceso = $this->evento->accessTypes()->create(['name' => 'Jornada 2', 'position' => 1, 'price' => 100000]);
        $acceso->sessions()->attach($jornada->id, ['seats' => 1]);

        $this->usuario = $this->usuarioConRol(Rol::Coordinacion, ['name' => 'Coordi Nadora']);
    }

    /** @return array<string, mixed> */
    private function datos(int $participantes = 4, int $monto = 400000, array $extra = []): array
    {
        $lista = [];
        $nombres = ['Rodrigo', 'Luisa', 'Sonia', 'Romina', 'Nicole', 'Pedro'];
        $apellidos = ['Villarroel', 'Arriagada', 'Barrera', 'Guerrero', 'Romero', 'Fuentes'];

        for ($i = 0; $i < $participantes; $i++) {
            $lista[] = [
                'first_name' => $nombres[$i], 'last_name' => $apellidos[$i], 'rut' => self::RUTS[$i],
                'position' => 'Director/a', 'email' => "persona{$i}@colegio.cl", 'access' => 'jornada 2', 'school' => 'A',
            ];
        }

        return array_replace_recursive([
            'event' => 'seminario-2026',
            'responsible' => [
                'responsible_name' => 'Celmira', 'responsible_lastname' => 'Montaner Echeverría',
                'responsible_position' => 'Directivo', 'responsible_email' => 'Celmira@Colegio.cl', 'responsible_phone' => '56912345678',
            ],
            'schools' => [['key' => 'A', 'name' => 'Colegio Montaner', 'rbd' => '4790-2', 'region' => 'Biobío', 'commune' => 'Hualpén']],
            'payer' => [
                'name' => 'Fundación Educacional Montaner', 'rut' => '65.155.175-7', 'address' => 'Avenida Grecia 1911',
                'commune' => 'Hualpén', 'billing_email' => 'celmira@colegio.cl', 'business_activity' => 'Educación', 'billing_reference' => null,
            ],
            'participants' => $lista,
            'code_hint' => null,
            'payment' => [
                'method' => 'transfer', 'amount' => $monto, 'paid_on' => now('America/Santiago')->subDay()->toDateString(),
                'file' => ['name' => 'comprobante.pdf', 'base64' => base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")],
            ],
            'note' => 'Llegó por correo.',
        ], $extra);
    }

    private function importar(array $datos, bool $simular = false): array
    {
        return app(ImportarInscripcion::class)($datos, $this->usuario, $simular);
    }

    private function detenida(array $datos): ImportacionDetenida
    {
        try {
            $this->importar($datos);
        } catch (ImportacionDetenida $e) {
            return $e;
        }

        $this->fail('Se esperaba que la importación se detuviera.');
    }

    /** @return array<string, int> */
    private function conteos(): array
    {
        return collect(['orders', 'payments', 'program_requests', 'participants', 'order_groups', 'magic_links', 'audit_logs', 'establishments', 'payer_entities'])
            ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])
            ->all() + ['code_uses' => (int) DiscountCode::query()->sum('used_people')];
    }

    private function codigo(string $code, TipoDescuento $tipo, int $valor, array $extra = []): DiscountCode
    {
        return DiscountCode::factory()->create([
            'event_id' => $this->evento->id, 'code' => $code, 'type' => $tipo, 'value' => $valor, ...$extra,
        ]);
    }

    public function test_un_colegio_al_contado_sin_codigo_queda_confirmado_con_su_comprobante_y_sin_correos_al_cliente(): void
    {
        $resultado = $this->importar($this->datos());

        $this->assertTrue($resultado['ok']);
        $this->assertSame(400000, $resultado['total']);
        $this->assertNull($resultado['code_applied']);

        $orden = Order::query()->sole();
        $this->assertSame($this->usuario->id, $orden->created_by_user_id);
        $this->assertFalse($orden->notify_client);
        $this->assertNotNull($orden->number);
        $this->assertSame(4, $orden->participantesVigentes()->count());
        $this->assertSame(PaymentStatus::EnValidacion, $orden->payment_status);

        $pago = $orden->payments()->sole();
        $this->assertSame(400000, $pago->amount);
        $this->assertSame($this->usuario->id, $pago->submitted_by_user_id);
        $this->assertNotEmpty(Storage::disk(config('filesystems.private_disk'))->allFiles());

        Mail::assertNotQueued(EnlaceDeAcceso::class);
        Mail::assertNotQueued(OrdenConfirmada::class);
        Mail::assertNotQueued(ConjuntoConfirmado::class);
        Mail::assertNotQueued(CredencialParticipante::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'orden.importada']);
    }

    public function test_los_avisos_internos_salen_despues_de_confirmar(): void
    {
        $contabilidad = $this->usuarioConRol(Rol::Contabilidad);
        $contabilidad->forceFill(['notify_confirmed_orders' => true, 'notify_payment_proofs' => true])->save();

        $this->importar($this->datos());

        Mail::assertQueued(AvisoInscripcionConfirmada::class, 1);
        Mail::assertQueued(AvisoComprobantePorRevisar::class, 1);
    }

    public function test_un_monto_que_solo_calza_con_el_codigo_lo_aplica_y_cuenta_una_persona(): void
    {
        $codigo = $this->codigo('CMEI2026', TipoDescuento::Porcentaje, 25);

        $resultado = $this->importar($this->datos(monto: 300000));

        $this->assertSame('CMEI2026', $resultado['code_applied']);
        $this->assertStringContainsString('Se aplicó el código CMEI2026', implode(' ', $resultado['warnings']));

        $orden = Order::query()->sole();
        $this->assertSame($codigo->id, $orden->discount_code_id);
        $this->assertSame(300000, (int) $orden->total);
        $this->assertSame(1, $codigo->fresh()->used_people);
    }

    public function test_si_el_documento_menciona_el_codigo_pero_calza_sin_descuento_no_se_aplica(): void
    {
        $this->codigo('CMEI2026', TipoDescuento::Porcentaje, 25);

        $resultado = $this->importar($this->datos(extra: ['code_hint' => 'cmei-2026']));

        $this->assertNull($resultado['code_applied']);
        $this->assertStringContainsString('no se aplicó', implode(' ', $resultado['warnings']));
    }

    public function test_un_monto_que_no_calza_con_nada_se_detiene_y_no_guarda_nada(): void
    {
        $this->codigo('CMEI2026', TipoDescuento::Porcentaje, 25);
        $antes = $this->conteos();

        $e = $this->detenida($this->datos(monto: 123456));

        $this->assertStringContainsString('no calza con el total', $e->problemas[0]);
        $this->assertStringContainsString('sin código $400.000', $e->problemas[0]);
        $this->assertStringContainsString('con CMEI2026 $300.000', $e->problemas[0]);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_un_codigo_vencido_que_calzaria_no_se_aplica_y_se_detiene_con_su_motivo(): void
    {
        $this->codigo('VIEJO', TipoDescuento::Porcentaje, 25, ['expires_at' => now()->subDay()]);

        $e = $this->detenida($this->datos(monto: 300000, extra: ['code_hint' => 'VIEJO']));

        $this->assertStringContainsString('VIEJO no se puede usar', implode(' ', $e->problemas));
        $this->assertSame(0, Order::count());
    }

    public function test_dos_colegios_hacen_dos_ordenes_en_un_conjunto_cada_una_con_su_pago(): void
    {
        $datos = $this->datos(participantes: 6, monto: 600000, extra: [
            'schools' => [1 => ['key' => 'B', 'name' => 'Colegio Dos Hermanos', 'region' => 'Biobío']],
        ]);
        $datos['participants'][4]['school'] = 'B';
        $datos['participants'][5]['school'] = 'B';

        $resultado = $this->importar($datos);

        $this->assertCount(2, $resultado['orders']);
        $ordenes = Order::query()->orderBy('id')->get();
        $this->assertCount(1, $ordenes->pluck('group_id')->unique());
        $this->assertNotNull($ordenes[0]->number);
        $this->assertNotSame($ordenes[0]->number, $ordenes[1]->number);
        $this->assertSame([4, 2], $ordenes->map(fn (Order $o) => $o->participantesVigentes()->count())->all());
        $this->assertSame([400000, 200000], $ordenes->map(fn (Order $o) => $o->payments()->sole()->amount)->all());
    }

    public function test_el_descuento_por_cantidad_se_compara_con_el_total_real_por_colegio(): void
    {
        $this->evento->discountTiers()->create(['min_participants' => 5, 'type' => 'percent', 'value' => 10]);

        $datos = $this->datos(participantes: 6, monto: 600000, extra: [
            'schools' => [1 => ['key' => 'B', 'name' => 'Colegio Dos Hermanos', 'region' => 'Biobío']],
        ]);
        $datos['participants'][4]['school'] = 'B';
        $datos['participants'][5]['school'] = 'B';

        // 4 + 2 people never reach the tier of 5 per school: the real total is $600.000.
        $resultado = $this->importar($datos);

        $this->assertSame(600000, $resultado['total']);
    }

    public function test_un_porcentaje_que_redondea_distinto_por_colegio_calza_con_la_suma_real(): void
    {
        $this->evento->accessTypes()->update(['price' => 33333]);
        $this->codigo('REDONDO', TipoDescuento::Porcentaje, 15);

        $datos = $this->datos(participantes: 3, monto: 0, extra: [
            'schools' => [1 => ['key' => 'B', 'name' => 'Colegio Dos Hermanos', 'region' => 'Biobío']],
        ]);
        $datos['participants'][2]['school'] = 'B';

        // Probe: ask the system which total it really charges with the code.
        $e = $this->detenida(array_replace_recursive($datos, ['payment' => ['amount' => 1]]));
        preg_match('/con REDONDO \$([\d.]+)/', $e->problemas[0], $m);
        $real = (int) str_replace('.', '', $m[1]);

        $resultado = $this->importar(array_replace_recursive($datos, ['payment' => ['amount' => $real]]));

        $this->assertSame('REDONDO', $resultado['code_applied']);
        $this->assertSame($real, $resultado['total']);
    }

    public function test_pago_a_credito_registra_el_pago_por_el_saldo_sin_archivo(): void
    {
        $datos = $this->datos(extra: ['payment' => ['method' => 'credit', 'purchase_order' => 'OC-77']]);
        unset($datos['payment']['file'], $datos['payment']['amount'], $datos['payment']['paid_on']);

        $this->importar($datos);

        $pago = Order::query()->sole()->payments()->sole();
        $this->assertSame(PaymentMethod::Credito, $pago->method);
        $this->assertSame(400000, $pago->amount);
        $this->assertSame('OC-77', $pago->purchase_order);
        $this->assertEmpty(Storage::disk(config('filesystems.private_disk'))->allFiles());
    }

    public function test_si_ya_existe_una_inscripcion_viva_del_correo_se_detiene_sin_crear_nada(): void
    {
        $this->importar($this->datos());
        $antes = $this->conteos();

        $e = $this->detenida($this->datos());

        $this->assertStringContainsString('Ya existe una inscripción viva', $e->problemas[0]);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_simular_devuelve_el_resultado_sin_tocar_la_base_ni_el_disco_ni_los_correos(): void
    {
        $antes = $this->conteos();

        $resultado = $this->importar($this->datos(), simular: true);

        $this->assertTrue($resultado['ok']);
        $this->assertTrue($resultado['simulated']);
        $this->assertSame(400000, $resultado['total']);
        $this->assertNull($resultado['orders'][0]['number']);
        $this->assertSame($antes, $this->conteos());
        $this->assertEmpty(Storage::disk(config('filesystems.private_disk'))->allFiles());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_un_usuario_sin_permiso_de_crear_inscripciones_se_detiene(): void
    {
        $contabilidad = $this->usuarioConRol(Rol::Contabilidad);

        $this->expectException(ImportacionDetenida::class);

        app(ImportarInscripcion::class)($this->datos(), $contabilidad);
    }

    public function test_el_comando_valida_el_usuario_y_responde_json(): void
    {
        $contabilidad = $this->usuarioConRol(Rol::Contabilidad);
        $this->app->instance('inscripciones.entrada', json_encode($this->datos()));

        $this->artisan('inscripciones:importar', ['--usuario' => $contabilidad->email])->assertExitCode(1);
        $this->assertSame(0, Order::count());

        $this->artisan('inscripciones:importar', ['--usuario' => $this->usuario->email, '--simular' => true])->assertExitCode(0);
        $this->assertSame(0, Order::count());

        $this->app->instance('inscripciones.entrada', 'no es json');
        $this->artisan('inscripciones:importar', ['--usuario' => $this->usuario->email])->assertExitCode(1);
    }

    public function test_telefono_fijo_y_rut_invalido_se_importan_sin_esos_datos_con_dos_avisos(): void
    {
        $datos = $this->datos();
        $datos['responsible']['responsible_phone'] = '412345678';
        $datos['participants'][0]['rut'] = '10.994.953-2';

        $resultado = $this->importar($datos);

        $this->assertCount(2, $resultado['warnings']);
        $orden = Order::query()->sole();
        $this->assertNull($orden->responsible_phone);
        $this->assertNull($orden->participants()->where('first_name', 'Rodrigo')->value('rut'));
    }

    public function test_responsable_sin_telefono_se_importa_sin_aviso(): void
    {
        $datos = $this->datos();
        $datos['responsible']['responsible_phone'] = null;

        $resultado = $this->importar($datos);

        $this->assertSame([], $resultado['warnings']);
    }

    public function test_un_codigo_completo_deja_la_orden_pagada_y_envia_credenciales_aunque_no_se_avise_al_cliente(): void
    {
        $this->codigo('GRATIS', TipoDescuento::Porcentaje, 100);
        $datos = $this->datos(extra: ['code_hint' => 'GRATIS', 'payment' => ['method' => 'credit']]);
        unset($datos['payment']['file'], $datos['payment']['amount'], $datos['payment']['paid_on']);

        $resultado = $this->importar($datos);

        $orden = Order::query()->sole();
        $this->assertSame(0, $resultado['total']);
        $this->assertSame(PaymentStatus::Aprobado, $orden->payment_status);
        $this->assertSame(0, $orden->payments()->count());
        $this->assertFalse($orden->notify_client);
        Mail::assertQueued(PagoAprobado::class);
        Mail::assertQueued(CredencialParticipante::class, 4);
    }

    public function test_inscripciones_evento_lista_accesos_cargos_y_codigos_sin_avisos(): void
    {
        $this->codigo('CMEI2026', TipoDescuento::Porcentaje, 25);

        $this->artisan('inscripciones:evento', ['slug' => 'seminario-2026'])->assertExitCode(0);
        $this->artisan('inscripciones:evento', ['slug' => 'no-existe'])->assertExitCode(1);

        Artisan::call('inscripciones:evento', ['slug' => 'seminario-2026']);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Jornada 2', $json['accesses'][0]['name']);
        $this->assertContains('Director/a', $json['positions']);
        $this->assertContains('Biobío', $json['regions']);
        $this->assertSame(['code' => 'CMEI2026', 'type' => 'percent', 'usable' => true, 'reason' => null], array_intersect_key($json['codes'][0], array_flip(['code', 'type', 'usable', 'reason'])));
    }

    public function test_acceso_desconocido_se_detiene_nombrando_los_validos(): void
    {
        $datos = $this->datos();
        $datos['participants'][0]['access'] = 'Jornada 9';

        $e = $this->detenida($datos);

        $this->assertStringContainsString('Jornada 9', $e->problemas[0]);
        $this->assertStringContainsString('Válidos: Jornada 2', $e->problemas[0]);
        $this->assertSame(0, Order::count());
    }
}
