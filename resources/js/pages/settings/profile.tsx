import { Form, Head, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

type Contacto = {
    contacto_email: string | null;
    contacto_telefono: string | null;
};

export default function Profile({ contacto }: { contacto: Contacto | null }) {
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
                                <Label htmlFor="name">Nombre</Label>

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
            </div>

            {/* Solo Administración: es el contacto que ve todo cliente al pie
                de cada correo, no un dato personal de quien tiene la sesión
                abierta. */}
            {contacto && (
                <div className="max-w-xl space-y-6">
                    <Heading
                        variant="small"
                        title="Contacto de eventos"
                        description="El correo y el teléfono de ayuda al pie de todos los correos"
                    />

                    <Form
                        {...ProfileController.actualizarContacto.form()}
                        options={{ preserveScroll: true }}
                        className="bg-card space-y-6 rounded-xl border p-5 shadow-xs"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="contacto_email">
                                        Correo de contacto
                                    </Label>
                                    <Input
                                        id="contacto_email"
                                        name="contacto_email"
                                        type="email"
                                        defaultValue={
                                            contacto.contacto_email ?? ''
                                        }
                                        placeholder="contacto@visionactiva.cl"
                                    />
                                    <InputError
                                        message={errors.contacto_email}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="contacto_telefono">
                                        Teléfono de contacto
                                    </Label>
                                    <Input
                                        id="contacto_telefono"
                                        name="contacto_telefono"
                                        type="tel"
                                        defaultValue={
                                            contacto.contacto_telefono ?? ''
                                        }
                                        placeholder="+56912345678"
                                    />
                                    <InputError
                                        message={errors.contacto_telefono}
                                    />
                                </div>

                                <Button disabled={processing}>
                                    {processing ? 'Guardando…' : 'Guardar'}
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            )}
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
