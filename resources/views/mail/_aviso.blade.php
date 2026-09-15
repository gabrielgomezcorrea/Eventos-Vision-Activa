{{--
    Aviso con color. Un pago rechazado no puede verse igual que uno aprobado:
    el color dice qué pasó antes de que la persona termine de leer.

    Espera $tono ('exito', 'alerta', 'error') y el texto en $slot o $mensaje.
    Colores en línea porque los clientes de correo ignoran las hojas de estilo.
--}}
@php
    $tonos = [
        'exito' => ['fondo' => '#EAF6ED', 'borde' => '#2E7D32', 'texto' => '#1B5E20'],
        'alerta' => ['fondo' => '#FFF4E5', 'borde' => '#F58A07', 'texto' => '#8A4704'],
        'error' => ['fondo' => '#FDECEC', 'borde' => '#B42318', 'texto' => '#912018'],
    ];
    $c = $tonos[$tono ?? 'alerta'];
@endphp
<table class="aviso" width="100%" cellpadding="0" cellspacing="0" role="presentation"
       style="background-color:{{ $c['fondo'] }};border-left:4px solid {{ $c['borde'] }};border-radius:6px;margin-bottom:24px">
<tr>
<td style="padding:16px 20px;font-size:18px;line-height:1.6em;color:{{ $c['texto'] }}">
{!! $mensaje !!}
</td>
</tr>
</table>
