@extends('publico.layout')
@section('titulo', 'Establecimientos')
@section('subtitulo', 'Establecimientos de los que provienen los participantes')

@section('contenido')
    <div class="tarjeta">
        <h2>Establecimientos de la orden</h2>
        <p class="sub">Puedes asociar uno o varios. Cada participante quedará vinculado a uno de ellos.</p>

        @if ($orden->establishments->isEmpty())
            <div class="vacio">Todavía no has agregado establecimientos.</div>
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
            <h2>Agregar establecimiento</h2>
            <p class="sub">El RBD se ingresa manualmente.</p>

            <div class="rejilla">
                <div>
                    <label for="name">Nombre del establecimiento</label>
                    <input type="text" id="name" name="name" value="{{ $valores['name'] ?? '' }}"
                           @if ($errores->has('name')) aria-invalid="true" @endif>
                    @if ($errores->has('name'))
                        <div class="error-campo">{{ $errores->first('name') }}</div>
                    @endif
                </div>
                <div>
                    <label for="rbd">RBD <span class="opcional">(opcional)</span></label>
                    <input type="text" id="rbd" name="rbd" value="{{ $valores['rbd'] ?? '' }}">
                </div>
                <div>
                    <label for="address">Dirección <span class="opcional">(opcional)</span></label>
                    <input type="text" id="address" name="address" value="{{ $valores['address'] ?? '' }}">
                </div>
                <div>
                    <label for="commune">Comuna <span class="opcional">(opcional)</span></label>
                    <input type="text" id="commune" name="commune" value="{{ $valores['commune'] ?? '' }}">
                </div>
            </div>

            <div class="acciones">
                <button type="submit" class="btn btn-secundario" data-enviando-texto="Agregando…">Agregar establecimiento</button>
            </div>
        </div>
    </form>

    <div class="acciones">
        <a href="{{ route('inscripcion.pagador', ['token' => $token]) }}" class="btn btn-secundario">Volver</a>
        <a href="{{ route('inscripcion.participantes', ['token' => $token]) }}" class="btn btn-primario">Continuar a participantes</a>
    </div>
@endsection
