{{--
    Event contact on a public page, right below any message that sends the
    person to ask a human. Inline styles: it is used by pages with different
    stylesheets (enrollment layout, ticket and invalid-link pages).
--}}
@php
    $normal = fn (?string $telefono): ?string => \App\Support\Texto::telefono($telefono) ?? $telefono;
    $legible = fn (?string $telefono): ?string => preg_match('/^56(9)(\d{4})(\d{4})$/', (string) $normal($telefono), $m)
        ? "+56 {$m[1]} {$m[2]} {$m[3]}"
        : $telefono;
@endphp
@if ($evento?->tieneContacto())
    {{-- $plano: ya viene dentro de una caja (el menú lateral del estado), así
         que no dibuja la suya. Una caja dentro de otra se ve como un error. --}}
    <div @if ($plano ?? false) style="color:#1F2937;font-size:.88rem;line-height:1.6;text-align:left"
         @else style="margin-top:12px;padding:14px 16px;border:1px solid #D0D5DD;border-radius:8px;background:#fff;color:#1F2937;font-size:.92rem;line-height:1.6;text-align:left" @endif>
        @if ($evento->contact_name)<strong>{{ $evento->contact_name }}</strong><br>@endif
        @if ($evento->contact_role){{ $evento->contact_role }}<br>@endif
        @if ($evento->contact_organization){{ $evento->contact_organization }}<br>@endif
        @if ($evento->contact_phone)Teléfono: <a href="tel:+{{ ltrim((string) $normal($evento->contact_phone), '+') }}">{{ $legible($evento->contact_phone) }}</a><br>@endif
        @if ($evento->contact_email)Correo: <a href="mailto:{{ $evento->contact_email }}">{{ $evento->contact_email }}</a><br>@endif
        @if ($evento->contact_whatsapp)WhatsApp: <a href="https://wa.me/{{ ltrim((string) $normal($evento->contact_whatsapp), '+') }}">{{ $legible($evento->contact_whatsapp) }}</a>@endif
    </div>
@endif
