{{--
    Banner del evento en el correo: solo la imagen, tal cual, y solo si existe.
    Sin imagen no se dibuja nada.

    Estilos en línea: Outlook ignora las hojas de estilo y no entiende
    max-width en una imagen, por eso lleva también `width`. Va en una tabla
    al ancho completo del cuerpo (506 px útiles dentro del relleno del correo).
--}}
@if ($evento->muestraBannerEn('mail'))
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;margin:0 0 24px">
<tr><td align="center" style="padding:0">
<img src="{{ $evento->bannerUrl() }}" alt="{{ $evento->name }}" width="506" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:6px">
</td></tr>
</table>
@endif
