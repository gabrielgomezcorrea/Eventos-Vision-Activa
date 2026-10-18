import { useForm } from '@inertiajs/react';
import { Campo } from '@/components/campo';
import { SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

export type Nota = {
    id: number;
    fecha: string;
    autor: string | null;
    texto: string;
};

/**
 * Seguimiento con notas fechadas: se escribe una y queda en la lista, de la más
 * nueva a la más vieja, con quién y cuándo. No se editan ni se borran.
 */
export function NotasConFecha({
    titulo = 'Seguimiento',
    url,
    notas,
}: {
    titulo?: string;
    url: string;
    notas: Nota[];
}) {
    const form = useForm({ body: '' });

    return (
        <SeccionFicha titulo={titulo}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(url, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
                className="space-y-3"
            >
                <Campo
                    label="Nueva nota"
                    htmlFor="nota"
                    error={form.errors.body}
                >
                    <Textarea
                        id="nota"
                        rows={3}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                </Campo>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Guardando…' : 'Guardar nota'}
                </Button>
            </form>
            {notas.length > 0 && (
                <ul className="mt-5 divide-y rounded-lg border">
                    {notas.map((nota) => (
                        <li key={nota.id} className="space-y-1 px-4 py-3">
                            <p className="text-muted-foreground text-xs">
                                {nota.fecha}
                                {nota.autor ? ` · ${nota.autor}` : ''}
                            </p>
                            <p className="text-sm whitespace-pre-line">
                                {nota.texto}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </SeccionFicha>
    );
}
