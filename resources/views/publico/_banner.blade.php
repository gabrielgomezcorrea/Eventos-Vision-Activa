{{--
    Cabecera del evento: la imagen que subió quien lo configuró, o si no hay
    ninguna, un fondo liso del color del sistema. Nunca se recorta la imagen;
    el sistema no decide qué parte se ve. Encima van los datos que el evento
    tenga cargados: el nombre siempre, lugar, ciudad y fecha si existen.
--}}
@php($datos = $evento->datosCabecera())
<div style="position:relative;border-radius:10px;overflow:hidden;margin-bottom:16px;
            {{ $evento->tieneBanner() ? '' : 'background:var(--primario);' }}">
    @if ($evento->tieneBanner())
        <img src="{{ $evento->bannerUrl() }}" alt="{{ $evento->name }}"
             style="display:block;width:100%;height:auto">
        {{-- Capa oscura para que el texto se lea sobre cualquier imagen. --}}
        <div style="position:absolute;inset:0;
                    background:linear-gradient(to top, rgba(0,0,0,.68), rgba(0,0,0,.1) 65%)"></div>
    @endif
    <div style="{{ $evento->tieneBanner() ? 'position:absolute;left:0;right:0;bottom:0;' : '' }}
                padding:22px 22px;color:#fff">
        <div style="font-size:1.3rem;font-weight:700;line-height:1.3">{{ $datos['nombre'] }}</div>
        @if ($datos['lugar'] || $datos['ciudad'] || $datos['fecha'])
            <div style="font-size:.9rem;opacity:.92;margin-top:5px">
                {{ collect([$datos['lugar'], $datos['ciudad'], $datos['fecha']])->filter()->implode(' · ') }}
            </div>
        @endif
    </div>
</div>
