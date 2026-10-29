@extends('publico.layout')
@section('titulo', 'Inscripción')
@section('subtitulo', '')

@section('contenido')
    <style>
        .envoltura{max-width:600px}
        .cabecera{text-align:center}
        .cabecera h1{font-size:1.6rem}
        .cabecera p:empty{display:none}
        .entrada{border:1px solid #C9CDD9;box-shadow:0 4px 18px rgba(0,0,0,.12)}
        .entrada h2{font-size:1.3rem}
        .entrada .sub{font-size:1.05rem;color:#3A4057}
        .entrada label{font-size:1rem}
        .entrada input[type=email]{font-size:1rem;padding:11px 14px;border:2px solid #8A8FA3}
        .entrada input[type=email]:focus{border-color:var(--primario)}
        .entrada .btn{width:100%;font-size:1.1rem;padding:12px 24px}
        .entrada .exito{display:flex;align-items:center;gap:14px;background:#DDF3E2;border:2px solid #2E7D32;color:#14532D;
                  border-radius:12px;padding:18px 20px;margin-bottom:18px;font-size:1.2rem}
        .entrada .exito-icono{flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:50%;
                              background:#2E7D32;color:#fff;font-size:1.4rem;font-weight:700}
        .entrada .error-campo{font-size:1.05rem;font-weight:600;color:#B42318;background:#FDECEC;border-radius:8px;padding:10px 12px;margin-top:10px}
        .separador{border:0;border-top:2px solid #C9CDD9;margin:28px 0}
        .portal-banner img{cursor:zoom-in}
        .visor{position:fixed;inset:0;z-index:50;background:rgba(15,12,25,.82);display:flex;align-items:center;justify-content:center;padding:24px}
        .visor[hidden]{display:none}
        .visor img{max-width:100%;max-height:100%;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.5);background:#fff}
        .visor-x{position:absolute;top:14px;right:18px;width:48px;height:48px;border-radius:50%;border:0;background:#fff;color:#1F2937;
                 font-size:2rem;line-height:1;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.4)}
        .ayuda-card h2{font-size:1.15rem;margin-bottom:12px}
        .ayuda-card p{margin:0 0 10px;font-size:1rem;line-height:1.4;word-break:break-word}
        .ayuda-card .ayuda-nombre{font-weight:700;font-size:1.05rem}
        .ayuda-card .ayuda-dato span{display:block;font-weight:600;color:var(--texto-suave);font-size:.95rem}
        .ayuda-card a{color:var(--primario);font-weight:600}
        .entrada .btn[disabled]{opacity:.75;cursor:wait}
        .entrada .correo-enviado{font-weight:600;word-break:break-all;color:var(--texto)}
        .entrada .otro{font-weight:600}
    </style>
    <div class="tarjeta entrada">
        @if ($enviado)
            <div class="exito">
                <span class="exito-icono" aria-hidden="true">✓</span>
                <strong>Te enviamos el enlace</strong>
            </div>
            <p class="sub">
                Abre el correo que llegó a <span class="correo-enviado">{{ $valores['email'] ?? 'tu correo' }}</span>
                y haz clic en el botón.
            </p>
            <p class="sub">¿No te llega? Por favor revisa en correo no deseado SPAM.</p>
            <a class="otro" href="{{ route('inscripcion.inicio', $event) }}">Volver a intentarlo</a>
        @else
            <h2>Ingresa tu correo</h2>
            <p class="sub">
                Te enviaremos un enlace para inscribirte.
            </p>

            <form method="POST" action="{{ route('inscripcion.solicitar', $event) }}" novalidate autocomplete="off" id="form-correo">
                <div>
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" value="{{ $valores['email'] ?? '' }}"
                           autocomplete="off" inputmode="email" autocapitalize="none" spellcheck="false"
                           placeholder="nombre@correo.cl" autofocus
                           @if ($errores->has('email')) aria-invalid="true" @endif>
                    @if ($errores->has('email'))
                        <div class="error-campo">{{ $errores->first('email') }}</div>
                    @endif
                </div>

                <div class="acciones">
                    <button type="submit" class="btn btn-primario" data-enviando-texto="Enviando, espera un momento…">Enviarme enlace de inscripción al correo</button>
                </div>
            </form>
        @endif
    </div>

    @if ($event->tieneContacto())
        <hr class="separador">
        @php
            $normal = fn (?string $telefono): ?string => \App\Support\Texto::telefono($telefono) ?? $telefono;
            $legible = fn (?string $telefono): ?string => preg_match('/^56(9)(\d{4})(\d{4})$/', (string) $normal($telefono), $m)
                ? "+56 {$m[1]} {$m[2]} {$m[3]}"
                : $telefono;
        @endphp
        <div class="tarjeta entrada ayuda-card">
            <h2>¿Necesitas ayuda con tu inscripción?</h2>
            @if ($event->contact_name)<p class="ayuda-nombre">{{ $event->contact_name }}</p>@endif
            @if ($event->contact_role)<p class="ayuda-dato">{{ $event->contact_role }}</p>@endif
            @if ($event->contact_organization)<p class="ayuda-dato">{{ $event->contact_organization }}</p>@endif
            @if ($event->contact_phone)
                <p class="ayuda-dato"><span>Teléfono:</span><a href="tel:+{{ ltrim((string) $normal($event->contact_phone), '+') }}">{{ $legible($event->contact_phone) }}</a></p>
            @endif
            @if ($event->contact_email)
                <p class="ayuda-dato"><span>Correo:</span><a href="mailto:{{ $event->contact_email }}">{{ $event->contact_email }}</a></p>
            @endif
            @if ($event->contact_whatsapp)
                <p class="ayuda-dato"><span>WhatsApp:</span><a href="https://wa.me/{{ ltrim((string) $normal($event->contact_whatsapp), '+') }}">{{ $legible($event->contact_whatsapp) }}</a></p>
            @endif
        </div>
    @endif

    <div id="visor" class="visor" hidden role="dialog" aria-modal="true" aria-label="Imagen del evento">
        <button type="button" class="visor-x" aria-label="Cerrar imagen">×</button>
        <img src="" alt="{{ $event->name }}">
    </div>
    <script>
        (function () {
            var visor = document.getElementById('visor');
            var banner = document.querySelector('.portal-banner img');
            if (visor && banner) {
                var abrir = function () { visor.querySelector('img').src = banner.src; visor.hidden = false; };
                var cerrar = function () { visor.hidden = true; };
                banner.addEventListener('click', abrir);
                visor.addEventListener('click', function (e) {
                    if (e.target === visor || e.target.classList.contains('visor-x')) { cerrar(); }
                });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { cerrar(); } });
            }
        })();
        (function () {
            var form = document.getElementById('form-correo');
            if (!form) { return; }
            var campo = document.getElementById('email');
            var enviado = false;

            function mostrarError(texto) {
                var aviso = form.querySelector('.error-campo');
                if (!aviso) {
                    aviso = document.createElement('div');
                    aviso.className = 'error-campo';
                    aviso.setAttribute('role', 'alert');
                    campo.parentNode.appendChild(aviso);
                }
                aviso.textContent = texto;
                campo.setAttribute('aria-invalid', 'true');
                campo.focus();
            }

            form.addEventListener('submit', function (evento) {
                if (enviado) { evento.preventDefault(); return; }
                var valor = campo.value.trim();
                campo.value = valor;
                if (valor === '') {
                    evento.preventDefault();
                    mostrarError('Escribe tu correo.');
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor)) {
                    evento.preventDefault();
                    mostrarError('Revisa tu correo. Debe verse así: nombre@correo.cl');
                } else {
                    enviado = true;
                }
            });
        })();
    </script>
@endsection
