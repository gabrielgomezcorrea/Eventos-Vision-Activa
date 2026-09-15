<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\InvoiceRecord;
use App\Models\Order;
use App\Models\Participant;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistroDeFacturaTest extends TestCase
{
    use RefreshDatabase;

    private Order $orden;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesSeeder::class);

        $event = Event::factory()->conJornadasYAccesos(50)->create();
        $orden = Order::factory()->create(['event_id' => $event->id]);
        $establecimiento = Establishment::factory()->create();
        $orden->establishments()->attach($establecimiento);

        Participant::factory()->create([
            'order_id' => $orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $event->accessTypes()->first()->id,
        ]);

        $this->orden = app(ConfirmarOrden::class)($orden->fresh());
        $this->orden->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        $this->orden->refresh();
    }

    public function test_una_orden_sin_documento_esta_pendiente_de_factura(): void
    {
        $this->assertSame(InvoiceStatus::Pendiente, $this->orden->estadoDeFactura());
    }

    public function test_registrar_el_documento_la_deja_emitida(): void
    {
        $this->orden->invoiceRecords()->create([
            'document_type' => InvoiceDocumentType::Factura,
            'number' => '00012345',
            'issued_on' => now(),
            'amount' => $this->orden->total,
        ]);

        $this->assertSame(InvoiceStatus::Emitida, $this->orden->fresh()->estadoDeFactura());
    }

    public function test_el_estado_se_deriva_y_no_se_guarda_como_columna(): void
    {
        // Una sola fuente de verdad: si el estado viviera en una columna, se
        // podría desincronizar del documento registrado.
        $this->assertArrayNotHasKey('invoice_status', $this->orden->getAttributes());
    }

    public function test_no_se_registra_dos_veces_el_mismo_documento(): void
    {
        $datos = [
            'document_type' => InvoiceDocumentType::Factura,
            'number' => '00012345',
            'issued_on' => now(),
            'amount' => 100000,
        ];

        $this->orden->invoiceRecords()->create($datos);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->orden->invoiceRecords()->create($datos);
    }

    public function test_una_boleta_y_una_factura_pueden_tener_el_mismo_numero(): void
    {
        $this->orden->invoiceRecords()->create([
            'document_type' => InvoiceDocumentType::Factura,
            'number' => '100', 'issued_on' => now(), 'amount' => 1000,
        ]);

        $this->orden->invoiceRecords()->create([
            'document_type' => InvoiceDocumentType::Boleta,
            'number' => '100', 'issued_on' => now(), 'amount' => 1000,
        ]);

        $this->assertSame(2, InvoiceRecord::count());
    }

    public function test_describe_el_documento_en_lenguaje_del_negocio(): void
    {
        $factura = $this->orden->invoiceRecords()->create([
            'document_type' => InvoiceDocumentType::Factura,
            'number' => '00012345', 'issued_on' => now(), 'amount' => 1000,
        ]);

        $this->assertSame('Factura N° 00012345', $factura->descripcion());
    }

    public function test_solo_contabilidad_y_admin_gestionan_facturas(): void
    {
        foreach ([Rol::Contabilidad, Rol::Administrador] as $rol) {
            $u = User::factory()->create();
            $u->assignRole($rol->value);
            $this->assertTrue($u->fresh()->can('gestionar_facturas'));
        }

        foreach ([Rol::Coordinacion, Rol::Acreditacion] as $rol) {
            $u = User::factory()->create();
            $u->assignRole($rol->value);
            $this->assertFalse($u->fresh()->can('gestionar_facturas'));
        }
    }
}
