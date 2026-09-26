{{--
    Banner del evento: solo la imagen que subió quien lo configuró, tal cual.
    Sin imagen no se dibuja nada. Nunca lleva texto encima: las imágenes ya
    vienen diseñadas completas.
--}}
@if ($evento->muestraBannerEn($ubicacion))
    <img src="{{ $evento->bannerUrl() }}" alt="{{ $evento->name }}"
         style="display:block;width:100%;height:auto;max-height:200px;object-fit:contain;border-radius:10px;margin-bottom:16px">
@endif
