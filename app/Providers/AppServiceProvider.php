<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Database\Faker\ChileProvider;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Datos chilenos para seeders y factories: RUT con dígito verificador,
        // RBD, comunas y nombres de establecimientos.
        //
        // Se registran las dos claves a propósito: las factories resuelven
        // `Faker\Generator`, mientras que el helper `fake()` resuelve una clave
        // con el locale al final. Sin ambas, el proveedor solo funciona en una
        // de las dos vías.
        $constructor = function (): Generator {
            $faker = Factory::create(config('app.faker_locale', 'en_US'));
            $faker->addProvider(new ChileProvider($faker));

            return $faker;
        };

        $this->app->singleton(Generator::class, $constructor);
        $this->app->singleton(
            Generator::class.':'.config('app.faker_locale', 'en_US'),
            $constructor,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Detrás de un proxy la petición llega como http; sin esto los
        // enlaces mágicos y los QR saldrían con http://.
        URL::forceHttps(app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
