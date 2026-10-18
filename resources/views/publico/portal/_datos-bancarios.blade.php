{{--
    Datos para transferir, los mismos en la tarjeta de la pestaña Pago y en la
    caja de la columna izquierda. Variables: $event, $referencia y, opcional,
    $apilado (columna angosta), $monto (en la caja se muestra; en la tarjeta ya está en el resumen).
--}}
<table @if ($apilado ?? false) class="tabla-apilada" @endif>
    <tbody>
        <tr><td style="width:38%;color:var(--texto-suave)">Titular</td><td>{{ $event->bank_holder_name }}</td></tr>
        <tr><td style="color:var(--texto-suave)">RUT</td><td>{{ $event->bank_holder_rut }}</td></tr>
        <tr><td style="color:var(--texto-suave)">Banco</td><td>{{ $event->bank_name }}</td></tr>
        <tr><td style="color:var(--texto-suave)">Tipo de cuenta</td><td>{{ $event->bank_account_type }}</td></tr>
        <tr><td style="color:var(--texto-suave)">N° de cuenta</td><td>{{ $event->bank_account_number }}</td></tr>
        @if ($event->bank_email)
            <tr><td style="color:var(--texto-suave)">Correo</td><td>{{ $event->bank_email }}</td></tr>
        @endif
        @isset($monto)
            <tr><td style="color:var(--texto-suave)">Monto</td><td><strong>${{ number_format($monto, 0, ',', '.') }}</strong></td></tr>
        @endisset
        <tr><td style="color:var(--texto-suave)">Referencia</td><td><strong>{{ $referencia }}</strong></td></tr>
    </tbody>
</table>
