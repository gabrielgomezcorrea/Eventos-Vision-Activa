import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, KeyRound, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import UsuarioController from '@/actions/App/Http/Controllers/Configuracion/UsuarioController';
import { Campo } from '@/components/campo';
import { Confirmar } from '@/components/confirmar';
import { EditarSeccion } from '@/components/editar-seccion';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { ElegirRoles, MatrizRoles } from '@/components/roles-de-usuario';
import type { FilaMatriz, OpcionRol } from '@/components/roles-de-usuario';
import { Dato, Datos, SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

type Props = {
    usuario: {
        id: number;
        nombre: string;
        correo: string;
        roles: string[];
        etiquetas: string[];
        activo: boolean;
        creado: string | null;
    };
    soyYo: boolean;
    puedeEliminar: boolean;
    roles: OpcionRol[];
    matriz: FilaMatriz[];
};

function Panel({
    titulo,
    trigger,
    abierto,
    onOpenChange,
    onSubmit,
    procesando,
    ancho = false,
    children,
}: {
    titulo: string;
    trigger: ReactNode;
    abierto: boolean;
    onOpenChange: (valor: boolean) => void;
    onSubmit: (e: FormEvent) => void;
    procesando: boolean;
    ancho?: boolean;
    children: ReactNode;
}) {
    return (
        <Sheet open={abierto} onOpenChange={onOpenChange}>
            <SheetTrigger asChild>{trigger}</SheetTrigger>
            <SheetContent
                className={
                    ancho
                        ? 'w-full overflow-y-auto sm:max-w-2xl'
                        : 'w-full overflow-y-auto sm:max-w-lg'
                }
            >
                <form onSubmit={onSubmit} className="flex min-h-full flex-col">
                    <SheetHeader>
                        <SheetTitle>{titulo}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {titulo}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex-1 space-y-5 px-4">{children}</div>
                    <SheetFooter>
                        <Button type="submit" disabled={procesando}>
                            {procesando ? 'Guardando…' : 'Guardar'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function EditarAcceso({
    usuario,
    soyYo,
    roles,
    matriz,
}: Omit<Props, 'puedeEliminar'>) {
    const [abierto, setAbierto] = useState(false);
    const inicial = { roles: usuario.roles, is_active: usuario.activo };
    const form = useForm(inicial);

    function guardar(e: FormEvent) {
        e.preventDefault();
        form.patch(UsuarioController.update.url(usuario.id), {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Panel
            titulo="Acceso"
            ancho
            trigger={
                <Button variant="ghost" size="icon" aria-label="Editar acceso">
                    <Pencil />
                </Button>
            }
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.setData(inicial);
                    form.clearErrors();
                }
            }}
            onSubmit={guardar}
            procesando={form.processing}
        >
            <ElegirRoles
                roles={roles}
                elegidos={form.data.roles}
                onChange={(elegidos) => form.setData('roles', elegidos)}
                error={form.errors.roles}
            />
            <MatrizRoles
                roles={roles}
                filas={matriz}
                resaltar={form.data.roles}
            />
            <div className="space-y-1">
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="activo"
                        checked={form.data.is_active}
                        disabled={soyYo}
                        onCheckedChange={(valor) =>
                            form.setData('is_active', valor === true)
                        }
                    />
                    <Label htmlFor="activo">Puede entrar al sistema</Label>
                </div>
                {soyYo ? (
                    <p className="text-muted-foreground text-xs">
                        No puedes desactivar tu propia cuenta.
                    </p>
                ) : (
                    !form.data.is_active && (
                        <p className="text-xs text-amber-700 dark:text-amber-400">
                            Pierde el acceso de inmediato, incluso si tiene la
                            sesión abierta. Su historial se conserva.
                        </p>
                    )
                )}
                <InputError message={form.errors.is_active} />
            </div>
        </Panel>
    );
}

function AsignarContrasena({ usuario }: { usuario: Props['usuario'] }) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm({ password: '' });

    function guardar(e: FormEvent) {
        e.preventDefault();
        form.patch(UsuarioController.update.url(usuario.id), {
            preserveScroll: true,
            onSuccess: () => setAbierto(false),
        });
    }

    return (
        <Panel
            titulo={`Asignar una contraseña nueva a ${usuario.nombre}`}
            trigger={
                <Button variant="outline" size="sm">
                    <KeyRound />
                    Asignar contraseña nueva
                </Button>
            }
            abierto={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
            onSubmit={guardar}
            procesando={form.processing}
        >
            <Campo
                label="Contraseña nueva"
                htmlFor="password"
                error={form.errors.password}
            >
                <PasswordInput
                    id="password"
                    required
                    autoComplete="new-password"
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                />
            </Campo>
            <p className="text-muted-foreground text-sm">
                Para cuando la persona olvidó la suya y no le llega el correo de
                recuperación. Díctasela por un canal seguro.
            </p>
        </Panel>
    );
}

export default function Usuario({
    usuario,
    soyYo,
    puedeEliminar,
    roles,
    matriz,
}: Props) {
    return (
        <>
            <Head title={usuario.nombre} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Button variant="ghost" size="sm" asChild className="-ml-3">
                        <Link href={UsuarioController.index()}>
                            <ArrowLeft />
                            Usuarios
                        </Link>
                    </Button>
                    <div className="flex flex-wrap gap-2">
                        {!soyYo && <AsignarContrasena usuario={usuario} />}
                        {puedeEliminar && (
                            <Confirmar
                                titulo={`Eliminar a ${usuario.nombre}`}
                                descripcion="Si ya registró acciones en el sistema no se podrá eliminar: en ese caso desactívalo para quitarle el acceso."
                                textoAccion="Eliminar usuario"
                                onConfirmar={() =>
                                    router.delete(
                                        UsuarioController.destroy.url(
                                            usuario.id,
                                        ),
                                    )
                                }
                            >
                                <Button variant="destructive" size="sm">
                                    <Trash2 />
                                    Eliminar
                                </Button>
                            </Confirmar>
                        )}
                    </div>
                </div>

                <SeccionFicha
                    titulo={soyYo ? `${usuario.nombre} (tú)` : usuario.nombre}
                    accion={
                        <EditarSeccion
                            titulo="Datos del usuario"
                            url={UsuarioController.update.url(usuario.id)}
                            inicial={{
                                name: usuario.nombre,
                                email: usuario.correo,
                            }}
                        >
                            {(f) => (
                                <>
                                    <Campo
                                        label="Nombre completo"
                                        htmlFor="name"
                                        error={f.errors.name}
                                    >
                                        <Input
                                            id="name"
                                            value={f.data.name}
                                            onChange={(e) =>
                                                f.set('name', e.target.value)
                                            }
                                        />
                                    </Campo>
                                    <Campo
                                        label="Correo"
                                        htmlFor="email"
                                        error={f.errors.email}
                                    >
                                        <Input
                                            id="email"
                                            type="email"
                                            value={f.data.email}
                                            onChange={(e) =>
                                                f.set('email', e.target.value)
                                            }
                                        />
                                    </Campo>
                                </>
                            )}
                        </EditarSeccion>
                    }
                >
                    <Datos>
                        <Dato etiqueta="Correo">{usuario.correo}</Dato>
                        <Dato etiqueta="Creado">{usuario.creado}</Dato>
                    </Datos>
                </SeccionFicha>

                <SeccionFicha
                    titulo="Acceso"
                    accion={
                        <EditarAcceso
                            usuario={usuario}
                            soyYo={soyYo}
                            roles={roles}
                            matriz={matriz}
                        />
                    }
                >
                    <Datos>
                        <Dato etiqueta="Rol">
                            {usuario.etiquetas.length > 0
                                ? usuario.etiquetas.join(', ')
                                : null}
                        </Dato>
                        <Dato etiqueta="Puede entrar al sistema">
                            {usuario.activo ? (
                                'Sí'
                            ) : (
                                <span className="text-red-700 dark:text-red-400">
                                    No, está desactivado
                                </span>
                            )}
                        </Dato>
                    </Datos>
                </SeccionFicha>
            </div>
        </>
    );
}
