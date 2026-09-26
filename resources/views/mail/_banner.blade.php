{{--
    Banner del evento en el correo: solo la imagen, tal cual, y solo si existe.
    Sin imagen no se dibuja nada.

    Estilos en línea: Outlook ignora las hojas de estilo y no entiende
    max-width en una imagen, por eso lleva también `width`.
--}}
@if ($evento->muestraBannerEn('mail'))
<p style="margin:0 0 18px">
    <img src="{{ $evento->bannerUrl() }}" alt="{{ $evento->name }}" width="570"
         style="display:block;width:100%;max-width:570px;height:auto;border:0;border-radius:6px">
</p>
@endif
