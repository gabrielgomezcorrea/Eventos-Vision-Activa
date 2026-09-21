import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import WebController from '@/actions/App/Http/Controllers/Configuracion/WebController';
import { Confirmar } from '@/components/confirmar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Props = {
    webs: string[];
};

/** Sites allowed to show the public form inside an iframe. */
export default function Webs({ webs }: Props) {
    const form = useForm({ dominio: '' });

    function agregar(e: FormEvent) {
        e.preventDefault();
        form.post(WebController.store.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <>
            <Head title="Websites" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Websites"
                    description="Sitios que pueden mostrar el formulario de los eventos."
                />

                <form onSubmit={agregar} className="max-w-md space-y-2">
                    <div className="flex gap-2">
                        <Input
                            aria-label="Website"
                            placeholder="liderazgoescolar.cl"
                            value={form.data.dominio}
                            onChange={(e) =>
                                form.setData('dominio', e.target.value)
                            }
                        />
                        <Button type="submit" disabled={form.processing}>
                            Agregar
                        </Button>
                    </div>
                    <InputError message={form.errors.dominio} />
                </form>

                <div className="rounded-lg border bg-white">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Web</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {webs.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={2}
                                        className="text-muted-foreground py-10 text-center"
                                    >
                                        Ningún website puede mostrar el
                                        formulario todavía.
                                    </TableCell>
                                </TableRow>
                            )}
                            {webs.map((web) => (
                                <TableRow key={web}>
                                    <TableCell className="pl-5 font-medium">
                                        {web}
                                    </TableCell>
                                    <TableCell className="pr-5 text-right">
                                        <Confirmar
                                            titulo={`¿Quitar ${web}?`}
                                            descripcion="El formulario dejará de mostrarse en ese website."
                                            textoAccion="Quitar"
                                            onConfirmar={() =>
                                                router.delete(
                                                    WebController.destroy.url(),
                                                    {
                                                        data: { dominio: web },
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        >
                                            <Button variant="outline" size="sm">
                                                Quitar
                                            </Button>
                                        </Confirmar>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}
