import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import UsuarioController from '@/actions/App/Http/Controllers/Configuracion/UsuarioController';
import { Campo } from '@/components/campo';
import Heading from '@/components/heading';
import { Paginacion } from '@/components/paginacion';
import PasswordInput from '@/components/password-input';
import { ElegirRoles, MatrizRoles } from '@/components/roles-de-usuario';
import type { FilaMatriz, OpcionRol } from '@/components/roles-de-usuario';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Paginado } from '@/types';

type Usuario = {
    id: number;
    nombre: string;
    correo: string;
    roles: string[];
    activo: boolean;
    soy_yo: boolean;
};

type Props = {
    usuarios: Paginado<Usuario>;
    roles: OpcionRol[];
    matriz: FilaMatriz[];
};

function NuevoUsuario({
    roles,
    matriz,
}: {
    roles: OpcionRol[];
    matriz: FilaMatriz[];
}) {
    const [abierto, setAbierto] = useState(false);
    const form = useForm<{
        name: string;
        email: string;
        password: string;
        roles: string[];
    }>({
        name: '',
        email: '',
        password: '',
        roles: [],
    });

    function crear(e: FormEvent) {
        e.preventDefault();
        form.post(UsuarioController.store.url());
    }

    return (
        <Dialog
            open={abierto}
            onOpenChange={(valor) => {
                setAbierto(valor);

                if (valor) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus />
                    Nuevo usuario
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <form onSubmit={crear} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Nuevo usuario</DialogTitle>
                        <DialogDescription>
                            Entra con su correo y la contraseña que le asignes
                            aquí.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Campo
                            label="Nombre completo"
                            htmlFor="u-name"
                            error={form.errors.name}
                        >
                            <Input
                                id="u-name"
                                required
                                autoComplete="off"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </Campo>
                        <Campo
                            label="Correo"
                            htmlFor="u-email"
                            error={form.errors.email}
                        >
                            <Input
                                id="u-email"
                                type="email"
                                required
                                autoComplete="off"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        </Campo>
                    </div>
                    <Campo
                        label="Contraseña"
                        htmlFor="u-password"
                        error={form.errors.password}
                    >
                        <PasswordInput
                            id="u-password"
                            required
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                    </Campo>
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
                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creando…' : 'Crear usuario'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Usuarios({ usuarios, roles, matriz }: Props) {
    return (
        <>
            <Head title="Usuarios" />
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3">
                    <Heading variant="small" title="Usuarios" />
                    <NuevoUsuario roles={roles} matriz={matriz} />
                </div>

                <div className="bg-card rounded-xl border shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Nombre</TableHead>
                                <TableHead>Rol</TableHead>
                                <TableHead>Acceso</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {usuarios.data.map((usuario) => (
                                <TableRow
                                    key={usuario.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.visit(
                                            UsuarioController.show.url(
                                                usuario.id,
                                            ),
                                        )
                                    }
                                >
                                    <TableCell className="pl-5">
                                        <Link
                                            href={UsuarioController.show(
                                                usuario.id,
                                            )}
                                            className="font-medium hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {usuario.nombre}
                                        </Link>
                                        {usuario.soy_yo && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (tú)
                                            </span>
                                        )}
                                        <span className="text-muted-foreground block text-xs">
                                            {usuario.correo}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {usuario.roles.length === 0 ? (
                                            <span className="text-amber-700 dark:text-amber-400">
                                                Sin rol
                                            </span>
                                        ) : (
                                            <span className="flex flex-wrap gap-1">
                                                {usuario.roles.map((rol) => (
                                                    <span
                                                        key={rol}
                                                        className="bg-muted rounded-md px-2 py-0.5 text-xs"
                                                    >
                                                        {rol}
                                                    </span>
                                                ))}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {usuario.activo ? (
                                            'Activo'
                                        ) : (
                                            <span className="text-red-700 dark:text-red-400">
                                                Desactivado
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Paginacion pagina={usuarios} />
            </div>
        </>
    );
}
