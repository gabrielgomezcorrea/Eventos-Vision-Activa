{{--
    Pie de ayuda, igual en todos los correos.

    La persona que recibe esto no sabe a quién escribirle si algo se atasca, y
    dejarla sin salida termina en una llamada a la oficina o en un abandono.

    El contacto es uno solo para todo el sistema y lo define Administración en
    Mi perfil; un evento puede tener el suyo propio y entonces manda ese, porque
    hay seminarios que los atiende otra persona.
--}}
@php
    $correoContacto = $evento->contact_email ?: \App\Models\Setting::valor(\App\Models\Setting::CONTACTO_CORREO);
    $telefonoContacto = $evento->contact_phone ?: \App\Models\Setting::valor(\App\Models\Setting::CONTACTO_TELEFONO);
@endphp
@if ($correoContacto || $telefonoContacto)
---

**¿Tienes dudas?** Contáctanos a:
@if ($correoContacto)
- Correo: {{ $correoContacto }}
@endif
@if ($telefonoContacto)
- Teléfono: {{ $telefonoContacto }}
@endif

Te respondemos a la brevedad.
@endif
