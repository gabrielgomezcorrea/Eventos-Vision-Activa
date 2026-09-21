<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\RecordatorioDeReserva;
use App\Mail\RecordatorioDeReservaConjunto;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecordatorioDeReservaTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::factory()->conJornadasYAccesos(100)->create();
    }

    private function ordenReservada(): Order
    {
        $orden = Order::factory()->create(['event_id' => $this->event->id]);
        $establecimiento = Establishment::factory()->create();
        $orden->establishments()->attach($establecimiento);

        Participant::factory()->create([
            'order_id' => $orden->id,
            'establishment_id' => $establecimiento->id,
            'access_type_id' => $this->event->accessTypes()->first()->id,
        ]);

        return app(ConfirmarOrden::class)($orden->fresh());
    }

    public function test_avisa_a_una_reserva_que_vence_pronto(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->addHours(20)])->save();

        $this->artisan('reservas:recordar')->assertSuccessful();

        Mail::assertQueued(RecordatorioDeReserva::class, fn ($m) => $m->hasTo($orden->responsible_email));
        $this->assertNotNull($orden->fresh()->reminder_sent_at);
    }

    public function test_no_avisa_a_una_reserva_lejana(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->addDays(10)])->save();

        $this->artisan('reservas:recordar')->assertSuccessful();

        Mail::assertNotQueued(RecordatorioDeReserva::class);
    }

    public function test_avisa_una_sola_vez_aunque_el_comando_corra_cada_hora(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->addHours(10)])->save();

        $this->artisan('reservas:recordar');
        $this->artisan('reservas:recordar');
        $this->artisan('reservas:recordar');

        Mail::assertQueued(RecordatorioDeReserva::class, 1);
    }

    public function test_no_avisa_si_ya_hay_un_comprobante_en_validacion(): void
    {
        // La pelota está del lado de Contabilidad: pedirle el pago al cliente
        // que ya pagó es la mejor forma de generar una llamada molesta.
        $orden = $this->ordenReservada();
        $orden->forceFill([
            'reserved_until' => now()->addHours(10),
            'payment_status' => PaymentStatus::EnValidacion,
        ])->save();

        $this->artisan('reservas:recordar');

        Mail::assertNotQueued(RecordatorioDeReserva::class);
    }

    public function test_no_avisa_a_ordenes_que_ya_no_estan_reservadas(): void
    {
        foreach ([OrderStatus::Vencida, OrderStatus::Cancelada] as $estado) {
            $orden = $this->ordenReservada();
            $orden->forceFill(['reserved_until' => now()->addHours(5), 'status' => $estado])->save();
        }

        $this->artisan('reservas:recordar');

        Mail::assertNotQueued(RecordatorioDeReserva::class);
    }

    public function test_el_dry_run_no_envia_ni_marca(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->addHours(10)])->save();

        $this->artisan('reservas:recordar', ['--dry-run' => true])->assertSuccessful();

        Mail::assertNotQueued(RecordatorioDeReserva::class);
        $this->assertNull($orden->fresh()->reminder_sent_at);
    }

    public function test_la_ventana_de_aviso_es_configurable(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->addDays(5)])->save();

        $this->artisan('reservas:recordar');
        Mail::assertNotQueued(RecordatorioDeReserva::class);

        $this->artisan('reservas:recordar', ['--horas' => 24 * 7]);
        Mail::assertQueued(RecordatorioDeReserva::class, 1);
    }

    public function test_dos_ordenes_del_mismo_conjunto_que_vencen_el_mismo_dia_van_en_un_solo_correo(): void
    {
        $grupo = OrderGroup::create(['event_id' => $this->event->id, 'responsible_email' => 'ana@colegio.cl']);

        $sanJose = $this->ordenReservada();
        $sanJose->forceFill(['group_id' => $grupo->id, 'responsible_email' => 'ana@colegio.cl', 'reserved_until' => now()->addHours(10)])->save();

        $losRobles = $this->ordenReservada();
        $losRobles->forceFill(['group_id' => $grupo->id, 'responsible_email' => 'ana@colegio.cl', 'reserved_until' => now()->addHours(15)])->save();

        $this->artisan('reservas:recordar')->assertSuccessful();

        Mail::assertQueued(RecordatorioDeReservaConjunto::class, 1);
        Mail::assertNotQueued(RecordatorioDeReserva::class);
        $this->assertNotNull($sanJose->fresh()->reminder_sent_at);
        $this->assertNotNull($losRobles->fresh()->reminder_sent_at);
    }

    public function test_una_orden_sin_conjunto_sigue_con_su_propio_correo(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->addHours(10)])->save();

        $this->artisan('reservas:recordar')->assertSuccessful();

        Mail::assertQueued(RecordatorioDeReserva::class, 1);
        Mail::assertNotQueued(RecordatorioDeReservaConjunto::class);
    }

    public function test_el_correo_lleva_el_folio_el_monto_y_como_pagar(): void
    {
        $orden = $this->ordenReservada();
        $orden->forceFill(['reserved_until' => now()->addHours(10)])->save();

        $html = (new RecordatorioDeReserva($orden->fresh()))->render();

        $this->assertStringContainsString($orden->number, $html);
        $this->assertStringContainsString(number_format($orden->total, 0, ',', '.'), $html);
        $this->assertStringContainsString($this->event->bank_account_number, $html);
        $this->assertStringContainsString('/i/o/', $html);
    }
}
