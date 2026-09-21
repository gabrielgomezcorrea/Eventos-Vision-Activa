{{--
    Cabecera del evento en el correo. Si tiene banner, va arriba, tal como
    hoy. Los datos del evento van siempre debajo, en una franja de color:
    Outlook no dibuja texto sobre una imagen de fondo, así que el texto nunca
    va superpuesto al banner.

    Estilos en línea y tabla en vez de <div>: Outlook ignora las hojas de
    estilo, no entiende max-width en una imagen y renderiza mejor un color de
    fondo sólido con `bgcolor` que con CSS.
--}}
@php($datos = $evento->datosCabecera())
@if ($evento->tieneBanner())
<p style="margin:0">
    <img src="{{ $evento->bannerUrl() }}" alt="{{ $evento->name }}" width="570"
         style="display:block;width:100%;max-width:570px;height:auto;border:0;border-radius:6px 6px 0 0">
</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px">
    <tr>
        <td bgcolor="#084887"
            style="padding:14px 18px;font-family:sans-serif;color:#ffffff;
                   border-radius:{{ $evento->tieneBanner() ? '0 0 6px 6px' : '6px' }}">
            <div style="font-size:16px;font-weight:bold;line-height:1.3">{{ $datos['nombre'] }}</div>
            @if ($datos['lugar'] || $datos['ciudad'] || $datos['fecha'])
                <div style="font-size:13px;color:#ffffff;margin-top:4px">
                    {{ collect([$datos['lugar'], $datos['ciudad'], $datos['fecha']])->filter()->implode(' · ') }}
                </div>
            @endif
        </td>
    </tr>
</table>
