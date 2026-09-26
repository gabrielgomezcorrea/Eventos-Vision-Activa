<?php

namespace App\Http\Controllers\Publico;

use App\Exceptions\EnlaceNoUtilizable;
use App\Http\Controllers\Controller;
use App\Mail\ProgramaDelEvento;
use App\Models\Event;
use App\Models\ProgramRequest;
use App\Support\Forms\ProgramFormField;
use App\Support\ReglasDeContacto;
use App\Support\Texto;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Formulario público de solicitud de programa.
 *
 * Es deliberadamente stateless: se sirve y se procesa sin sesión ni cookies,
 * porque va embebido en sitios de terceros donde las cookies están bloqueadas.
 * En vez de CSRF por sesión, la protección es rate limiting más honeypot.
 *
 * Las tres capas son invisibles para quien llena el formulario de verdad:
 * honeypot, tiempo mínimo de llenado y tope de envíos por dirección de correo.
 * Ninguna le pide nada al usuario, porque un captcha visible con este perfil de
 * usuario cuesta inscripciones reales.
 */
class ProgramRequestController extends Controller
{
    public function mostrar(Event $event, bool $embed = false): View
    {
        throw_unless($event->admiteInscripciones(), EnlaceNoUtilizable::eventoNoDisponible($event));

        return view('publico.programa', [
            'event' => $event,
            'campos' => $event->camposDelFormulario(),
            'opcionesInteres' => $event->opcionesDeInteres(),
            'embed' => $embed,
            'valores' => [],
            'enviado' => false,
            'sello' => self::sellarInstante(),
            'utm' => self::utm(request()),
        ]);
    }

    public function mostrarEmbebido(Event $event): View
    {
        return $this->mostrar($event, embed: true);
    }

    public function guardar(Request $request, Event $event, bool $embed = false): View
    {
        abort_unless($event->admiteInscripciones(), 404);

        $campos = $event->camposDelFormulario();

        // Honeypot: un bot rellena todo, una persona no ve este campo.
        // Igual que el resto de las guardas, responde con la pantalla de éxito:
        // decirle a un bot que fue detectado solo le enseña a evitarlo.
        if (filled($request->input('website'))) {
            return $this->vistaDeExito($event, $campos, $embed);
        }

        if ($this->llegoDemasiadoRapido($request)) {
            return $this->vistaDeExito($event, $campos, $embed);
        }

        if ($this->excedeElTopePorCorreo($request)) {
            return $this->vistaDeExito($event, $campos, $embed);
        }

        $validator = Validator::make(
            ReglasDeContacto::limpiar($request->all()),
            $this->reglas($campos, $event),
            $this->mensajes($campos),
            $this->etiquetas($campos),
        );

        if ($validator->fails()) {
            return view('publico.programa', [
                'event' => $event,
                'campos' => $campos,
                'opcionesInteres' => $event->opcionesDeInteres(),
                'embed' => $embed,
                'valores' => $request->except(['website']),
                'enviado' => false,
                'sello' => self::sellarInstante(),
                'utm' => self::utm($request),
                'errores' => $validator->errors(),
            ]);
        }

        $datos = $validator->validated();
        $solicitud = $this->crearSolicitud($event, $campos, $datos, $request);

        Mail::to($solicitud->email)->queue(new ProgramaDelEvento($solicitud));

        // Queda la fecha para que Coordinación vea a quién ya se le mandó el
        // programa y a quién no. Sin esto, el listado no distingue una solicitud
        // recién llegada de una que ya tuvo respuesta.
        $solicitud->update(['program_sent_at' => now()]);

        return $this->vistaDeExito($event, $campos, $embed);
    }

    public function guardarEmbebido(Request $request, Event $event): View
    {
        return $this->guardar($request, $event, embed: true);
    }

    /**
     * Parámetros de campaña del enlace, recortados y sin basura.
     *
     * El jefe arma el enlace con `?utm_source=instagram&utm_campaign=seminario`
     * y esto es lo que después responde de dónde salió cada inscrito. Se
     * limitan a 120 caracteres porque son etiquetas, no texto libre.
     *
     * @return array<string, string>
     */
    private static function utm(Request $request): array
    {
        return collect(['utm_source', 'utm_medium', 'utm_campaign'])
            ->mapWithKeys(fn (string $clave): array => [
                $clave => mb_substr(trim((string) $request->input($clave, '')), 0, 120),
            ])
            ->filter(fn (string $valor): bool => $valor !== '')
            ->all();
    }

    /**
     * Instante en que se dibujó el formulario, firmado para que no se pueda
     * falsear. Va cifrado y no en claro porque un bot que lee el HTML podría
     * mandar una marca vieja y saltarse el control.
     */
    private static function sellarInstante(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Un bot completa y envía en milisegundos. Sin sello válido no se bloquea:
     * puede ser una pestaña vieja o un navegador raro, y dejar fuera a una
     * persona real cuesta más que dejar pasar a un bot.
     */
    private function llegoDemasiadoRapido(Request $request): bool
    {
        $sello = $request->input('sello');

        if (! is_string($sello) || $sello === '') {
            return false;
        }

        try {
            $dibujado = (int) Crypt::decryptString($sello);
        } catch (DecryptException) {
            return false;
        }

        return (now()->getTimestamp() - $dibujado) < config('formulario_publico.segundos_minimos');
    }

    /**
     * Tope por dirección de correo, además del tope por IP que aplica la ruta.
     * Sin esto el formulario sirve para llenarle la bandeja a un tercero desde
     * muchas IP distintas.
     */
    private function excedeElTopePorCorreo(Request $request): bool
    {
        $correo = mb_strtolower(trim((string) $request->input('email')));

        if ($correo === '') {
            return false;
        }

        $clave = 'programa:correo:'.sha1($correo);
        $max = (int) config('formulario_publico.max_por_correo');

        if (RateLimiter::tooManyAttempts($clave, $max)) {
            return true;
        }

        RateLimiter::hit($clave, (int) config('formulario_publico.ventana_minutos') * 60);

        return false;
    }

    /**
     * @param  array<int, ProgramFormField>  $campos
     * @param  array<string, mixed>  $datos
     */
    private function crearSolicitud(Event $event, array $campos, array $datos, Request $request): ProgramRequest
    {
        $base = [];
        $extra = [];

        foreach ($campos as $campo) {
            $valor = $datos[$campo->key] ?? null;

            // Se guarda el cargo escrito, no la palabra "Otro". La columna es la
            // que después se filtra, y "Otro" no dice nada.
            if ($valor === ProgramFormField::CARGO_OTRO && filled($datos[$campo->key.'_otro'] ?? null)) {
                $valor = $datos[$campo->key.'_otro'];
            }

            $valor = self::normalizar($campo, $valor);

            if ($campo->esCampoBase()) {
                $base[$campo->key] = $valor;
            } elseif ($valor !== null) {
                $extra[$campo->key] = $valor;
            }
        }

        // La etiqueta del acceso se guarda además del id, para que el registro
        // siga siendo legible si el acceso cambia de nombre o se elimina.
        $interesLabel = null;
        if (! empty($base['access_type_id'])) {
            $interesLabel = $event->opcionesDeInteres()[$base['access_type_id']] ?? null;
        }

        return $event->programRequests()->create([
            ...$base,
            'interest_label' => $interesLabel,
            'extra' => $extra ?: null,
            // El instante en que pidió el programa. Ya no hay casilla que
            // marcar: pedirle a alguien que autorice recibir justo lo que acaba
            // de solicitar es una traba sin propósito. Lo que queda registrado
            // es la solicitud, no una autorización para comunicaciones ajenas a
            // ella; si algún día se hace marketing con estos correos, eso pide
            // su propio consentimiento y su propia casilla.
            'consented_at' => now(),
            // Marcar la casilla es lo único que autoriza usar este correo para
            // campañas. Sin ella, el contacto solo sirve para esta solicitud.
            'marketing_consented_at' => $request->boolean('marketing') ? now() : null,
            'source_url' => $request->headers->get('referer'),
            ...self::utm($request),
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Deja el valor como debe quedar guardado, sin avisarle a nadie.
     *
     * Se corrige en vez de rechazar: a quien escribe "JUAN carlos" no hay nada
     * que explicarle, y devolverle el formulario con un error por mayúsculas
     * sería una traba absurda.
     */
    private static function normalizar(ProgramFormField $campo, mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        return match (true) {
            $campo->type === 'email' => Texto::correo($valor),
            $campo->type === 'tel' => Texto::telefono($valor),
            in_array($campo->key, ['first_name', 'last_name', 'institution', 'position'], true) => Texto::capitalizar($valor),
            default => Texto::limpiar($valor),
        };
    }

    /**
     * @param  array<int, ProgramFormField>  $campos
     * @return array<string, mixed>
     */
    private function reglas(array $campos, Event $event): array
    {
        $reglas = [];

        foreach ($campos as $campo) {
            if ($campo->key === 'access_type_id') {
                $ids = array_keys($event->opcionesDeInteres());
                $reglas[$campo->key] = [
                    $campo->required ? 'required' : 'nullable',
                    'integer',
                    'in:'.implode(',', $ids ?: [0]),
                ];

                continue;
            }

            $reglas[$campo->key] = $campo->reglas();

            // "Otro" sin escribir cuál no sirve para nada: el campo existe
            // justo para los cargos que no están en la lista.
            if ($campo->type === 'select' && in_array(ProgramFormField::CARGO_OTRO, $campo->options, true)) {
                $reglas[$campo->key.'_otro'] = ReglasDeContacto::cargoOtro($campo->key);
            }
        }

        $reglas['marketing'] = ['nullable', 'boolean'];

        return $reglas;
    }

    /**
     * Los mensajes se arman solos con el nombre del campo.
     *
     * Así quien configura el formulario no tiene que escribir un texto de error
     * por cada pregunta: pone el nombre del campo y el sistema dice "Por favor
     * escribe el nombre del establecimiento".
     *
     * @param  array<int, ProgramFormField>  $campos
     * @return array<string, string>
     */
    private function mensajes(array $campos = []): array
    {
        $mensajes = [
            'access_type_id.in' => 'Elige una de las opciones de la lista.',
            'access_type_id.required' => 'Elige una de las opciones de la lista.',
            'position_otro.required_if' => 'Escribe cuál es tu cargo.',
        ];

        foreach ($campos as $campo) {
            $nombre = mb_strtolower($campo->label);

            $mensajes[$campo->key.'.required'] = match ($campo->type) {
                'select' => "Elige {$nombre}.",
                default => "Por favor escribe {$nombre}.",
            };
        }

        return $mensajes;
    }

    /**
     * @param  array<int, ProgramFormField>  $campos
     * @return array<string, string>
     */
    private function etiquetas(array $campos): array
    {
        return collect($campos)
            ->mapWithKeys(fn (ProgramFormField $c) => [$c->key => mb_strtolower($c->label)])
            ->all();
    }

    /** @param  array<int, ProgramFormField>  $campos */
    private function vistaDeExito(Event $event, array $campos, bool $embed): View
    {
        return view('publico.programa', [
            'event' => $event,
            'campos' => $campos,
            'opcionesInteres' => $event->opcionesDeInteres(),
            'embed' => $embed,
            'valores' => [],
            'enviado' => true,
            'sello' => self::sellarInstante(),
            'utm' => [],
        ]);
    }
}
