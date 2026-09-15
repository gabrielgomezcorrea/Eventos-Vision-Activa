{{--
    Política de privacidad.

    El texto legal definitivo lo redacta y valida la empresa: esto es la
    estructura y el contenido verificable de lo que el sistema hace hoy, para
    que el enlace del formulario no lleve a una página en blanco.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Política de privacidad · {{ config('app.name') }}</title>
    <style>
        :root { --primario:#084887; --texto:#1F2937; --texto-suave:#667085; --borde:#D0D5DD; --fondo:#F7F5FB; }
        *{box-sizing:border-box}
        body{margin:0;background:var(--fondo);color:var(--texto);
             font:17px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .envoltura{max-width:720px;margin:0 auto;padding:40px 18px 60px}
        .tarjeta{background:#fff;border:1px solid var(--borde);border-radius:12px;padding:34px}
        h1{font-size:1.5rem;margin:0 0 6px}
        h2{font-size:1.1rem;margin:30px 0 8px}
        p,li{margin:0 0 12px}
        ul{padding-left:22px}
        .actualizado{color:var(--texto-suave);font-size:.88rem;margin:0 0 26px}
        a{color:var(--primario)}
        @media(max-width:480px){.tarjeta{padding:22px 18px}}
    </style>
</head>
<body>
<div class="envoltura">
    <div class="tarjeta">
        <h1>Política de privacidad</h1>
        <p class="actualizado">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>

        <h2>Qué datos pedimos</h2>
        <p>
            Nombre, apellidos, correo electrónico, teléfono, cargo y establecimiento.
            Al inscribirte pedimos además los datos de las personas que participan y
            de la entidad que paga. El RUT es opcional y solo se solicita cuando se
            necesita para emitir un certificado o un documento tributario.
        </p>

        <h2>Para qué los usamos</h2>
        <ul>
            <li>Enviarte el programa del evento que solicitaste.</li>
            <li>Gestionar tu inscripción, el pago y la emisión de credenciales.</li>
            <li>Acreditar tu ingreso el día del evento.</li>
            <li>Emitir y enviarte el documento tributario correspondiente.</li>
        </ul>
        <p>
            Solo enviamos información sobre otros eventos a quienes marcaron esa
            casilla en el formulario. Puedes pedir que dejemos de hacerlo cuando
            quieras, escribiéndonos al correo de contacto.
        </p>

        <h2>Quién accede a ellos</h2>
        <p>
            Solo el personal de la organización que participa en el proceso, según
            su función: coordinación, contabilidad y acreditación. No vendemos ni
            cedemos datos a terceros.
        </p>

        <h2>Cuánto tiempo los guardamos</h2>
        <p>
            Mientras dure la relación con el evento y el tiempo que exija la
            normativa tributaria para los documentos asociados al pago.
        </p>

        <h2>Tus derechos</h2>
        <p>
            Puedes pedir acceder a tus datos, corregirlos, eliminarlos u oponerte a
            su uso. Escríbenos y respondemos por el mismo medio.
        </p>

        <h2>Seguridad</h2>
        <p>
            La conexión viaja cifrada. Los comprobantes y documentos se guardan en
            almacenamiento privado, accesible solo por el personal autorizado y
            registrando cada descarga.
        </p>

        <h2>Contacto</h2>
        <p>
            Para cualquier consulta sobre tus datos, escríbenos a
            <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.
        </p>
    </div>
</div>
</body>
</html>
