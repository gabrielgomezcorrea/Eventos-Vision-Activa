@extends('publico.layout')
@section('titulo', 'Establecimiento')
@section('subtitulo', 'Establecimiento del que provienen los participantes')

@section('contenido')
    <div class="tarjeta">
        <h2>Establecimientos de la inscripción</h2>
        <p class="sub">Puedes agregar más de un colegio: cada uno queda como su propia inscripción, con el mismo enlace.</p>

        @if ($orden->establishments->isEmpty())
            <div class="vacio">Todavía no has agregado un establecimiento.</div>
        @else
            <table>
                <thead>
                    <tr><th>Establecimiento</th><th>RBD</th><th>Comuna</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($orden->establishments as $establecimiento)
                        <tr>
                            <td><strong>{{ $establecimiento->name }}</strong>
                                @if ($establecimiento->address)
                                    <div class="ayuda">{{ $establecimiento->address }}</div>
                                @endif
                            </td>
                            <td>{{ $establecimiento->rbd ?: '—' }}</td>
                            <td>{{ $establecimiento->commune ?: '—' }}</td>
                            <td style="text-align:right">
                                <form method="POST"
                                      action="{{ route('inscripcion.establecimientos.quitar', ['token' => $token, 'establishment' => $establecimiento]) }}"
                                      data-confirmar="¿Quitar «{{ $establecimiento->name }}» de la inscripción?">
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-peligro">Quitar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

<form method="POST" action="{{ route('inscripcion.establecimientos.agregar', ['token' => $token]) }}" novalidate>
    <div class="tarjeta">
        <h2>{{ $orden->establishments->isEmpty() ? 'Agregar el establecimiento' : 'Agregar otro colegio' }}</h2>
        <div class="rejilla">
            @include('publico.inscripcion._campo', ['campo' => 'name', 'etiqueta' => 'Nombre del establecimiento'])
            @include('publico.inscripcion._campo', ['campo' => 'rbd', 'etiqueta' => 'RBD', 'opcional' => true, 'placeholder' => '12345-6', 'autocomplete' => 'off'])
            @include('publico.inscripcion._campo', ['campo' => 'address', 'etiqueta' => 'Dirección', 'autocomplete' => 'street-address'])
            @include('publico.inscripcion._campo', ['campo' => 'commune', 'etiqueta' => 'Comuna', 'autocomplete' => 'off', 'atributos' => 'list="comunas"'])
        </div>
        @include('publico.inscripcion._comunas')

        <div class="acciones">
            <button type="submit" class="btn btn-secundario" data-enviando-texto="Guardando…">Guardar</button>
        </div>
    </div>
</form>

    <div class="acciones">
        <a href="{{ route('inscripcion.responsable', ['token' => $token]) }}" class="btn btn-secundario">Volver</a>
        @if ($orden->establishments->isNotEmpty())
            <a href="{{ route('inscripcion.participantes', ['token' => $token]) }}" class="btn btn-primario">Continuar a participantes</a>
        @endif
    </div>
@endsection
