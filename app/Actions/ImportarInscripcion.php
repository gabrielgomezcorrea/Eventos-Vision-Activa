<?php

namespace App\Actions;

use App\Enums\OrderKind;
use App\Enums\Permiso;
use App\Exceptions\CodigoNoAplicable;
use App\Exceptions\ComprobanteNoAceptado;
use App\Exceptions\CuposInsuficientes;
use App\Exceptions\ImportacionDetenida;
use App\Exceptions\OrdenNoConfirmable;
use App\Mail\AvisoComprobantePorRevisar;
use App\Mail\AvisoInscripcionConfirmada;
use App\Models\AccessType;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\MagicLink;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Payment;
use App\Models\User;
use App\Support\Auditor;
use App\Support\CodigoDeDescuento;
use App\Support\DestinatariosDeAvisos;
use App\Support\Forms\ProgramFormField;
use App\Support\ReglasDeContacto;
use App\Support\Rut;
use Carbon\Carbon;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports an enrollment that arrived by email (form + proof) as if the client had
 * filled the portal: same endpoints, same validation, same confirmation. It stops
 * where the client stops: confirmed order, proof waiting for Accounting.
 *
 * Everything runs in one transaction; any problem throws ImportacionDetenida and
 * nothing is saved. The discount code is decided by the system from the amount
 * transferred, never by the agent (docs/superpowers/specs/2026-11-11-importar-inscripciones-spec.md).
 */
class ImportarInscripcion
{
    public function __construct(
        private readonly CrearInscripcionDesdePanel $crear,
        private readonly EmitirEnlaceDeAcceso $emitir,
        private readonly ConfirmarOrden $confirmar,
        private readonly ConfirmarConjunto $confirmarConjunto,
        private readonly RegistrarComprobante $registrarComprobante,
        private readonly RegistrarPagoACredito $registrarCredito,
        private readonly AplicarCodigoDeDescuento $aplicarCodigo,
    ) {}

    /** The user must exist, be active and be able to create enrollments. */
    public static function usuarioValido(string $correo): User
    {
        $usuario = User::query()->where('email', mb_strtolower(trim($correo)))->first();

        if ($usuario === null || ! $usuario->is_active || ! $usuario->can(Permiso::GestionarOrdenes->value)) {
            throw ImportacionDetenida::porque("El usuario «{$correo}» no existe, está inactivo o no puede crear inscripciones.");
        }

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     *
     * @throws ImportacionDetenida
     */
    public function __invoke(array $datos, User $usuario, bool $simular = false): array
    {
        if (! $usuario->is_active || ! $usuario->can(Permiso::GestionarOrdenes->value)) {
            throw ImportacionDetenida::porque("El usuario «{$usuario->email}» no existe, está inactivo o no puede crear inscripciones.");
        }

        $this->validarEstructura($datos);

        $evento = Event::query()->where('slug', $datos['event'])->first()
            ?? throw ImportacionDetenida::porque("No existe el evento «{$datos['event']}».");

        if ($evento->estaCerrado()) {
            throw ImportacionDetenida::porque("El evento «{$evento->name}» está cerrado.");
        }

        $accesos = $this->resolverAccesos($evento, $datos['participants']);
        $pago = $this->validarPago($datos['payment']);

        $realMail = Mail::getFacadeRoot();
        if ($simular) {
            Mail::fake();
        }

        $solicitudPrevia = app()->bound('request') ? app('request') : null;

        try {
            $resultado = null;

            try {
                DB::transaction(function () use ($datos, $usuario, $simular, $evento, $accesos, &$pago, &$resultado): void {
                    $resultado = $this->importar($datos, $usuario, $simular, $evento, $accesos, $pago);

                    if ($simular) {
                        throw new SimulacionTerminada;
                    }
                });
            } catch (SimulacionTerminada) {
                // Expected: a simulation always rolls back.
            }
        } finally {
            if ($simular) {
                Mail::swap($realMail);
            }

            if ($solicitudPrevia !== null) {
                app()->instance('request', $solicitudPrevia);
                Facade::clearResolvedInstance('request');
            }

            if ($pago['tmp'] !== null) {
                @unlink($pago['tmp']);
            }
        }

        if (! $simular) {
            $this->avisar($resultado['_ordenes'], $resultado['_pagos']);
        }

        unset($resultado['_ordenes'], $resultado['_pagos']);

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<int, AccessType>  $accesos
     * @param  array<string, mixed>  $pago
     * @return array<string, mixed>
     */
    private function importar(array $datos, User $usuario, bool $simular, Event $evento, array $accesos, array $pago): array
    {
        $warnings = [];
        $actor = $usuario->name;
        $correo = mb_strtolower(str_replace(' ', '', trim((string) ($datos['responsible']['responsible_email'] ?? ''))));

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw ImportacionDetenida::porque('El correo del responsable no es válido.');
        }

        // 3. Request + draft, without mailing the client.
        [$borrador, $nueva] = ($this->crear)($evento, $correo, $usuario, $this->emitir, avisarAlCliente: false, actorLabel: $actor);

        if (! $nueva) {
            $folio = $borrador->number !== null ? "folio {$borrador->number}" : 'borrador sin folio';

            throw ImportacionDetenida::porque("Ya existe una inscripción viva de «{$correo}» en este evento ({$folio}).");
        }

        $borrador->forceFill([
            'kind' => OrderKind::Institucional,
            'group_id' => OrderGroup::paraResponsable($evento, $correo)->getKey(),
        ])->save();

        Auditor::registrar($borrador, 'orden.importada', comentario: $datos['note'] ?? null, actorLabel: $actor);

        [, $token] = MagicLink::emitir($evento, $correo, $borrador);

        // 4. Responsible, written directly (the form demands a mobile phone).
        $this->guardarResponsable($borrador, $datos['responsible'], $correo, $warnings);

        // 5. Schools.
        $ids = [];
        foreach ($datos['schools'] as $colegio) {
            $antes = $borrador->establishments()->pluck('establishments.id')->all();

            $this->enviar(route('inscripcion.establecimientos.agregar', $token), [
                'name' => $colegio['name'] ?? null,
                'rbd' => $colegio['rbd'] ?? null,
                'region' => $colegio['region'] ?? null,
                'commune' => $colegio['commune'] ?? null,
            ], 'Colegio «'.($colegio['name'] ?? '').'»');

            $nuevo = array_values(array_diff($borrador->establishments()->pluck('establishments.id')->all(), $antes));
            $ids[(string) $colegio['key']] = $nuevo[0] ?? throw ImportacionDetenida::porque('No se pudo guardar el colegio «'.($colegio['name'] ?? '').'».');
        }

        // 6. Payer.
        $this->enviar(route('inscripcion.pagador.guardar', $token), $datos['payer'], 'Datos de facturación');

        // 7. Participants.
        $cargos = $evento->cargosDeParticipante();
        foreach ($datos['participants'] as $indice => $p) {
            $cargo = $p['position'] ?? null;
            $enLista = in_array($cargo, $cargos, true);
            $nombre = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));

            if (! $enLista) {
                $warnings[] = "El cargo «{$cargo}» no está en la lista: se guardó como Otro.";
            }

            $rut = $p['rut'] ?? null;
            if (filled($rut) && ! Rut::esValido($rut)) {
                $warnings[] = "El RUT «{$rut}» de {$nombre} no es válido: quedó sin RUT.";
                $rut = null;
            }

            $this->enviar(route('inscripcion.participantes.agregar', $token), [
                'first_name' => $p['first_name'] ?? null,
                'last_name' => $p['last_name'] ?? null,
                'rut' => $rut,
                'position' => $enLista ? $cargo : ProgramFormField::CARGO_OTRO,
                'position_otro' => $enLista ? null : $cargo,
                'email' => $p['email'] ?? null,
                'access_type_id' => $accesos[$indice]->getKey(),
                'establishment_id' => $ids[(string) ($p['school'] ?? array_key_first($ids))] ?? null,
            ], "Participante {$nombre}");
        }

        // 8. Missing pieces.
        $borrador = $this->recargar($borrador);
        $faltantes = $this->confirmar->faltantes($borrador);

        if ($faltantes !== []) {
            throw ImportacionDetenida::porque(...array_column($faltantes, 'mensaje'));
        }

        // 9. Code + confirmation.
        $codigo = $this->elegirCodigo($borrador, $evento, $datos, $pago, $correo, $actor, $warnings);

        $borrador = $this->recargar($borrador);
        $ordenes = $this->confirmarConCodigo($borrador, $codigo, $correo, $actor);

        // 10. Payment.
        $pagos = $this->registrarPago($ordenes, $datos['payment'], $pago, $usuario, $simular);

        $total = (int) array_sum(array_map(fn (Order $o): int => (int) $o->total, $ordenes));

        return [
            'ok' => true,
            'simulated' => $simular,
            'orders' => array_map(fn (Order $o): array => [
                'number' => $simular ? null : $o->number,
                'school' => $o->establishments->first()?->name,
                'participants' => $o->participantesVigentes()->count(),
                'total' => (int) $o->total,
                'panel_url' => $simular ? null : route('inscripciones.show', $o),
            ], $ordenes),
            'total' => $total,
            'transferred' => $pago['amount'],
            'code_applied' => $codigo?->code,
            'warnings' => array_values(array_unique($warnings)),
            'problems' => [],
            '_ordenes' => $ordenes,
            '_pagos' => $pagos,
        ];
    }

    /** @param  array<string, mixed>  $datos */
    private function validarEstructura(array $datos): void
    {
        $problemas = [];

        foreach (['event', 'responsible', 'schools', 'payer', 'participants', 'payment'] as $campo) {
            if (blank($datos[$campo] ?? null)) {
                $problemas[] = "Falta el campo «{$campo}».";
            }
        }

        if ($problemas === []) {
            $claves = array_map(fn ($c) => (string) ($c['key'] ?? ''), (array) $datos['schools']);

            if (in_array('', $claves, true) || count(array_unique($claves)) !== count($claves)) {
                $problemas[] = 'Cada colegio necesita una «key» distinta.';
            }

            foreach ((array) $datos['participants'] as $p) {
                $colegio = (string) ($p['school'] ?? '');

                if ($colegio === '' ? count($claves) > 1 : ! in_array($colegio, $claves, true)) {
                    $problemas[] = 'El participante '.trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? '')).' no indica un colegio válido.';
                }
            }
        }

        if ($problemas !== []) {
            throw new ImportacionDetenida($problemas);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $participantes
     * @return array<int, AccessType> indexed like the participants
     */
    private function resolverAccesos(Event $evento, array $participantes): array
    {
        $activos = $evento->accessTypes()->where('is_active', true)->orderBy('position')->get();
        $norma = fn (string $t): string => mb_strtolower(Str::ascii(trim($t)));
        $porNombre = $activos->keyBy(fn (AccessType $a): string => $norma($a->name));

        $resueltos = [];
        $problemas = [];

        foreach ($participantes as $i => $p) {
            $acceso = $porNombre->get($norma((string) ($p['access'] ?? '')));

            if ($acceso === null) {
                $problemas[] = 'El acceso «'.($p['access'] ?? '').'» no existe en el evento. Válidos: '.$activos->pluck('name')->implode(', ').'.';

                continue;
            }

            $resueltos[$i] = $acceso;
        }

        if ($problemas !== []) {
            throw new ImportacionDetenida(array_values(array_unique($problemas)));
        }

        return $resueltos;
    }

    /**
     * @param  array<string, mixed>  $pago
     * @return array{method: string, amount: int|null, paid_on: string|null, tmp: string|null, name: string|null}
     */
    private function validarPago(array $pago): array
    {
        $metodo = $pago['method'] ?? null;

        if (! in_array($metodo, ['transfer', 'credit'], true)) {
            throw ImportacionDetenida::porque('El método de pago debe ser «transfer» o «credit».');
        }

        $salida = ['method' => $metodo, 'amount' => null, 'paid_on' => null, 'tmp' => null, 'name' => null];

        if ($metodo === 'credit') {
            return $salida;
        }

        $problemas = [];
        $monto = $pago['amount'] ?? null;
        $fecha = $pago['paid_on'] ?? null;
        $hoy = Carbon::now('America/Santiago')->toDateString();

        if (! is_numeric($monto) || (int) $monto < 1 || (int) $monto != $monto) {
            $problemas[] = 'El monto transferido debe ser un número entero mayor a cero.';
        }

        if (! is_string($fecha) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || Carbon::hasFormat($fecha, 'Y-m-d') === false) {
            $problemas[] = 'La fecha de transferencia debe venir como AAAA-MM-DD.';
        } elseif ($fecha > $hoy) {
            $problemas[] = 'La fecha de transferencia no puede ser futura.';
        }

        $base64 = $pago['file']['base64'] ?? null;
        $binario = is_string($base64) ? base64_decode($base64, true) : false;

        if ($binario === false || $binario === '') {
            $problemas[] = 'Falta el comprobante o no se pudo leer.';
        } elseif (strlen($binario) > 10 * 1024 * 1024) {
            $problemas[] = 'El comprobante no puede superar los 10 MB.';
        }

        if ($problemas !== []) {
            throw new ImportacionDetenida($problemas);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $binario);

        $nombre = basename((string) ($pago['file']['name'] ?? 'comprobante'));
        $archivo = new UploadedFile($tmp, $nombre, mime_content_type($tmp) ?: null, null, true);

        $validador = Validator::make(['proof' => $archivo], [
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:10240'],
        ]);

        if ($validador->fails()) {
            @unlink($tmp);

            throw ImportacionDetenida::porque('El comprobante debe ser un PDF o una foto (JPG, PNG, WEBP o HEIC).');
        }

        return [...$salida, 'amount' => (int) $monto, 'paid_on' => $fecha, 'tmp' => $tmp, 'name' => $nombre];
    }

    /**
     * @param  array<string, mixed>  $responsable
     * @param  array<int, string>  $warnings
     */
    private function guardarResponsable(Order $orden, array $responsable, string $correo, array &$warnings): void
    {
        $entrada = ReglasDeContacto::limpiar($responsable);
        $entrada['responsible_email'] = $correo;

        $telefono = $entrada['responsible_phone'] ?? null;
        if (filled($telefono)) {
            $malo = Validator::make(['t' => $telefono], ['t' => ReglasDeContacto::telefono(false)])->fails();

            if ($malo) {
                $warnings[] = "El teléfono del responsable «{$telefono}» no es un celular válido: se guardó sin teléfono.";
                $entrada['responsible_phone'] = null;
            }
        }

        $cargo = $entrada['responsible_position'] ?? null;
        if (filled($cargo) && ! in_array($cargo, ProgramFormField::CARGOS, true)) {
            $warnings[] = "El cargo «{$cargo}» no está en la lista: se guardó como Otro.";
            $entrada['responsible_position'] = ProgramFormField::CARGO_OTRO;
            $entrada['responsible_position_otro'] = $cargo;
        }

        $validador = Validator::make($entrada, [
            'responsible_name' => ReglasDeContacto::nombres(),
            'responsible_lastname' => ReglasDeContacto::apellidos(),
            'responsible_position' => ReglasDeContacto::cargo(),
            'responsible_position_otro' => ReglasDeContacto::cargoOtro('responsible_position'),
            'responsible_email' => ReglasDeContacto::correo(),
            'responsible_phone' => ReglasDeContacto::telefono(false),
        ], [], [
            'responsible_name' => 'nombre del responsable',
            'responsible_lastname' => 'apellidos del responsable',
            'responsible_position' => 'cargo del responsable',
            'responsible_position_otro' => 'cargo del responsable',
            'responsible_email' => 'correo del responsable',
            'responsible_phone' => 'teléfono del responsable',
        ]);

        if ($validador->fails()) {
            throw new ImportacionDetenida(array_values($validador->errors()->all()));
        }

        $guardar = ReglasDeContacto::normalizar($validador->validated(), [
            'responsible_name' => 'nombre',
            'responsible_lastname' => 'nombre',
            'responsible_position' => 'cargo',
            'responsible_email' => 'correo',
            'responsible_phone' => 'telefono',
        ]);
        unset($guardar['responsible_email']); // The order was born with this email.

        $orden->update($guardar);
    }

    /**
     * Runs the request through the app exactly as the portal would; anything but a
     * redirect is a rejection and carries the response text as the problem.
     *
     * @param  array<string, mixed>  $datos
     */
    private function enviar(string $url, array $datos, string $contexto): void
    {
        $respuesta = app(Kernel::class)->handle(Request::create($url, 'POST', $datos));

        if ($respuesta->isRedirection()) {
            return;
        }

        $texto = trim(preg_replace('/\s+/', ' ', strip_tags((string) $respuesta->getContent())));

        throw ImportacionDetenida::porque("{$contexto}: rechazado por el sistema (".$respuesta->getStatusCode().'). '.mb_substr($texto, 0, 400));
    }

    private function recargar(Order $orden): Order
    {
        return $orden->fresh()->load(['event', 'establishments', 'participants.accessType', 'payerEntity']);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $pago
     * @param  array<int, string>  $warnings
     */
    private function elegirCodigo(Order $borrador, Event $evento, array $datos, array $pago, string $correo, string $actor, array &$warnings): ?DiscountCode
    {
        $pista = filled($datos['code_hint'] ?? null)
            ? $evento->discountCodes()->where('code', CodigoDeDescuento::normalizar($datos['code_hint']))->first()
            : null;
        $impedimentoDePista = $pista?->impedimentoPara($borrador);

        if ($pago['method'] === 'credit') {
            if ($pista === null && filled($datos['code_hint'] ?? null)) {
                throw ImportacionDetenida::porque("El código «{$datos['code_hint']}» no existe en este evento.");
            }

            if ($impedimentoDePista !== null) {
                throw ImportacionDetenida::porque("{$pista->code} no se puede usar: {$impedimentoDePista}");
            }

            return $pista;
        }

        $amount = $pago['amount'];

        // Candidates: no code, the hint, then the rest of the event's codes.
        $candidatos = [null];
        $demas = $evento->discountCodes()->orderBy('id')->get();
        $ordenados = $pista !== null ? $demas->sortBy(fn ($c) => $c->is($pista) ? 0 : 1)->values() : $demas;

        foreach ($ordenados as $codigo) {
            if ($codigo->impedimentoPara($borrador) === null) {
                $candidatos[] = $codigo;
            }
        }

        $totales = [];
        foreach ($candidatos as $codigo) {
            $totales[$codigo?->code ?? ''] = $this->totalDelCandidato($borrador, $codigo, $correo, $actor);
        }

        if ($totales[''] === $amount) {
            if ($pista !== null) {
                $warnings[] = "El documento menciona el código {$pista->code}, pero el monto transferido calza sin descuento: no se aplicó.";
            }

            return null;
        }

        $calzan = array_values(array_filter($candidatos, fn (?DiscountCode $c): bool => $c !== null && $totales[$c->code] === $amount));

        if (count($calzan) === 1) {
            if ($pista === null || ! $calzan[0]->is($pista)) {
                $warnings[] = "Se aplicó el código {$calzan[0]->code} porque deja el total igual a lo transferido.";
            }

            return $calzan[0];
        }

        if (count($calzan) > 1) {
            foreach ($calzan as $c) {
                if ($pista !== null && $c->is($pista)) {
                    return $c;
                }
            }

            throw ImportacionDetenida::porque('Hay varios códigos que calzan: '.implode(', ', array_map(fn ($c) => $c->code, $calzan)).'. Revisa cuál corresponde.');
        }

        $detalle = ['sin código '.$this->pesos($totales[''])];
        foreach ($candidatos as $c) {
            if ($c !== null) {
                $detalle[] = "con {$c->code} ".$this->pesos($totales[$c->code]);
            }
        }

        $problemas = ['Lo transferido ('.$this->pesos($amount).') no calza con el total: '.implode('; ', $detalle).'.'];

        if ($impedimentoDePista !== null) {
            $problemas[] = "{$pista->code} no se puede usar: {$impedimentoDePista}";
        } elseif ($pista === null && filled($datos['code_hint'] ?? null)) {
            $problemas[] = "El código «{$datos['code_hint']}» no existe en este evento.";
        }

        throw new ImportacionDetenida($problemas);
    }

    /**
     * Confirms for real inside a savepoint and rolls back: the only honest way to
     * know the total (quantity discount and rounding are per school at confirmation).
     */
    private function totalDelCandidato(Order $borrador, ?DiscountCode $codigo, string $correo, string $actor): int
    {
        $real = Mail::getFacadeRoot();
        Mail::fake();

        try {
            DB::transaction(function () use ($borrador, $codigo, $correo, $actor): void {
                $copia = $this->recargar($borrador);
                $ordenes = $this->confirmarConCodigo($copia, $codigo, $correo, $actor);

                throw new PruebaDeCandidato((int) array_sum(array_map(fn (Order $o): int => (int) $o->total, $ordenes)));
            });
        } catch (PruebaDeCandidato $prueba) {
            return $prueba->total;
        } finally {
            Mail::swap($real);
        }

        return -1; // Unreachable: the closure always throws.
    }

    /** @return array<int, Order> */
    private function confirmarConCodigo(Order $borrador, ?DiscountCode $codigo, string $correo, string $actor): array
    {
        try {
            if ($codigo !== null) {
                ($this->aplicarCodigo)($borrador, $codigo->code, $correo);
                $borrador = $this->recargar($borrador);
            }

            $ordenes = $borrador->establishments->count() > 1
                ? ($this->confirmarConjunto)($borrador, actorLabel: $actor)
                : [($this->confirmar)($borrador, actorLabel: $actor, enviarCorreo: false)];
        } catch (CodigoNoAplicable|OrdenNoConfirmable|CuposInsuficientes $e) {
            throw ImportacionDetenida::porque($e->getMessage());
        }

        return array_map(fn (Order $o): Order => $o->fresh()->load(['event', 'establishments', 'payerEntity']), $ordenes);
    }

    /**
     * @param  array<int, Order>  $ordenes
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $pago
     * @return array<int, Payment>
     */
    private function registrarPago(array $ordenes, array $entrada, array $pago, User $usuario, bool $simular): array
    {
        $pendientes = array_values(array_filter($ordenes, fn (Order $o): bool => $o->saldo() > 0));
        $pagos = [];

        if ($pago['method'] === 'transfer') {
            $suma = (int) array_sum(array_map(fn (Order $o): int => $o->saldo(), $pendientes));

            if ($suma !== $pago['amount']) {
                throw ImportacionDetenida::porque('Lo transferido ('.$this->pesos($pago['amount']).') no es igual a lo que se debe ('.$this->pesos($suma).').');
            }

            if ($simular) {
                return [];
            }

            $archivo = new UploadedFile($pago['tmp'], $pago['name'], mime_content_type($pago['tmp']) ?: null, null, true);

            foreach ($pendientes as $orden) {
                try {
                    $pagos[] = ($this->registrarComprobante)($orden, [
                        'amount' => $orden->saldo(),
                        'paid_on' => $pago['paid_on'],
                        'reference' => $orden->number,
                    ], $archivo, $usuario);
                } catch (ComprobanteNoAceptado $e) {
                    throw ImportacionDetenida::porque($e->getMessage());
                }
            }

            return $pagos;
        }

        foreach ($pendientes as $orden) {
            try {
                $pagos[] = ($this->registrarCredito)($orden, $entrada['purchase_order'] ?? null, $usuario->name);
            } catch (ComprobanteNoAceptado $e) {
                throw ImportacionDetenida::porque($e->getMessage());
            }
        }

        return $pagos;
    }

    /**
     * Internal notices after the commit, like the portal: one per confirmation and
     * one per payment. A failure to queue never undoes the import.
     *
     * @param  array<int, Order>  $ordenes
     * @param  array<int, Payment>  $pagos
     */
    private function avisar(array $ordenes, array $pagos): void
    {
        try {
            $confirmadas = collect($ordenes)->each->load(['event', 'establishments', 'payerEntity']);

            foreach (DestinatariosDeAvisos::para('notify_confirmed_orders') as $destinatario) {
                Mail::to($destinatario)->queue(new AvisoInscripcionConfirmada($confirmadas));
            }

            foreach ($pagos as $pago) {
                foreach (DestinatariosDeAvisos::para('notify_payment_proofs') as $destinatario) {
                    Mail::to($destinatario)->queue(new AvisoComprobantePorRevisar($pago));
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function pesos(int $monto): string
    {
        return '$'.number_format($monto, 0, ',', '.');
    }
}

/** Internal: aborts a simulation so its transaction rolls back. */
final class SimulacionTerminada extends \RuntimeException {}

/** Internal: carries a candidate's real total out of a savepoint that must roll back. */
final class PruebaDeCandidato extends \RuntimeException
{
    public function __construct(public readonly int $total)
    {
        parent::__construct('candidate');
    }
}
