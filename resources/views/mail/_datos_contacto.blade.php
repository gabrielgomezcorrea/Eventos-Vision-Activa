{{--
    Event contact, one line per field, missing fields skipped. Shared by the
    footer and by any message that sends the person to ask a human: the contact
    goes right there, not only at the bottom (meeting of 17/09/2026).
--}}
@php
    // Older events may still store the phone as +569...: both forms show the same.
    $normal = fn (?string $telefono): ?string => \App\Support\Texto::telefono($telefono) ?? $telefono;
    $legible = fn (?string $telefono): ?string => preg_match('/^56(9)(\d{4})(\d{4})$/', (string) $normal($telefono), $m)
        ? "+56 {$m[1]} {$m[2]} {$m[3]}"
        : $telefono;
    $lineas = array_values(array_filter([
        $evento->contact_name ? '**'.e($evento->contact_name).'**' : null,
        $evento->contact_role ? e($evento->contact_role) : null,
        $evento->contact_organization ? e($evento->contact_organization) : null,
        $evento->contact_phone ? 'Teléfono: '.e($legible($evento->contact_phone)) : null,
        $evento->contact_email ? 'Correo: '.e($evento->contact_email) : null,
        $evento->contact_whatsapp
            ? 'WhatsApp: ['.e($legible($evento->contact_whatsapp)).'](https://wa.me/'.ltrim((string) $normal($evento->contact_whatsapp), '+').')'
            : null,
    ]));
@endphp
@if ($lineas !== [])
{!! implode("<br>\n", $lineas) !!}
@endif
