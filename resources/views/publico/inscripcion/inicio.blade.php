@extends('publico.layout')
@section('titulo', 'Inscripción')
@section('subtitulo', '')

@section('barra')
    @php $nombreOrganizador = $event->organizer_name; @endphp
    <div class="barra-marca">
        <div class="barra-marca-interior">
            @if ($event->tieneLogo())
                <img src="{{ $event->logoUrl() }}" style="height:{{ $event->altoDelLogo() }}px" alt="{{ $nombreOrganizador }}">
            @else
                <strong class="barra-marca-nombre">{{ $nombreOrganizador }}</strong>
            @endif
            <span>Plataforma de inscripciones</span>
        </div>
    </div>
@endsection

@section('encabezado')
    @php
        $fechas = $event->fechasLegibles();
        $etiqueta = $event->event_type;
        $titulo = $event->tituloParaMostrar();
        $bajada = $event->tagline;
    @endphp

    <div class="hero">
        @if ($etiqueta)<p class="hero-etiqueta">{{ $etiqueta }}</p>@endif
        <h1>{{ $titulo }}</h1>
        @if ($bajada)<p class="hero-sub">{{ $bajada }}</p>@endif
    </div>

    <ul class="datos-evento">
        @if ($fechas)
            <li>
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4M7.5 14h2M11 14h2M14.5 14h2M7.5 17.5h2M11 17.5h2"/></svg>
                <strong>{{ $fechas }}</strong>
            </li>
            <li class="divisor" aria-hidden="true"></li>
        @endif
        <li>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2.6"/><circle cx="5.5" cy="17.5" r="2.6"/><circle cx="18.5" cy="17.5" r="2.6"/><path d="M12 8v3M12 11l-6 4M12 11l6 4"/></svg>
            <strong>{{ $event->modality->label() }}</strong>
        </li>
        @if (filled($event->location) || filled($event->city))
            <li class="lugar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s-7.5-6.4-7.5-12a7.5 7.5 0 0 1 15 0c0 5.6-7.5 12-7.5 12z"/><circle cx="12" cy="10" r="2.8"/></svg>
                <span><strong>{{ collect([$event->location, $event->city])->filter()->implode(' · ') }}</strong>@if ($event->address)<small>{{ $event->address }}</small>@endif</span>
            </li>
        @endif
    </ul>
@endsection

@section('contenido')
    <style>
        body{background:#FAF8F4;color:#1B2A4A}
        .barra-marca{background:#fff;border-bottom:1px solid #E2E4EA;margin-bottom:34px}
        .barra-marca-interior{max-width:1040px;margin:0 auto;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
        .barra-marca img{width:auto;max-width:60%;object-fit:contain;display:block}
        .barra-marca-nombre{color:#0B1F4B;font-size:1.1rem;line-height:1.2;max-width:60%}
        .barra-marca span{color:#5B6478;font-size:1rem}
        .envoltura{max-width:640px;padding-top:0}
        .hero{text-align:center;margin-bottom:22px}
        .hero-etiqueta{margin:0 0 14px;text-transform:uppercase;letter-spacing:.28em;font-size:.85rem;color:#4A5570}
        .hero h1{margin:0 0 14px;color:#0B1F4B;font-size:clamp(2.2rem,9vw,3.5rem);line-height:1.08;font-weight:800;letter-spacing:-.02em}
        .hero-sub{margin:0;color:#5B6478;font-size:1.15rem;line-height:1.4}
        .datos-evento{list-style:none;margin:0 0 30px;padding:0;display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:16px 26px;color:#0B1F4B}
        .datos-evento li{display:flex;align-items:center;gap:14px;font-size:1.1rem}
        .datos-evento svg{flex:none;width:44px;height:44px;fill:none;stroke:#0B1F4B;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}
        .datos-evento .divisor{width:1px;height:40px;background:#D5D8E0}
        .datos-evento .lugar{flex-basis:100%;justify-content:center;gap:16px}
        .datos-evento .lugar svg{stroke:#0B1F4B}
        .datos-evento small{display:block;margin-top:2px;font-weight:400;color:#5B6478;font-size:.95rem}
        .entrada{background:#fff;border:1px solid #E2E4EA;border-radius:14px;box-shadow:0 6px 24px rgba(20,30,60,.08);padding:30px 32px}
        .entrada h2{margin:0 0 10px;color:#0B1F4B;font-size:2rem;font-weight:800;letter-spacing:-.01em}
        .entrada .sub{margin:0 0 22px;color:#5B6478;font-size:1.05rem;line-height:1.45;max-width:30em}
        .entrada label{margin-bottom:8px;color:#0B1F4B;font-size:1rem;font-weight:700}
        .entrada input[type=email]{font-size:1.05rem;padding:14px 16px;border:1px solid #C9CDD9;border-radius:8px;color:#0B1F4B}
        .entrada input[type=email]::placeholder{color:#8A91A3}
        .entrada input[type=email]:focus{border-color:#0B1F4B;outline:2px solid rgba(11,31,75,.15)}
        .entrada .ayuda-campo{margin:10px 0 0;font-size:.92rem;color:#5B6478}
        .entrada .acciones{margin-top:20px}
        .entrada .btn{width:100%;font-size:1.15rem;font-weight:700;padding:16px 24px;border-radius:8px}
        .entrada .btn-primario{background:#D9B24C;color:#0B1F4B}
        .entrada .btn-primario:hover{background:#C9A23F}
        .entrada .btn[disabled]{opacity:.75;cursor:wait}
        .entrada .nota{margin:16px 0 0;font-size:.9rem;color:#6B7285;text-align:center}
        .entrada .error-campo{font-size:1rem;font-weight:600;color:#B42318;background:#FDECEC;border-radius:8px;padding:10px 12px;margin-top:10px}
        .entrada .exito{display:flex;align-items:flex-start;gap:14px;background:#E8F5EA;border:1px solid #A9D5B0;color:#14532D;
                        border-radius:10px;padding:16px 18px;font-size:1.05rem;line-height:1.45}
        .entrada .exito-icono{flex:none;display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#2E7D32;color:#fff;font-weight:700}
        .entrada .correo-enviado{word-break:break-all}
        .entrada .acciones-sec{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
        .entrada .acciones-sec form{margin:0;flex:1}
        .entrada .acciones-sec .btn{width:100%;padding:12px 16px;font-size:1rem;font-weight:600}
        .entrada .acciones-sec a.btn{flex:1}
        .ayuda-card{display:flex;align-items:center;gap:22px;background:#EEF4FC;border:1px solid #D3E2F5;border-radius:14px;box-shadow:none;padding:22px 26px;margin-top:20px}
        .ayuda-icono{flex:none;width:60px;height:60px;fill:none;stroke:#0B1F4B;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;
                     padding-right:22px;box-sizing:content-box;border-right:1px solid #C9D6EA}
        .ayuda-card h2{margin:0 0 6px;font-size:1.1rem;color:#0B1F4B}
        .ayuda-card p{margin:0 0 3px;font-size:.98rem;line-height:1.4;color:#33405F;word-break:break-word}
        .ayuda-card a{color:#0B1F4B;font-weight:500;text-decoration:none}
        .ayuda-card a:hover{text-decoration:underline}
        .pie{margin-top:28px;padding-top:16px;border-top:1px solid #E2E4EA;text-align:center;color:#6B7285;font-size:.88rem}
        @media(max-width:520px){
            .entrada{padding:22px 18px}
            .entrada h2{font-size:1.6rem}
            .ayuda-card{padding:18px;gap:14px}
            .ayuda-icono{width:40px;height:40px;padding-right:14px}
            
            .barra-marca span{font-size:.85rem}
        }
    </style>
    <div class="entrada">
        @if ($enviado)
            <div class="exito">
                <span class="exito-icono" aria-hidden="true">✓</span>
                <span>Enlace enviado a <strong class="correo-enviado">{{ $valores['email'] ?? 'tu correo' }}</strong>. Revisa tu bandeja de entrada y la carpeta de correo no deseado para continuar.</span>
            </div>
            <div class="acciones-sec">
                <form method="POST" action="{{ route('inscripcion.solicitar', $event) }}">
                    <input type="hidden" name="email" value="{{ $valores['email'] ?? '' }}">
                    <button type="submit" class="btn btn-secundario" data-enviando-texto="Enviando enlace…">Reenviar enlace</button>
                </form>
                <a class="btn btn-secundario" href="{{ route('inscripcion.inicio', $event) }}?email={{ urlencode($valores['email'] ?? '') }}">Corregir correo</a>
            </div>
        @else
            <h2>Comienza tu inscripción</h2>
            <p class="sub">Ingresa tu correo y recibirás un enlace para acceder al formulario de inscripción.</p>

            <form method="POST" action="{{ route('inscripcion.solicitar', $event) }}" novalidate autocomplete="off" id="form-correo">
                <div>
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" value="{{ $valores['email'] ?? '' }}"
                           autocomplete="off" inputmode="email" autocapitalize="none" spellcheck="false"
                           placeholder="nombre@correo.cl" autofocus
                           @if ($errores->has('email')) aria-invalid="true" @endif>
                    @if ($errores->has('email'))
                        <div class="error-campo">{{ $errores->first('email') }} Corrígelo y vuelve a intentar; no necesitas escribir nada más.</div>
                    @endif
                    <p class="ayuda-campo">Usa el correo de quien gestionará la inscripción.</p>
                </div>

                <div class="acciones">
                    <button type="submit" class="btn btn-primario" data-enviando-texto="Enviando enlace…">Recibir enlace de inscripción →</button>
                </div>
                <p class="nota">Solicitar el enlace no confirma tu inscripción ni implica un pago.</p>
            </form>
        @endif
    </div>

    @if ($event->tieneContacto())
        @php
            $normal = fn (?string $telefono): ?string => \App\Support\Texto::telefono($telefono) ?? $telefono;
            $legible = fn (?string $telefono): ?string => preg_match('/^56(9)(\d{4})(\d{4})$/', (string) $normal($telefono), $m)
                ? "+56 {$m[1]} {$m[2]} {$m[3]}"
                : $telefono;
        @endphp
        <div class="ayuda-card">
            <svg class="ayuda-icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5a6.5 6.5 0 0 1 6.5-6.5h2A6.5 6.5 0 0 1 18 11.5 6.5 6.5 0 0 1 11.5 18H8l-3.5 2.5V16A6.5 6.5 0 0 1 3 11.5z"/><circle cx="8.5" cy="11.5" r=".6"/><circle cx="11.5" cy="11.5" r=".6"/><circle cx="14.5" cy="11.5" r=".6"/></svg>
            <div>
                <h2>¿Necesitas ayuda para inscribirte?</h2>
                @if ($event->contact_name || $event->contact_role)
                    <p>{{ collect([$event->contact_name, $event->contact_role])->filter()->implode(' · ') }}</p>
                @endif
                @if ($event->contact_phone)
                    <p><a href="tel:+{{ ltrim((string) $normal($event->contact_phone), '+') }}">{{ $legible($event->contact_phone) }}</a></p>
                @endif
                @if ($event->contact_whatsapp)
                    <p><a href="https://wa.me/{{ ltrim((string) $normal($event->contact_whatsapp), '+') }}">WhatsApp {{ $legible($event->contact_whatsapp) }}</a></p>
                @endif
                @if ($event->contact_email)
                    <p><a href="mailto:{{ $event->contact_email }}">{{ $event->contact_email }}</a></p>
                @endif
            </div>
        </div>
    @endif

    @if ($nombreOrganizador)
        <p class="pie">{{ $nombreOrganizador }}</p>
    @endif

    <script>
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
