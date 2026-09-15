<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('titulo') · Acreditación</title>
    @include('acreditacion._estilos')
</head>
<body>
<div class="envoltura">
    <div class="barra">
        <h1>Acreditación</h1>
        <span class="usuario">{{ auth()->user()?->name }}</span>
    </div>

    @yield('contenido')
</div>
@stack('scripts')
</body>
</html>
