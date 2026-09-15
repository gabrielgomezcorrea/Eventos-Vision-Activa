@extends('publico.layout')
@section('titulo', 'Facturación')
@section('subtitulo', 'Entidad que realiza el pago')

@section('contenido')
    <form method="POST" action="{{ route('inscripcion.pagador.guardar', ['token' => $token]) }}" novalidate>
        <div class="tarjeta">
            <h2>Entidad pagadora</h2>
            <p class="sub">
                Ingresa los datos de la entidad que realizará el pago y a cuyo nombre se emitirá
                la factura. Puede ser distinta del establecimiento participante.
            </p>

            <div class="rejilla">
                <div>
                    <label for="name">Nombre o razón social</label>
                    <input type="text" id="name" name="name" value="{{ $valores['name'] ?? '' }}"
                           @if ($errores->has('name')) aria-invalid="true" @endif>
                    @if ($errores->has('name'))
                        <div class="error-campo">{{ $errores->first('name') }}</div>
                    @endif
                </div>

                <div>
                    <label for="rut">RUT <span class="opcional">(opcional)</span></label>
                    <input type="text" id="rut" name="rut" value="{{ $valores['rut'] ?? '' }}"
                           placeholder="76.086.428-5" data-rut maxlength="12" autocomplete="off"
                           @if ($errores->has('rut')) aria-invalid="true" @endif>
                    @if ($errores->has('rut'))
                        <div class="error-campo">{{ $errores->first('rut') }}</div>
                    @else
                        <div class="ayuda">Si lo informas, validamos el dígito verificador.</div>
                    @endif
                </div>

                <div class="ancho">
                    <label for="address">Dirección <span class="opcional">(opcional)</span></label>
                    <input type="text" id="address" name="address" value="{{ $valores['address'] ?? '' }}">
                </div>

                <div>
                    <label for="billing_email">Correo de facturación <span class="opcional">(opcional)</span></label>
                    <input type="email" id="billing_email" name="billing_email"
                           value="{{ $valores['billing_email'] ?? '' }}"
                           @if ($errores->has('billing_email')) aria-invalid="true" @endif>
                    @if ($errores->has('billing_email'))
                        <div class="error-campo">{{ $errores->first('billing_email') }}</div>
                    @endif
                </div>

                <div>
                    <label for="phone">Teléfono <span class="opcional">(opcional)</span></label>
                    <input type="tel" id="phone" name="phone" value="{{ $valores['phone'] ?? '' }}">
                </div>
            </div>

            <div class="acciones">
                <a href="{{ route('inscripcion.responsable', ['token' => $token]) }}" class="btn btn-secundario">Volver</a>
                <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Continuar</button>
            </div>
        </div>
    </form>
@endsection
