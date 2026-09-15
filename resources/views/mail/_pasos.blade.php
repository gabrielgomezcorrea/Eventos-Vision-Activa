{{--
    Los cuatro pasos del proceso, con el actual marcado y los cumplidos con un
    visto. Va arriba del correo a propósito: lo primero que necesita saber
    alguien que abre esto días después es en qué parte del trámite quedó.

    Lista con <br> y no un stepper gráfico: los círculos con flechas se arman
    con tablas anidadas y Outlook los descuadra. El <br> es necesario porque en
    Markdown un salto de línea simple se colapsa y queda todo en un párrafo.

    Se espera $actual con el número del paso en curso.
--}}
@php
    $pasos = [
        1 => 'Recibes el programa',
        2 => 'Completas la inscripción',
        3 => 'Transfieres y subes el comprobante',
        4 => 'Recibes las credenciales con código QR',
    ];
    $enCurso = $actual ?? 0;
@endphp
<table class="aviso" width="100%" cellpadding="0" cellspacing="0" role="presentation"
       style="background-color:#F4F6F9;border-radius:8px;margin-bottom:24px">
<tr>
<td style="padding:18px 22px;font-size:18px;line-height:1.7em;color:#3f3f46">
@foreach ($pasos as $numero => $texto)
@if ($numero < $enCurso)
<span style="color:#2E7D32">✅ {{ $numero }}. {{ $texto }}</span><br>
@elseif ($numero === $enCurso)
<strong style="color:#084887">➡️ {{ $numero }}. {{ $texto }} — estás aquí</strong><br>
@else
<span style="color:#8b8b93">{{ $numero }}. {{ $texto }}</span><br>
@endif
@endforeach
</td>
</tr>
</table>
