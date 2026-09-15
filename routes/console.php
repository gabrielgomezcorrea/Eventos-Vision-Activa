<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El vencimiento de reservas es la unica via por la que una reserva expira.
// Nunca debe depender de que alguien visite una pagina.
Schedule::command('reservas:expirar')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Avisar antes de vencer. Vencer una reserva sin haber avisado es perder una
// venta que ya estaba hecha.
Schedule::command('reservas:recordar')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Base y archivos privados juntos, de madrugada en hora de Chile.
Schedule::command('respaldo:crear')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();
