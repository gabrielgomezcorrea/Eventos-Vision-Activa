<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\CuentaBancariaRequest;
use App\Models\BankAccount;
use App\Models\Event;
use App\Support\Rut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cuentas bancarias de la empresa. Son globales: se cargan una vez y cada
 * evento elige cuál usar.
 */
class CuentaBancariaController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', BankAccount::class);

        return Inertia::render('settings/cuentas-bancarias/index', [
            'cuentas' => BankAccount::query()
                ->withCount('events')
                ->latest()
                ->get()
                ->map(fn (BankAccount $cuenta): array => [
                    'id' => $cuenta->id,
                    'label' => $cuenta->label,
                    'banco' => $cuenta->bank_name,
                    'numero' => $cuenta->account_number,
                    'titular' => $cuenta->holder_name,
                    'eventos' => $cuenta->events_count,
                    'activa' => $cuenta->is_active,
                ])
                ->all(),
            // Desde la ficha de un evento sin cuenta se llega aquí con el
            // formulario de creación ya abierto.
            'abrirNueva' => $request->boolean('nueva'),
            'opciones' => self::opciones(),
        ]);
    }

    public function store(CuentaBancariaRequest $request): RedirectResponse
    {
        $cuenta = BankAccount::create(self::datos($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuenta creada. Ya se puede elegir en los eventos.']);

        return to_route('cuentas-bancarias.show', $cuenta);
    }

    public function show(Request $request, BankAccount $bankAccount): Response
    {
        Gate::authorize('view', $bankAccount);

        $eventos = $bankAccount->events()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('settings/cuentas-bancarias/show', [
            'cuenta' => [
                'id' => $bankAccount->id,
                'label' => $bankAccount->label,
                'holder_name' => $bankAccount->holder_name,
                'holder_rut' => $bankAccount->holder_rut,
                'bank_name' => $bankAccount->bank_name,
                'account_type' => $bankAccount->account_type,
                'account_number' => $bankAccount->account_number,
                'email' => $bankAccount->email,
                'payment_instructions' => $bankAccount->payment_instructions,
                'is_active' => $bankAccount->is_active,
            ],
            'eventos' => $eventos->map(fn (Event $evento): array => ['id' => $evento->id, 'nombre' => $evento->name])->all(),
            'opciones' => self::opciones(),
        ]);
    }

    public function update(CuentaBancariaRequest $request, BankAccount $bankAccount): RedirectResponse
    {
        $bankAccount->update(self::datos($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuenta guardada.']);

        return back();
    }

    public function destroy(BankAccount $bankAccount): RedirectResponse
    {
        Gate::authorize('delete', $bankAccount);

        // Un evento sin cuenta deja al cliente sin saber dónde transferir.
        if ($bankAccount->events()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'No se puede eliminar: hay eventos usando esta cuenta. Desactívala para que deje de ofrecerse.']);

            return back();
        }

        $bankAccount->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Cuenta «{$bankAccount->label}» eliminada."]);

        return to_route('cuentas-bancarias.index');
    }

    /**
     * @return array{bancos: array<int, string>, tiposDeCuenta: array<int, string>}
     */
    private static function opciones(): array
    {
        return [
            'bancos' => config('cuentas_bancarias.bancos'),
            'tiposDeCuenta' => config('cuentas_bancarias.tipos_de_cuenta'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function datos(CuentaBancariaRequest $request): array
    {
        $datos = $request->validated();

        // Una sola forma guardada del RUT, para poder buscarlo después.
        $datos['holder_rut'] = Rut::normalizar($datos['holder_rut'] ?? null);

        return $datos;
    }
}
