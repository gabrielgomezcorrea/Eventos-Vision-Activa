@php
    $errores = $errores ?? new \Illuminate\Support\MessageBag;
    $accion = $embed
        ? route('publico.programa.embed.guardar', $event)
        : route('publico.programa.guardar', $event);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $event->name }}</title>

    {{-- Previsualización al compartir el enlace en redes y en WhatsApp. Sin
         esto aparece una tarjeta gris sin título y baja el clic. --}}
    @php
        $resumen = $event->description
            ? \Illuminate\Support\Str::limit(strip_tags($event->description), 160)
            : 'Completa el formulario y recibe el programa en tu correo.';
    @endphp
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $event->name }}">
    <meta property="og:description" content="{{ $resumen }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $event->name }}">
    <meta name="twitter:description" content="{{ $resumen }}">
    <style>
        :root {
            --primario: #084887;
            --primario-oscuro: #063a6d;
            --acento: #F58A07;
            --texto: #1F2937;
            --texto-suave: #667085;
            --borde: #D0D5DD;
            --fondo: {{ $embed ? 'transparent' : '#F7F5FB' }};
            --superficie: #ffffff;
            --error: #B42318;
            --error-suave: #FDECEC;
            --exito: #2E7D32;
            --exito-suave: #EAF6ED;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--fondo);
            color: var(--texto);
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .envoltura {
            max-width: 640px;
            margin: 0 auto;
            padding: {{ $embed ? '0' : '32px 16px' }};
        }
        .tarjeta {
            background: var(--superficie);
            border: {{ $embed ? 'none' : '1px solid var(--borde)' }};
            border-radius: {{ $embed ? '0' : '12px' }};
            padding: {{ $embed ? '4px' : '28px' }};
        }
        h1 { font-size: 1.35rem; margin: 0 0 6px; line-height: 1.3; }
        .intro { color: var(--texto-suave); margin: 0 0 22px; font-size: .95rem; }
        .campo { margin-bottom: 16px; }
        label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: 6px; }
        .requerido { color: var(--error); margin-left: 2px; }
        input[type=text], input[type=email], input[type=tel], input[type=number], select, textarea {
            width: 100%;
            padding: 11px 12px;
            font: inherit;
            color: inherit;
            background: #fff;
            border: 1px solid var(--borde);
            border-radius: 8px;
        }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid var(--primario);
            outline-offset: 1px;
            border-color: var(--primario);
        }
        .ayuda { color: var(--texto-suave); font-size: .82rem; margin-top: 5px; }
        .error-campo { color: var(--error); font-size: .85rem; margin-top: 5px; }
        [aria-invalid=true] { border-color: var(--error); }
        button {
            width: 100%; padding: 13px 18px;
            font: inherit; font-weight: 600; color: #fff;
            background: var(--primario); border: 0; border-radius: 8px; cursor: pointer;
        }
        button:hover { background: var(--primario-oscuro); }
        button:disabled { opacity: .62; cursor: progress; }
        .aviso { border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; font-size: .92rem; }
        .aviso-error { background: var(--error-suave); color: var(--error); }
        .aviso-exito { background: var(--exito-suave); color: var(--exito); }
        .exito h2 { font-size: 1.2rem; margin: 0 0 8px; }
        .exito p { color: var(--texto-suave); margin: 0 0 6px; }
        .casilla { display: flex; align-items: flex-start; gap: 10px; font-weight: 400; font-size: .9rem; cursor: pointer; }
    .casilla input { width: auto; margin-top: 3px; flex: 0 0 auto; }
    .legal { color: var(--texto-suave); font-size: .78rem; text-align: center; margin: 14px 0 0; line-height: 1.5; }
    .legal a { color: var(--texto-suave); }
    .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        @media (max-width: 480px) { .tarjeta { padding: {{ $embed ? '4px' : '20px 16px' }}; } }
    </style>
</head>
<body>
<div class="envoltura">
    <div class="tarjeta">

        @if ($enviado)
            <div class="exito">
                <div class="aviso aviso-exito">Solicitud recibida.</div>
                <h2>Revisa tu correo</h2>
                <p>Enviamos el programa de <strong>{{ $event->name }}</strong> a la dirección que indicaste.</p>
                <p>Si no lo ves en unos minutos, revisa la carpeta de correo no deseado.</p>
                @if ($event->contact_email)
                    <p>¿Dudas? Escríbenos a <a href="mailto:{{ $event->contact_email }}">{{ $event->contact_email }}</a>.</p>
                @endif
            </div>
        @else
            @if ($errores->isNotEmpty())
                <div class="aviso aviso-error">Revisa los campos marcados y vuelve a enviar.</div>
            @endif

            <form method="POST" action="{{ $accion }}" novalidate>
                {{-- Honeypot: invisible para personas, tentador para bots. --}}
                <div class="hp" aria-hidden="true">
                    <label for="website">No completar</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                {{-- Instante en que se dibujó el formulario, cifrado. Sirve para
                     descartar envíos instantáneos, que solo hace un bot. --}}
                <input type="hidden" name="sello" value="{{ $sello }}">

                {{-- De qué campaña vino. Viaja oculto porque el envío no
                     conserva la query del enlace. --}}
                @foreach ($utm ?? [] as $clave => $valor)
                    <input type="hidden" name="{{ $clave }}" value="{{ $valor }}">
                @endforeach

                @php
                    // Deja que el navegador ofrezca los datos que la persona ya
                    // tiene guardados: en este perfil evita la mayoria de los
                    // errores de tipeo en el correo.
                    $autocompletado = [
                        'first_name' => 'given-name',
                        'last_name' => 'family-name',
                        'email' => 'email',
                        'phone' => 'tel',
                        'position' => 'organization-title',
                        'institution' => 'organization',
                    ];
                @endphp

                @foreach ($campos as $campo)
                    @php
                        $valor = $valores[$campo->key] ?? '';
                        $auto = $autocompletado[$campo->key] ?? null;
                    @endphp
                    <div class="campo">
                        <label for="{{ $campo->key }}">
                            {{ $campo->label }}@if ($campo->required)<span class="requerido" aria-hidden="true">*</span>@endif
                        </label>

                        @if ($campo->key === 'access_type_id')
                            <select id="{{ $campo->key }}" name="{{ $campo->key }}"
                                    @if ($campo->required) aria-required="true" @endif
                                    @if ($errores->has($campo->key)) aria-invalid="true" @endif>
                                <option value="">Selecciona una opción</option>
                                @foreach ($opcionesInteres as $id => $nombre)
                                    <option value="{{ $id }}" @selected((string) $valor === (string) $id)>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        @elseif ($campo->type === 'select')
                            @php
                                $abreOtro = in_array(\App\Support\Forms\ProgramFormField::CARGO_OTRO, $campo->options, true);
                                $claveOtro = $campo->key.'_otro';
                                $valorOtro = $valores[$claveOtro] ?? '';
                            @endphp
                            <select id="{{ $campo->key }}" name="{{ $campo->key }}"
                                    @if ($abreOtro) data-abre-otro="{{ $claveOtro }}" @endif
                                    @if ($campo->required) aria-required="true" @endif
                                    @if ($errores->has($campo->key)) aria-invalid="true" @endif>
                                <option value="">Selecciona una opción</option>
                                @foreach ($campo->options as $opcion)
                                    <option value="{{ $opcion }}" @selected($valor === $opcion)>{{ $opcion === \App\Support\Forms\ProgramFormField::CARGO_OTRO ? 'Otro (especificar)' : $opcion }}</option>
                                @endforeach
                            </select>

                            @if ($abreOtro)
                                {{-- Se dibuja siempre y se oculta con JS: sin
                                     JavaScript el campo queda visible y la
                                     persona igual puede escribir su cargo. --}}
                                <div id="{{ $claveOtro }}_envoltura" style="margin-top:10px"
                                     @if ($valor !== \App\Support\Forms\ProgramFormField::CARGO_OTRO) hidden @endif>
                                    <input type="text" id="{{ $claveOtro }}" name="{{ $claveOtro }}"
                                           value="{{ $valorOtro }}" placeholder="Escribe tu cargo" maxlength="120"
                                           @if ($errores->has($claveOtro)) aria-invalid="true" @endif>
                                    @if ($errores->has($claveOtro))
                                        <div class="error-campo">{{ $errores->first($claveOtro) }}</div>
                                    @endif
                                </div>
                            @endif
                        @elseif ($campo->type === 'textarea')
                            <textarea id="{{ $campo->key }}" name="{{ $campo->key }}" rows="3"
                                      placeholder="{{ $campo->placeholder }}"
                                      @if ($campo->required) aria-required="true" @endif
                                    @if ($errores->has($campo->key)) aria-invalid="true" @endif>{{ $valor }}</textarea>
                        @else
                            <input type="{{ $campo->type }}" id="{{ $campo->key }}" name="{{ $campo->key }}"
                                   value="{{ $valor }}" placeholder="{{ $campo->placeholder }}"
                                   @if ($auto) autocomplete="{{ $auto }}" @endif
                                   @if ($campo->key === 'phone') inputmode="tel" @endif
                                   @if ($campo->required) aria-required="true" @endif
                                    @if ($errores->has($campo->key)) aria-invalid="true" @endif>
                        @endif

                        @if ($campo->helpText)
                            <div class="ayuda">{{ $campo->helpText }}</div>
                        @endif
                        @if ($errores->has($campo->key))
                            <div class="error-campo">{{ $errores->first($campo->key) }}</div>
                        @endif
                    </div>
                @endforeach

                {{-- Casilla aparte y desmarcada. El envío del programa no la
                     necesita —lo está pidiendo justo ahora—, pero usar después
                     ese correo para campañas es otra finalidad y necesita su
                     propio permiso. Este campo es el que decide qué contactos
                     se pueden usar para eso. --}}
                <div class="campo">
                    <label class="casilla">
                        <input type="checkbox" name="marketing" value="1"
                               @checked(old('marketing', $valores['marketing'] ?? false))>
                        <span>{{ $event->consent_text ?: \App\Models\Event::CONSENTIMIENTO_POR_DEFECTO }}</span>
                    </label>
                </div>

                <button type="submit" data-enviando-texto="Enviando…">Enviar solicitud</button>

                {{-- Oculto el 13/09/2026 hasta que la empresa valide el texto de
                     privacidad. Para reactivarlo, quitar este comentario.
                <p class="legal">
                    Al enviar autorizas que usemos tus datos para responder a esta
                    solicitud.
                    <a href="{{ route('publico.privacidad') }}" target="_blank" rel="noopener">
                        Política de privacidad
                    </a>
                </p>
                --}}
            </form>
        @endif

    </div>
</div>
{{-- Es un formulario embebido en sitios ajenos y no comparte el layout del
     flujo de inscripcion, asi que lleva su propia proteccion contra el doble
     envio: sin senal de espera la persona vuelve a hacer clic y se registran
     dos solicitudes. --}}
<script>
// Mostrar el campo de texto solo cuando eligen "Otro". Sin JavaScript queda
// visible, que es preferible a dejar a alguien sin poder escribir su cargo.
document.querySelectorAll('select[data-abre-otro]').forEach(function (select) {
    var envoltura = document.getElementById(select.dataset.abreOtro + '_envoltura');
    if (! envoltura) { return; }

    select.addEventListener('change', function () {
        envoltura.hidden = select.value !== 'Otro';
    });
});

(function () {
    var form = document.querySelector('form');
    if (! form) { return; }

    form.addEventListener('submit', function () {
        if (form.dataset.enviando === '1') { return; }
        form.dataset.enviando = '1';

        window.setTimeout(function () {
            form.querySelectorAll('button[type=submit]').forEach(function (boton) {
                if (boton.dataset.enviandoTexto) { boton.textContent = boton.dataset.enviandoTexto; }
                boton.disabled = true;
                boton.setAttribute('aria-busy', 'true');
            });
        }, 0);
    });
})();
</script>
</body>
</html>
