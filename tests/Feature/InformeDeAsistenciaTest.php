<?php

namespace Tests\Feature;

use App\Actions\ConfirmarOrden;
use App\Actions\EmitirTickets;
use App\Actions\InformeDeAsistencia;
use App\Enums\PaymentStatus;
use App\Enums\Rol;
use App\Models\Accreditation;
use App\Models\Event;
use App\Models\Order;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Certificates come out of this report: nobody who paid may be missing, and
 * each session column must say whether they came.
 */
class InformeDeAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_a_todos_los_pagados_con_una_columna_por_jornada(): void
    {
        Mail::fake();
        $evento = Event::factory()->conJornadasYAccesos(50)->create();
        [$j1, $j2] = $evento->sessions()->orderBy('position')->get()->all();
        $acceso = fn (string $nombre) => $evento->accessTypes()->where('name', $nombre)->sole()->id;

        $pagada = Order::factory()->create(['event_id' => $evento->id]);
        foreach ([['Ana', 'Jornada 1'], ['Beto', 'Ambas jornadas'], ['Carla', 'Ambas jornadas'], ['Dani', 'Jornada 2']] as [$nombre, $a]) {
            Participant::factory()->create(['order_id' => $pagada->id, 'first_name' => $nombre, 'last_name' => 'Pérez', 'access_type_id' => $acceso($a), 'email' => null]);
        }
        $pagada = app(ConfirmarOrden::class)($pagada->fresh());
        $pagada->forceFill(['payment_status' => PaymentStatus::Aprobado])->save();
        app(EmitirTickets::class)($pagada->fresh());

        $llega = function (string $nombre, $cuando) use ($pagada, $evento): void {
            $ticket = $pagada->tickets()->vigentes()->whereHas('participant', fn ($q) => $q->where('first_name', $nombre))->sole();
            Accreditation::create(['ticket_id' => $ticket->id, 'event_id' => $evento->id, 'participant_id' => $ticket->participant_id, 'accredited_at' => $cuando]);
        };
        $llega('Ana', $j1->starts_at->copy()->addHour());
        $llega('Beto', $j1->starts_at->copy()->addHour());
        $llega('Carla', $j2->starts_at->copy()->addHour());

        $sinPagar = Order::factory()->create(['event_id' => $evento->id]);
        Participant::factory()->create(['order_id' => $sinPagar->id, 'first_name' => 'Ema', 'access_type_id' => $acceso('Jornada 1')]);
        app(ConfirmarOrden::class)($sinPagar->fresh());

        $filas = app(InformeDeAsistencia::class)($evento)['Asistencia'];
        $porNombre = collect($filas)->slice(1)->keyBy(1)->map(fn (array $f) => array_slice($f, -2));

        $this->assertSame(['Jornada 1', 'Jornada 2'], array_slice($filas[0], -2));
        $this->assertSame(['Ana', 'Beto', 'Carla', 'Dani'], $porNombre->keys()->sort()->values()->all());
        $this->assertSame([InformeDeAsistencia::ASISTIO, InformeDeAsistencia::NO_INSCRITO], $porNombre['Ana']);
        $this->assertSame([InformeDeAsistencia::ASISTIO, InformeDeAsistencia::SIN_REGISTRO], $porNombre['Beto']);
        $this->assertSame([InformeDeAsistencia::NO_ASISTIO, InformeDeAsistencia::ASISTIO], $porNombre['Carla']);
        $this->assertSame([InformeDeAsistencia::NO_INSCRITO, InformeDeAsistencia::NO_ASISTIO], $porNombre['Dani']);

        $soloAsistieron = collect(app(InformeDeAsistencia::class)($evento, true)['Asistencia'])->slice(1)->pluck(1)->sort()->values()->all();
        $this->assertSame(['Ana', 'Beto', 'Carla'], $soloAsistieron);
    }

    public function test_descarga_el_excel_y_sin_permiso_no(): void
    {
        $evento = Event::factory()->conJornadasYAccesos(50)->create();

        $this->actingAs($this->usuarioConRol(Rol::Coordinacion))
            ->get(route('inscripciones.asistencia', ['evento' => $evento->id]))
            ->assertOk();

        $this->actingAs($this->usuarioConRol(Rol::Acreditacion))
            ->get(route('inscripciones.asistencia', ['evento' => $evento->id]))
            ->assertForbidden();
    }
}
