<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    {{-- El token viaja en la URL: no debe filtrarse por el encabezado Referer. --}}
    <meta name="referrer" content="no-referrer">
    <title>@yield('titulo', 'Inscripción') · {{ $event->name }}</title>
    <style>
        :root {
            --primario:#084887; --primario-oscuro:#063a6d; --acento:#F58A07;
            --texto:#1F2937; --texto-suave:#667085; --borde:#D0D5DD;
            --fondo:#F7F5FB; --superficie:#fff;
            --error:#B42318; --error-suave:#FDECEC;
            --exito:#2E7D32; --exito-suave:#EAF6ED;
            --info-suave:#EAF2FB; --aviso-suave:#FFF4E5;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--fondo);color:var(--texto);
             font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .envoltura{max-width:820px;margin:0 auto;padding:28px 16px 60px}
        /* La pantalla de estado lleva menú lateral y necesita algo más de aire.
           Los pasos del recorrido siguen en 820: son una columna de formulario. */
        .envoltura.ancha{max-width:1040px}
        .columnas{display:grid;grid-template-columns:240px 1fr;gap:24px;align-items:start}
        .lado{position:sticky;top:24px;display:flex;flex-direction:column;gap:14px}
        .lado-caja{background:var(--superficie);border:1px solid var(--borde);border-radius:12px;padding:16px}
        .lado-titulo{margin:0 0 10px;font-size:.78rem;text-transform:uppercase;
                     letter-spacing:.03em;color:var(--texto-suave);font-weight:600}
        .lado-lista{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:4px}
        .lado-item{display:block;padding:9px 11px;border-radius:8px;text-decoration:none;
                   color:var(--texto);border:1px solid transparent}
        .lado-item:hover{background:var(--fondo)}
        .lado-item.activo{background:var(--info-suave);border-color:#BBD3EE}
        .lado-item-nombre{display:block;font-weight:600;font-size:.9rem;line-height:1.3}
        .lado-item-estado{display:block;font-size:.78rem;color:var(--texto-suave);margin-top:2px}
        .lado-item.activo .lado-item-nombre{color:var(--primario)}
        .lado-nota{margin:10px 0 0;font-size:.78rem;color:var(--texto-suave)}
        /* En el celular el menú va arriba, no al costado: el contenido manda. */
        @media(max-width:820px){
            .columnas{grid-template-columns:1fr;gap:16px}
            .lado{position:static}
        }
        .banner{display:block;width:100%;height:auto;border-radius:10px;margin-bottom:20px}
        .cabecera{margin-bottom:20px}
        .cabecera h1{font-size:1.3rem;margin:0 0 4px}
        .cabecera p{margin:0;color:var(--texto-suave);font-size:.9rem}
        .pasos{display:flex;flex-wrap:wrap;gap:6px;margin:18px 0 22px;padding:0;list-style:none;font-size:.82rem}
        .pasos li{padding:5px 11px;border-radius:999px;background:#fff;border:1px solid var(--borde);color:var(--texto-suave)}
        .pasos li.actual{background:var(--primario);border-color:var(--primario);color:#fff;font-weight:600}
        .pasos li.hecho{background:var(--exito-suave);border-color:#BFE0C6;color:var(--exito)}
        .tarjeta{background:var(--superficie);border:1px solid var(--borde);border-radius:12px;padding:24px;margin-bottom:18px}
        .tarjeta h2{font-size:1.05rem;margin:0 0 4px}
        /* Paso dentro de una tarjeta: transferir y avisar son dos momentos del
           mismo trámite, no dos tarjetas separadas. */
        .tarjeta .paso{font-size:1.05rem;margin:0 0 4px}
        .tarjeta .paso + .sub{margin-bottom:14px}
        .tarjeta form .paso{margin-top:24px;padding-top:20px;border-top:1px solid var(--borde)}
        .tarjeta .sub{color:var(--texto-suave);font-size:.88rem;margin:0 0 18px}
        .rejilla{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .rejilla .ancho{grid-column:1/-1}
        @media(max-width:600px){.rejilla{grid-template-columns:1fr}}
        label{display:block;font-weight:600;font-size:.88rem;margin-bottom:5px}
        .opcional{font-weight:400;color:var(--texto-suave);font-size:.8rem}
        input[type=text],input[type=email],input[type=tel],select{
            width:100%;padding:10px 12px;font:inherit;color:inherit;background:#fff;
            border:1px solid var(--borde);border-radius:8px}
        input:focus,select:focus{outline:2px solid var(--primario);outline-offset:1px;border-color:var(--primario)}
        [aria-invalid=true]{border-color:var(--error)}
        .ayuda{color:var(--texto-suave);font-size:.8rem;margin-top:4px}
        .error-campo{color:var(--error);font-size:.83rem;margin-top:4px}
        .aviso{border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:.9rem}
        .aviso-error{background:var(--error-suave);color:var(--error)}
        /* Aviso sin fondo: el bloque rojo entero grita, cuando basta el texto. */
        .aviso-texto{color:var(--error);font-size:.92rem;margin-bottom:16px}
        .aviso-info{background:var(--info-suave);color:var(--primario)}
        .aviso-exito{background:var(--exito-suave);color:var(--exito)}
        .acciones{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}
        .btn{display:inline-block;padding:11px 20px;font:inherit;font-weight:600;
             border:0;border-radius:8px;cursor:pointer;text-decoration:none;text-align:center}
        .btn-primario{background:var(--primario);color:#fff}
        .btn-primario:hover{background:var(--primario-oscuro)}
        .btn-secundario{background:#fff;color:var(--texto);border:1px solid var(--borde)}
        .btn-peligro{background:transparent;color:var(--error);border:1px solid var(--borde);padding:6px 12px;font-size:.83rem}
        .btn:disabled,.btn[disabled]{opacity:.62;cursor:progress}
        table{width:100%;border-collapse:collapse;font-size:.9rem}
        th{text-align:left;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;
           color:var(--texto-suave);padding:8px 10px;border-bottom:1px solid var(--borde)}
        td{padding:10px;border-bottom:1px solid #EDF0F3;vertical-align:middle}
        tr:last-child td{border-bottom:0}
        .vacio{padding:22px;text-align:center;color:var(--texto-suave);font-size:.9rem;
               background:#FAFBFC;border:1px dashed var(--borde);border-radius:8px}
        .total{display:flex;justify-content:space-between;align-items:baseline;
               padding:14px 0;border-top:2px solid var(--borde);margin-top:8px;font-weight:600}
        .total .monto{font-size:1.35rem;color:var(--primario)}
        .chip{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.78rem;
              background:var(--info-suave);color:var(--primario)}
    </style>
</head>
<body>
<div class="envoltura @yield('envoltura')">
    @include('publico._banner', ['evento' => $event, 'ubicacion' => 'form'])

    <div class="cabecera">
        <h1>{{ $event->name }}</h1>
        <p>@yield('subtitulo', 'Inscripción de participantes')</p>
    </div>

    @isset($paso)
        @php
            $orden_pasos = ['responsable' => 'Responsable', 'establecimientos' => 'Establecimiento',
                            'participantes' => 'Participantes', 'pagador' => 'Facturación',
                            'resumen' => 'Resumen'];
            $indice = array_search($paso, array_keys($orden_pasos), true);
        @endphp
        <ol class="pasos">
            @foreach ($orden_pasos as $clave => $etiqueta)
                <li class="{{ $clave === $paso ? 'actual' : ($loop->index < $indice ? 'hecho' : '') }}">
                    {{ $loop->iteration }}. {{ $etiqueta }}
                </li>
            @endforeach
        </ol>
    @endisset

    @if ($errores->isNotEmpty())
        <div class="aviso aviso-error">Revisa los campos marcados y vuelve a intentar.</div>
    @endif

    @yield('contenido')
</div>

{{--
    Unico script del flujo publico. Resuelve tres cosas que en pruebas de uso
    hacian que la persona llamara por telefono:

    1. Quitar un participante o establecimiento borraba sin preguntar.
    2. Subir un comprobante por conexion lenta dejaba la pantalla muerta, asi
       que el usuario volvia a hacer clic y enviaba dos veces.
    3. Un archivo sobre el limite se rechazaba recien despues de subirlo entero.

    Es progresivo: sin JavaScript los formularios siguen funcionando igual.
--}}
<script>
(function () {
    document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
        form.addEventListener('submit', function (evento) {
            if (! window.confirm(form.dataset.confirmar)) {
                evento.preventDefault();
            }
        });
    });

    document.querySelectorAll('input[type=file][data-max-mb]').forEach(function (campo) {
        campo.addEventListener('change', function () {
            var limite = parseFloat(campo.dataset.maxMb) * 1024 * 1024;
            var archivo = campo.files && campo.files[0];

            if (archivo && archivo.size > limite) {
                window.alert('El archivo "' + archivo.name + '" pesa ' +
                    (archivo.size / 1024 / 1024).toFixed(1) + ' MB y el máximo son ' +
                    campo.dataset.maxMb + ' MB.\n\nElige un archivo más liviano ' +
                    'o toma la foto del comprobante con menor calidad.');
                campo.value = '';
            }
        });
    });

    // RUT: se formatea mientras se escribe (12.345.678-9) y avisa al salir del
    // campo si el dígito verificador no calza. El servidor valida igual.
    function formatearRut(valor) {
        var limpio = valor.replace(/[^0-9kK]/g, '').toUpperCase();
        if (limpio === '') { return ''; }
        var cuerpo = limpio.slice(0, -1).replace(/K/g, '').slice(0, 8);
        var dv = limpio.slice(-1);
        if (cuerpo === '') { return dv; }
        return cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + dv;
    }

    function rutValido(valor) {
        var limpio = valor.replace(/[^0-9kK]/g, '').toUpperCase();
        if (limpio.length < 2) { return false; }
        var cuerpo = limpio.slice(0, -1), suma = 0, factor = 2;
        for (var i = cuerpo.length - 1; i >= 0; i--) {
            suma += Number(cuerpo[i]) * factor;
            factor = factor === 7 ? 2 : factor + 1;
        }
        var resto = 11 - (suma % 11);
        return limpio.slice(-1) === (resto === 11 ? '0' : resto === 10 ? 'K' : String(resto));
    }

    document.querySelectorAll('input[data-rut]').forEach(function (campo) {
        campo.value = formatearRut(campo.value);

        campo.addEventListener('input', function () {
            campo.value = formatearRut(campo.value);
        });

        campo.addEventListener('blur', function () {
            var aviso = campo.parentNode.querySelector('[data-rut-aviso]');
            var invalido = campo.value !== '' && ! rutValido(campo.value);

            if (invalido && ! aviso) {
                aviso = document.createElement('div');
                aviso.className = 'error-campo';
                aviso.setAttribute('data-rut-aviso', '');
                aviso.textContent = 'Este RUT no es válido. Revisa el dígito después del guion.';
                campo.insertAdjacentElement('afterend', aviso);
            } else if (! invalido && aviso) {
                aviso.remove();
            }

            if (invalido) {
                campo.setAttribute('aria-invalid', 'true');
            } else {
                campo.removeAttribute('aria-invalid');
            }
        });
    });

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (evento) {
            if (evento.defaultPrevented || form.dataset.enviando === '1') {
                return;
            }

            form.dataset.enviando = '1';

            // Se difiere: un boton deshabilitado durante el submit no envia su
            // propio name/value y se perderia la accion.
            window.setTimeout(function () {
                form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (boton) {
                    if (boton.dataset.enviandoTexto) {
                        boton.textContent = boton.dataset.enviandoTexto;
                    }
                    boton.disabled = true;
                    boton.setAttribute('aria-busy', 'true');
                });
            }, 0);
        });
    });
})();
</script>
</body>
</html>
