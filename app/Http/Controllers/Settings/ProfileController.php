<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ContactoDeEventosRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            // El contacto de los correos es global y lo define Administración.
            // Quien no puede tocarlo no ve la tarjeta.
            'contacto' => $request->user()->can(Permiso::GestionarUsuarios->value)
                ? [
                    'contacto_email' => Setting::valor(Setting::CONTACTO_CORREO),
                    'contacto_telefono' => Setting::valor(Setting::CONTACTO_TELEFONO),
                ]
                : null,
        ]);
    }

    /**
     * Correo y teléfono de ayuda que van al pie de todos los correos.
     */
    public function actualizarContacto(ContactoDeEventosRequest $request): RedirectResponse
    {
        Setting::guardar($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contacto de eventos actualizado.']);

        return to_route('profile.edit');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perfil actualizado.']);

        return to_route('profile.edit');
    }
}
