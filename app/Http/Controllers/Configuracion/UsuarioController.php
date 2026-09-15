<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\UsuarioRequest;
use App\Models\User;
use App\Support\MatrizDeRoles;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El equipo interno. Se administra de vez en cuando, por eso vive en
 * Configuración y no en el menú lateral.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('settings/usuarios/index', [
            'usuarios' => User::query()
                ->with('roles')
                ->latest()
                ->paginate(15)
                ->withQueryString()
                ->through(fn (User $usuario): array => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                    'correo' => $usuario->email,
                    'roles' => self::etiquetasDeRoles($usuario),
                    'activo' => $usuario->is_active,
                    'soy_yo' => $usuario->is($request->user()),
                ]),
            ...self::opciones(),
        ]);
    }

    public function store(UsuarioRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $usuario = User::create([
            ...Arr::only($datos, ['name', 'email']),
            'password' => $datos['password'],
            'is_active' => $datos['is_active'] ?? true,
        ]);
        $usuario->syncRoles($datos['roles']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Usuario creado. {$usuario->name} ya puede entrar con su correo y la contraseña que le asignaste."]);

        return to_route('usuarios.show', $usuario);
    }

    public function show(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        return Inertia::render('settings/usuarios/show', [
            'usuario' => [
                'id' => $user->id,
                'nombre' => $user->name,
                'correo' => $user->email,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'etiquetas' => self::etiquetasDeRoles($user),
                'activo' => $user->is_active,
                'creado' => $user->created_at?->format('d-m-Y'),
            ],
            'soyYo' => $user->is($request->user()),
            'puedeEliminar' => $request->user()->can('delete', $user),
            ...self::opciones(),
        ]);
    }

    public function update(UsuarioRequest $request, User $user): RedirectResponse
    {
        $datos = $request->validated();

        if (array_key_exists('password', $datos)) {
            $user->forceFill(['password' => Hash::make($datos['password'])])->save();
        }

        $user->update(Arr::only($datos, ['name', 'email', 'is_active']));

        if (array_key_exists('roles', $datos)) {
            $user->syncRoles($datos['roles']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => array_key_exists('password', $datos)
            ? 'Contraseña asignada. Avísale a la persona para que la use al entrar.'
            : 'Cambios guardados.']);

        return back();
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        try {
            $user->delete();
        } catch (QueryException) {
            // Revisó pagos o registró acciones: borrarlo dejaría la auditoría
            // sin autor. Para sacarlo del sistema se desactiva.
            Inertia::flash('toast', ['type' => 'error', 'message' => "No se puede eliminar a {$user->name}: tiene acciones registradas. Desactívalo para quitarle el acceso."]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Usuario {$user->name} eliminado."]);

        return to_route('usuarios.index');
    }

    /**
     * @return array<int, string>
     */
    private static function etiquetasDeRoles(User $usuario): array
    {
        return $usuario->getRoleNames()
            ->map(fn (string $nombre): string => Rol::tryFrom($nombre)?->label() ?? $nombre)
            ->values()
            ->all();
    }

    /**
     * Los roles para elegir y la tabla de qué puede hacer cada uno, que se
     * muestra justo donde se decide el rol de una persona.
     *
     * @return array<string, mixed>
     */
    private static function opciones(): array
    {
        return [
            'roles' => collect(Rol::cases())
                ->map(fn (Rol $rol): array => ['value' => $rol->value, 'label' => $rol->label()])
                ->all(),
            'matriz' => MatrizDeRoles::filas(),
        ];
    }
}
