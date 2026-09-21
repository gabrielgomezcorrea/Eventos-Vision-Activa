{{--
    Pie de ayuda, igual en todos los correos.

    Los correos salen de una casilla que nadie lee: por eso dicen que no se
    respondan y siempre traen a quién escribir. El contacto es de cada evento y
    se configura en su formulario público.
--}}
---

@if ($evento->contact_email || $evento->contact_phone || $evento->contact_whatsapp)
**¿Tienes dudas?** Contáctanos:

@include('mail._datos_contacto', ['evento' => $evento])

@endif
<small>Este correo se envía automáticamente: por favor no lo respondas. Escríbenos a los datos de contacto.</small>
