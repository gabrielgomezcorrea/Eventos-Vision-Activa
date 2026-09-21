{{--
    Suggestions for the commune fields. It suggests, never forces: a commune
    missing from the list is accepted and stored clean.
--}}
<datalist id="comunas">
    @foreach (\App\Support\Texto::comunas() as $comuna)
        <option value="{{ $comuna }}">
    @endforeach
</datalist>
