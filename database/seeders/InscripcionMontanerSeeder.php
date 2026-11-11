<?php

namespace Database\Seeders;

use App\Actions\EmitirEnlaceDeAcceso;
use App\Enums\OrderKind;
use App\Models\AccessType;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\ProgramRequest;
use App\Support\Auditor;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off, run by hand in production (11/11/2026): Colegio Montaner sent its
 * enrollment form and transfer by email instead of using the portal. Leaves the
 * draft complete (responsible, school, payer, 5 participants, code CMEI2026) so
 * the team only opens it, goes to Pago and uploads the proof.
 *
 * Every block except the responsible goes through the same public endpoints the
 * client uses, so validation and normalization are the real ones. The
 * responsible is written directly because they only gave a landline, which the
 * form rejects; the phone is not needed to confirm. Created by "Sistema".
 *
 * php artisan db:seed --class=InscripcionMontanerSeeder --force
 */
class InscripcionMontanerSeeder extends Seeder
{
    private const EVENTO = 'seminario-liderazgo-noviembre-2026';

    private const CORREO = 'celmira@colegiomontaner.cl';

    private const CODIGO = 'CMEI2026';

    private const ACCESO = 'Jornada 2';

    private const PARTICIPANTES = [
        ['Rodrigo Manuel', 'Villarroel Montaner', '10.994.953-1', 'Administrador', 'rodrigo.villarroel@colegiomontaner.cl'],
        ['Luisa Evandra', 'Arriagada Durán', '10.319.713-9', 'Jefe de UTP', 'l_arriagada@colegiomontaner.cl'],
        ['Sonia del Pilar', 'Barrera Martínez', '8.807.962-0', 'Coordinadora Ciclo Media', 'sonia.barrera@colegiomontaner.cl'],
        ['Romina Francesca', 'Guerrero Barto', '18.135.942-0', 'Coordinadora PIE', 'romina.guerrero@colegiomontaner.cl'],
        ['Nicole Alejandra', 'Romero Ulloa', '17.513.939-7', 'Encargada CRA', 'nicole.romero@colegiomontaner.cl'],
    ];

    public function run(): void
    {
        $evento = Event::query()->where('slug', self::EVENTO)->firstOrFail();
        $acceso = AccessType::query()
            ->where('event_id', $evento->id)
            ->where('is_active', true)
            ->where('name', 'like', '%'.self::ACCESO.'%')
            ->firstOrFail();

        if (($existente = app(EmitirEnlaceDeAcceso::class)->ordenVigenteDe($evento, self::CORREO)) !== null) {
            throw new RuntimeException('Ya existe una inscripción viva con '.self::CORREO." (id {$existente->id}). No se creó otra.");
        }

        $orden = DB::transaction(function () use ($evento, $acceso): Order {
            $solicitud = ProgramRequest::create([
                'event_id' => $evento->id,
                'email' => self::CORREO,
                'origin' => ProgramRequest::ORIGEN_DIRECTO,
                'consented_at' => now(),
            ]);

            $orden = Order::create([
                'event_id' => $evento->id,
                'program_request_id' => $solicitud->id,
                'notify_client' => false,
                'kind' => OrderKind::Institucional,
                'responsible_name' => 'Celmira',
                'responsible_lastname' => 'Montaner Echeverría',
                'responsible_position' => 'Representante Legal',
                'responsible_email' => self::CORREO,
            ]);

            Auditor::registrar($orden, 'orden.creada_desde_panel', comentario: 'Cargada desde la ficha enviada por correo.', propiedades: ['correo' => self::CORREO], actorLabel: 'Sistema');

            [, $token] = MagicLink::emitir($evento, self::CORREO, $orden);

            $this->enviar(route('inscripcion.establecimientos.agregar', $token), [
                'name' => 'Colegio Montaner', 'rbd' => '4790-2', 'region' => 'Biobío', 'commune' => 'Hualpén',
            ]);

            $this->enviar(route('inscripcion.pagador.guardar', $token), [
                'name' => 'Fundación Educacional Montaner',
                'rut' => '65.155.175-7',
                'address' => 'Avenida Grecia 1911 Irene Frei',
                'commune' => 'Hualpén',
                'billing_email' => self::CORREO,
                'business_activity' => 'Educación',
                'billing_reference' => 'Glosa: "Impulsando altas expectativas y motivación escolar" Recursos SEP 2026. Enviar factura también a secretaria.direccion@colegiomontaner.cl',
            ]);

            $colegio = $orden->fresh()->establishments()->sole();
            $cargos = $evento->cargosDeParticipante();

            foreach (self::PARTICIPANTES as [$nombres, $apellidos, $rut, $cargo, $correo]) {
                $enLista = in_array($cargo, $cargos, true);

                $this->enviar(route('inscripcion.participantes.agregar', $token), [
                    'first_name' => $nombres,
                    'last_name' => $apellidos,
                    'rut' => $rut,
                    'position' => $enLista ? $cargo : 'Otro',
                    'position_otro' => $enLista ? null : $cargo,
                    'email' => $correo,
                    'access_type_id' => $acceso->id,
                    'establishment_id' => $colegio->id,
                ]);
            }

            $this->enviar(route('inscripcion.codigo.aplicar', $token), ['codigo' => self::CODIGO, 'pestana' => 'inscripcion']);

            return $orden->fresh();
        });

        $total = $orden->calcularTotal();

        $this->command?->info("Inscripción lista (borrador, id {$orden->id}): ".$orden->participantesVigentes()->count().' participantes, total $'.number_format($total, 0, ',', '.').'.');
        $this->command?->info('Ábrela en el panel: '.route('inscripciones.show', $orden).' → «Abrir formulario del cliente» → pestaña Pago.');

        if ($total !== 400000) {
            $this->command?->warn('Ojo: el total no es $400.000 (lo transferido). Revisa el código de descuento antes de confirmar.');
        }
    }

    /** Runs the request through the app like the portal would; anything but a redirect is a rejection. */
    private function enviar(string $url, array $datos): void
    {
        $respuesta = app(Kernel::class)->handle(Request::create($url, 'POST', $datos));

        if (! $respuesta->isRedirection()) {
            $texto = trim(preg_replace('/\s+/', ' ', strip_tags((string) $respuesta->getContent())));

            throw new RuntimeException("Rechazado ({$respuesta->getStatusCode()}) en {$url}: ".mb_substr($texto, 0, 400));
        }
    }
}
