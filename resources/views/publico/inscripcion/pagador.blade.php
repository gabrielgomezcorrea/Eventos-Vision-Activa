@extends('publico.layout')
@section('titulo', 'Facturación')
@section('subtitulo', 'Entidad que realiza el pago')

@section('contenido')
    <form method="POST" action="{{ route('inscripcion.pagador.guardar', ['token' => $token]) }}" novalidate>
        <div class="tarjeta">
            <h2>Entidad pagadora (Datos de facturación)</h2>
            <p class="sub">
                Ingresa los datos de la entidad que realizará el pago y a cuyo nombre se emitirá
                la factura. Puede ser distinta del establecimiento participante.
            </p>

            <div class="rejilla">
                @include('publico.inscripcion._campo', ['campo' => 'name', 'etiqueta' => 'Nombre o razón social', 'ancho' => true])
                @include('publico.inscripcion._campo', ['campo' => 'rut', 'etiqueta' => 'RUT', 'placeholder' => '76.086.428-5', 'autocomplete' => 'off', 'atributos' => 'data-rut maxlength="12"'])
                @include('publico.inscripcion._campo', ['campo' => 'address', 'etiqueta' => 'Dirección', 'autocomplete' => 'street-address'])
                @include('publico.inscripcion._campo', ['campo' => 'commune', 'etiqueta' => 'Comuna', 'autocomplete' => 'off', 'atributos' => 'list="comunas"'])
                @include('publico.inscripcion._campo', ['campo' => 'billing_email', 'etiqueta' => 'Correo de facturación', 'tipoCampo' => 'email', 'autocomplete' => 'email'])
                @include('publico.inscripcion._campo', ['campo' => 'phone', 'etiqueta' => 'Teléfono', 'tipoCampo' => 'tel', 'placeholder' => '56912345678', 'autocomplete' => 'tel'])
            </div>
            @include('publico.inscripcion._comunas')

            <div class="acciones">
                <a href="{{ route('inscripcion.participantes', ['token' => $token]) }}" class="btn btn-secundario">Volver</a>
                <button type="submit" class="btn btn-primario" data-enviando-texto="Guardando…">Continuar al resumen</button>
            </div>
        </div>
    </form>
@endsection
