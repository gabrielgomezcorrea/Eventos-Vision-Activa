import { Form, Head, useForm, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { ListaDeAvisos } from '@/components/avisos-por-correo';
import type { Aviso } from '@/components/avisos-por-correo';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

function AvisosPorCorreo({ avisos }: { avisos: Aviso[] }) {
    const form = useForm<Record<string, boolean>>(
        Object.fromEntries(avisos.map((a) => [a.campo, a.activo])),
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.patch(ProfileController.update.url(), {
                    preserveScroll: true,
                });
            }}
            className="bg-card space-y-5 rounded-xl border p-5 shadow-xs"
        >
            <ListaDeAvisos
                avisos={avisos}
                valores={form.data}
                onChange={(campo, activo) => form.setData(campo, activo)}
                errores={form.errors}
                prefijo="perfil"
            />
            <Button disabled={form.processing}>
                {form.processing ? 'Guardando…' : 'Guardar'}
            </Button>
        </form>
    );
}

export default function Profile({ avisos }: { avisos: Aviso[] }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Mi perfil" />

            <h1 className="sr-only">Mi perfil</h1>

            <div className="max-w-xl space-y-6">
                <Heading
                    variant="small"
                    title="Mi perfil"
                    description="Actualiza tu nombre y tu correo"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="bg-card space-y-6 rounded-xl border p-5 shadow-xs"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nombre completo</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Nombre completo"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Correo</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="correo@ejemplo.cl"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {processing ? 'Guardando…' : 'Guardar'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {avisos.length > 0 && (
                    <>
                        <Heading
                            variant="small"
                            title="Avisos por correo"
                            description="Elige de qué te avisamos por correo"
                        />
                        <AvisosPorCorreo avisos={avisos} />
                    </>
                )}
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Mi perfil',
            href: edit(),
        },
    ],
};
