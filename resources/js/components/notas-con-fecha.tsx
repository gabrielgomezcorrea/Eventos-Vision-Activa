import { useForm } from '@inertiajs/react';
import { Campo } from '@/components/campo';
import { NativeSelect } from '@/components/native-select';
import { SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

export type Nota = {
    id: number;
    fecha: string;
    autor: string | null;
    texto: string;
    /** Marca que dejó la nota («No participará»); null en una nota común. */
    tipo?: string | null;
};

export type TipoDeNota = { value: string; label: string; texto?: string };

/**
 * Seguimiento con notas fechadas: se escribe una y queda en la lista, de la más
 * nueva a la más vieja, con quién y cuándo. No se editan ni se borran.
 */
export function NotasConFecha({
    titulo = 'Seguimiento',
    url,
    notas,
    tipos,
}: {
    titulo?: string;
    url: string;
    notas: Nota[];
    /** Tipos además de la nota común. Sin ellos no se dibuja el selector. */
    tipos?: TipoDeNota[];
}) {
    const form = useForm({ body: '', tipo: '' });

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
                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Guardando…' : 'Guardar nota'}
                    </Button>
                    {tipos && tipos.length > 0 && (
                        <label className="flex items-center gap-2 text-sm">
                            Elegir tipo:
                            <NativeSelect
                                className="w-auto"
                                value={form.data.tipo}
                                onChange={(e) => {
                                    const elegido = tipos.find(
                                        (t) => t.value === e.target.value,
                                    );
                                    form.setData((datos) => ({
                                        tipo: e.target.value,
                                        body:
                                            elegido?.texto &&
                                            datos.body.trim() === ''
                                                ? elegido.texto
                                                : datos.body,
                                    }));
                                }}
                            >
                                <option value="">Nota de seguimiento</option>
                                {tipos.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                    )}
                </div>
            </form>
            {notas.length > 0 && (
                <ul className="mt-5 divide-y rounded-lg border">
                    {notas.map((nota) => (
                        <li key={nota.id} className="space-y-1 px-4 py-3">
                            <p className="text-muted-foreground text-xs">
                                {nota.fecha}
                                {nota.autor ? ` · ${nota.autor}` : ''}
                                {nota.tipo && (
                                    <span className="bg-muted text-muted-foreground ml-2 rounded-md px-1.5 py-0.5 font-medium">
                                        {nota.tipo}
                                    </span>
                                )}
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
