<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

// No hay "eliminar mi cuenta": a una persona se la desactiva en Usuarios.
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/contacto', [ProfileController::class, 'actualizarContacto'])->name('profile.contacto');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});
