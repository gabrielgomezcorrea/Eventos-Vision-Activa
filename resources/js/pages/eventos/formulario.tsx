import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Code,
    Copy,
    ExternalLink,
    FileText,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { toast } from 'sonner';
import EventoController from '@/actions/App/Http/Controllers/Eventos/EventoController';
import FormularioPublicoController from '@/actions/App/Http/Controllers/Eventos/FormularioPublicoController';
import { Campo } from '@/components/campo';
import { Confirmar } from '@/components/confirmar';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { SeccionFicha } from '@/components/seccion-ficha';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Pregunta = {
    key: string | null;
    label: string;
    type: string;
    required: boolean;
    placeholder: string | null;
    options: string[];
};

type Props = {
    evento: {
        id: number;
        name: string;
        admite_inscripciones: boolean;
        consentimiento: string;
        contacto: Contacto;
        program_email_intro: string | null;
        adjuntos: { id: number; nombre: string; peso: string }[];
        max_adjuntos: number;
        max_mb: number;
        participant_positions: string[];
    };
    campos: Pregunta[];
    tipos: Record<string, string>;
    cargosParticipante: string[];
    embeber: { enlace: string; iframe: string; origenes: string | null };
};

type Contacto = {
    contact_name: string | null;
    contact_role: string | null;
    contact_organization: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    contact_whatsapp: string | null;
};

function copiar(texto: string) {
    void navigator.clipboard.writeText(texto);
    toast.success('Copiado');
}

/** Opciones de una lista: se escribe una y Enter. */
function Opciones({
    opciones,
    onChange,
}: {
    opciones: string[];
    onChange: (opciones: string[]) => void;
}) {
    const [nueva, setNueva] = useState('');

    function agregar() {
        const texto = nueva.trim();

        if (texto && !opciones.includes(texto)) {
            onChange([...opciones, texto]);
        }

        setNueva('');
    }

    function alPresionar(e: KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'Enter') {
            e.preventDefault();
            agregar();
        }
    }

    return (
        <div className="space-y-2">
            {opciones.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {opciones.map((opcion) => (
                        <span
                            key={opcion}
                            className="bg-muted inline-flex items-center gap-1 rounded-md py-0.5 pr-1 pl-2 text-sm"
                        >
                            {opcion}
                            <button
                                type="button"
                                aria-label={`Quitar ${opcion}`}
                                className="hover:bg-background rounded p-0.5"
                                onClick={() =>
                                    onChange(
                                        opciones.filter((o) => o !== opcion),
                                    )
                                }
                            >
                                <X className="size-3" />
                            </button>
                        </span>
                    ))}
                </div>
            )}
            <div className="flex gap-2">
                <Input
                    value={nueva}
                    placeholder="Escribe una opción y presiona Enter"
                    onChange={(e) => setNueva(e.target.value)}
                    onKeyDown={alPresionar}
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={agregar}
                    disabled={!nueva.trim()}
                >
                    Agregar
                </Button>
            </div>
        </div>
    );
}

/** Así lo ve la gente: se actualiza mientras se escribe. */
function VistaPrevia({
    campos,
    consentimiento,
}: {
    campos: Pregunta[];
    consentimiento: string;
}) {
    return (
        <div className="bg-card space-y-4 rounded-xl border p-5 shadow-xs">
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                Vista previa
            </p>
            {campos.map((campo, i) => (
                <div key={i} className="grid gap-1.5">
                    <Label>
                        {campo.label || 'Pregunta sin nombre'}
                        {campo.required && (
                            <span className="text-red-600"> *</span>
                        )}
                    </Label>
                    {campo.type === 'textarea' ? (
                        <Textarea
                            disabled
                            rows={2}
                            placeholder={campo.placeholder ?? ''}
                        />
                    ) : campo.type === 'select' ? (
                        <NativeSelect disabled>
                            <option>Elige una opción</option>
                        </NativeSelect>
                    ) : (
                        <Input disabled placeholder={campo.placeholder ?? ''} />
                    )}
                </div>
            ))}
            <label className="flex items-start gap-2 text-sm">
                <Checkbox disabled className="mt-0.5" />
                {consentimiento}
            </label>
            <Button disabled className="w-full">
                Enviar
            </Button>
        </div>
    );
}

/**
 * Arma el enlace con los parámetros de campaña.
 *
 * Sin esto la atribución existe pero nadie la usa: nadie va a escribir
 * `?utm_source=...` a mano cada vez que publica una historia.
 */
function EnlaceDeCampana({
    enlace,
    copiar,
}: {
    enlace: string;
    copiar: (texto: string) => void;
}) {
    const [origen, setOrigen] = useState('instagram');
    const [campana, setCampana] = useState('');

    const limpio = campana
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    const url = limpio
        ? `${enlace}?utm_source=${origen}&utm_campaign=${limpio}`
        : enlace;

    return (
        <div className="space-y-2">
            <p className="font-medium">Enlace para una campaña</p>
            <p className="text-muted-foreground text-xs">
                Usa este enlace en anuncios, historias o el link de la
                biografía. Cada inscripción queda marcada con su campaña.
            </p>
            <div className="flex flex-wrap gap-2">
                <NativeSelect
                    aria-label="Dónde se publica"
                    className="w-auto"
                    value={origen}
                    onChange={(e) => setOrigen(e.target.value)}
                >
                    <option value="instagram">Instagram</option>
                    <option value="facebook">Facebook</option>
                    <option value="meta-ads">Anuncio pagado (Meta)</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="correo">Correo</option>
                    <option value="web">Sitio web</option>
                </NativeSelect>
                <Input
                    aria-label="Nombre de la campaña"
                    placeholder="seminario-noviembre"
                    className="w-56"
                    value={campana}
                    onChange={(e) => setCampana(e.target.value)}
                />
            </div>
            <div className="flex gap-2">
                <code className="bg-muted flex-1 rounded-md p-2 break-all">
                    {url}
                </code>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Copiar enlace de campaña"
                    onClick={() => copiar(url)}
                >
                    <Copy />
                </Button>
            </div>
        </div>
    );
}

function PonerEnLaWeb({ embeber }: { embeber: Props['embeber'] }) {
    const codigo = `<iframe src="${embeber.iframe}" title="Formulario de inscripción" style="width:100%;border:0;min-height:760px" loading="lazy"></iframe>`;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Code />
                    Poner en la web
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Publicar el formulario en otra web
                    </DialogTitle>
                    <DialogDescription>
                        Elige una de las dos opciones.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-5 text-sm">
                    <div className="space-y-2">
                        <p className="font-medium">
                            Opción 1: enlace directo (la más robusta)
                        </p>
                        <div className="flex gap-2">
                            <code className="bg-muted flex-1 rounded-md p-2 break-all">
                                {embeber.enlace}
                            </code>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Copiar enlace"
                                onClick={() => copiar(embeber.enlace)}
                            >
                                <Copy />
                            </Button>
                        </div>
                    </div>
                    <EnlaceDeCampana enlace={embeber.enlace} copiar={copiar} />

                    <div className="space-y-2">
                        <p className="font-medium">
                            Opción 2: incrustar en un bloque HTML de WordPress o
                            Elementor
                        </p>
                        <div className="flex gap-2">
                            <code className="bg-muted flex-1 rounded-md p-2 break-all">
                                {codigo}
                            </code>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Copiar código"
                                onClick={() => copiar(codigo)}
                            >
                                <Copy />
                            </Button>
                        </div>
                    </div>
                    {embeber.origenes ? (
                        <p className="text-muted-foreground">
                            Sitios autorizados a incrustar: {embeber.origenes}
                        </p>
                    ) : (
                        <p className="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                            Todavía no hay sitios autorizados a incrustar: por
                            ahora solo funciona el enlace directo. Se configuran
                            en Configuración → Websites.
                        </p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** El PDF se sube apenas se elige, y el límite se avisa antes de subir. */
function Adjuntos({
    eventoId,
    adjuntos,
    maximo,
    maxMb,
}: {
    eventoId: number;
    adjuntos: { id: number; nombre: string; peso: string }[];
    maximo: number;
    maxMb: number;
}) {
    const entrada = useRef<HTMLInputElement>(null);
    const [subiendo, setSubiendo] = useState(false);
    const [errorLocal, setErrorLocal] = useState<string | null>(null);
    const { errors } = usePage().props;

    const lleno = adjuntos.length >= maximo;

    function subir(archivo: File | undefined) {
        setErrorLocal(null);

        if (!archivo) {
            return;
        }

        // Se avisa antes de subir: descubrir el límite después de esperar la
        // subida de un archivo pesado es la peor forma de enterarse.
        if (lleno) {
            setErrorLocal(
                `Ya hay ${maximo} archivos. Quita uno antes de subir otro.`,
            );

            return;
        }

        if (!archivo.name.toLowerCase().endsWith('.pdf')) {
            setErrorLocal('El programa debe ser un PDF.');

            return;
        }

        if (archivo.size > maxMb * 1024 * 1024) {
            setErrorLocal(`«${archivo.name}» pesa más de ${maxMb} MB.`);

            return;
        }

        router.post(
            FormularioPublicoController.subirPrograma.url(eventoId),
            { programa: archivo },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setSubiendo(true),
                onFinish: () => {
                    setSubiendo(false);

                    if (entrada.current) {
                        entrada.current.value = '';
                    }
                },
            },
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor="programa">Adjuntos</Label>

            {adjuntos.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {adjuntos.map((archivo) => (
                        <li
                            key={archivo.id}
                            className="flex items-center gap-2 px-3 py-2 text-sm"
                        >
                            <FileText className="text-muted-foreground size-4 shrink-0" />
                            <span className="min-w-0 flex-1 truncate">
                                {archivo.nombre}
                            </span>
                            <span className="text-muted-foreground text-xs">
                                {archivo.peso}
                            </span>
                            <Confirmar
                                titulo={`Quitar «${archivo.nombre}»`}
                                descripcion="Dejará de viajar en el correo con el programa."
                                textoAccion="Quitar archivo"
                                onConfirmar={() =>
                                    router.delete(
                                        FormularioPublicoController.quitarPrograma.url(
                                            [eventoId, archivo.id],
                                        ),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 shrink-0 text-red-600 hover:text-red-700"
                                    aria-label={`Quitar ${archivo.nombre}`}
                                >
                                    <X className="size-4" />
                                </Button>
                            </Confirmar>
                        </li>
                    ))}
                </ul>
            )}

            {lleno ? (
                <p className="text-muted-foreground text-xs">
                    No se pueden subir más archivos. Quita uno para poder subir
                    otro.
                </p>
            ) : (
                <Input
                    ref={entrada}
                    id="programa"
                    type="file"
                    accept=".pdf,application/pdf"
                    disabled={subiendo}
                    onChange={(e) => subir(e.target.files?.[0])}
                />
            )}

            {subiendo && (
                <p className="text-muted-foreground text-xs">Subiendo…</p>
            )}
            <InputError message={errorLocal ?? errors.programa} />
        </div>
    );
}

export default function FormularioPublico({
    evento,
    campos,
    tipos,
    cargosParticipante,
    embeber,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Eventos', href: EventoController.index() },
            { title: evento.name, href: EventoController.show(evento.id) },
            { title: 'Formulario público', href: '#' },
        ],
    });

    const form = useForm({
        campos,
        contact_name: evento.contacto.contact_name ?? '',
        contact_role: evento.contacto.contact_role ?? '',
        contact_organization: evento.contacto.contact_organization ?? '',
        contact_email: evento.contacto.contact_email ?? '',
        contact_phone: evento.contacto.contact_phone ?? '',
        contact_whatsapp: evento.contacto.contact_whatsapp ?? '',
        program_email_intro: evento.program_email_intro ?? '',
        participant_positions: evento.participant_positions,
    });
    const errores = form.errors as Record<string, string | undefined>;
    const preguntas = form.data.campos;

    function cambiar(indice: number, cambios: Partial<Pregunta>) {
        form.setData(
            'campos',
            preguntas.map((p, i) => (i === indice ? { ...p, ...cambios } : p)),
        );
    }

    function mover(indice: number, destino: number) {
        const lista = [...preguntas];
        [lista[indice], lista[destino]] = [lista[destino], lista[indice]];
        form.setData('campos', lista);
    }

    function agregar() {
        form.setData('campos', [
            ...preguntas,
            {
                key: null,
                label: '',
                type: 'text',
                required: false,
                placeholder: '',
                options: [],
            },
        ]);
    }

    function guardar(e: FormEvent) {
        e.preventDefault();
        form.put(FormularioPublicoController.update.url(evento.id), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title={`Formulario público · ${evento.name}`} />
            <form
                onSubmit={guardar}
                className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6"
            >
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="space-y-0.5">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Formulario público
                        </h1>
                        {form.isDirty && (
                            <p className="text-sm text-amber-700 dark:text-amber-400">
                                Hay cambios sin guardar.
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {evento.admite_inscripciones && (
                            <PonerEnLaWeb embeber={embeber} />
                        )}
                        {evento.admite_inscripciones ? (
                            <Button variant="outline" asChild>
                                <a
                                    href={embeber.enlace}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <ExternalLink />
                                    Ver cómo queda
                                </a>
                            </Button>
                        ) : (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span>
                                        <Button variant="outline" disabled>
                                            <ExternalLink />
                                            Ver cómo queda
                                        </Button>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    Publica el evento para verlo como lo ve la
                                    gente.
                                </TooltipContent>
                            </Tooltip>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={EventoController.show(evento.id)}>
                                <ArrowLeft />
                                Volver al evento
                            </Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Guardando…'
                                : 'Guardar formulario'}
                        </Button>
                    </div>
                </header>

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-4">
                        <InputError message={errores.campos} />

                        {preguntas.map((pregunta, i) => {
                            const esCorreo = pregunta.key === 'email';
                            const nombre =
                                pregunta.label || 'Pregunta sin nombre';

                            return (
                                <div
                                    key={i}
                                    className="bg-card space-y-4 rounded-xl border p-4 shadow-xs"
                                >
                                    <div className="flex items-start gap-3">
                                        <div className="grid flex-1 gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                            <Campo
                                                label="Pregunta"
                                                htmlFor={`label-${i}`}
                                                error={
                                                    errores[`campos.${i}.label`]
                                                }
                                            >
                                                <Input
                                                    id={`label-${i}`}
                                                    value={pregunta.label}
                                                    placeholder="Nombre del establecimiento"
                                                    onChange={(e) =>
                                                        cambiar(i, {
                                                            label: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </Campo>
                                            <Campo
                                                label="Tipo"
                                                htmlFor={`type-${i}`}
                                                error={
                                                    errores[`campos.${i}.type`]
                                                }
                                            >
                                                <NativeSelect
                                                    id={`type-${i}`}
                                                    value={pregunta.type}
                                                    disabled={esCorreo}
                                                    onChange={(e) =>
                                                        cambiar(i, {
                                                            type: e.target
                                                                .value,
                                                        })
                                                    }
                                                >
                                                    {Object.entries(tipos).map(
                                                        ([valor, texto]) => (
                                                            <option
                                                                key={valor}
                                                                value={valor}
                                                            >
                                                                {texto}
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                            </Campo>
                                        </div>
                                        <div className="flex shrink-0 items-center pt-6">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                aria-label="Subir"
                                                disabled={i === 0}
                                                onClick={() => mover(i, i - 1)}
                                            >
                                                <ArrowUp />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                aria-label="Bajar"
                                                disabled={
                                                    i === preguntas.length - 1
                                                }
                                                onClick={() => mover(i, i + 1)}
                                            >
                                                <ArrowDown />
                                            </Button>
                                            {esCorreo ? (
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8"
                                                                aria-label="El correo no se puede quitar"
                                                                disabled
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        El correo no se puede
                                                        quitar: es por donde
                                                        llega el programa.
                                                    </TooltipContent>
                                                </Tooltip>
                                            ) : (
                                                <Confirmar
                                                    titulo={`Quitar «${nombre}»`}
                                                    descripcion="Dejará de aparecer en el formulario de este evento. Se aplica al guardar."
                                                    textoAccion="Quitar pregunta"
                                                    onConfirmar={() =>
                                                        form.setData(
                                                            'campos',
                                                            preguntas.filter(
                                                                (_, j) =>
                                                                    j !== i,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8 text-red-600 hover:text-red-700"
                                                        aria-label={`Quitar ${nombre}`}
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </Confirmar>
                                            )}
                                        </div>
                                    </div>

                                    {pregunta.type === 'select' ? (
                                        <div className="grid gap-2">
                                            <Label>Opciones de la lista</Label>
                                            <Opciones
                                                opciones={pregunta.options}
                                                onChange={(opciones) =>
                                                    cambiar(i, {
                                                        options: opciones,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errores[
                                                        `campos.${i}.options`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ) : (
                                        <Campo
                                            label="Texto dentro del campo (opcional)"
                                            htmlFor={`placeholder-${i}`}
                                            error={
                                                errores[
                                                    `campos.${i}.placeholder`
                                                ]
                                            }
                                        >
                                            <Input
                                                id={`placeholder-${i}`}
                                                value={
                                                    pregunta.placeholder ?? ''
                                                }
                                                onChange={(e) =>
                                                    cambiar(i, {
                                                        placeholder:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Campo>
                                    )}

                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={
                                                esCorreo || pregunta.required
                                            }
                                            disabled={esCorreo}
                                            onCheckedChange={(valor) =>
                                                cambiar(i, {
                                                    required: valor === true,
                                                })
                                            }
                                        />
                                        Obligatoria
                                    </label>
                                </div>
                            );
                        })}

                        <Button
                            type="button"
                            variant="outline"
                            onClick={agregar}
                        >
                            <Plus />
                            Agregar una pregunta
                        </Button>

                        <SeccionFicha titulo="Contacto para consultas">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Campo
                                    label="Nombre completo"
                                    htmlFor="contact_name"
                                    error={form.errors.contact_name}
                                >
                                    <Input
                                        id="contact_name"
                                        type="text"
                                        placeholder="Nombre y apellidos"
                                        value={form.data.contact_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'contact_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Campo>
                                <Campo
                                    label="Cargo"
                                    htmlFor="contact_role"
                                    error={form.errors.contact_role}
                                >
                                    <Input
                                        id="contact_role"
                                        type="text"
                                        placeholder="Cargo"
                                        value={form.data.contact_role}
                                        onChange={(e) =>
                                            form.setData(
                                                'contact_role',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Campo>
                                <Campo
                                    label="Empresa o institución (opcional)"
                                    htmlFor="contact_organization"
                                    error={form.errors.contact_organization}
                                >
                                    <Input
                                        id="contact_organization"
                                        type="text"
                                        placeholder="Empresa o institución"
                                        value={form.data.contact_organization}
                                        onChange={(e) =>
                                            form.setData(
                                                'contact_organization',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Campo>
                                <Campo
                                    label="Correo"
                                    htmlFor="contact_email"
                                    error={form.errors.contact_email}
                                >
                                    <Input
                                        id="contact_email"
                                        type="email"
                                        placeholder="contacto@visionactiva.cl"
                                        value={form.data.contact_email}
                                        onChange={(e) =>
                                            form.setData(
                                                'contact_email',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Campo>
                                <Campo
                                    label="Teléfono"
                                    htmlFor="contact_phone"
                                    error={form.errors.contact_phone}
                                >
                                    <Input
                                        id="contact_phone"
                                        type="tel"
                                        placeholder="56912345678"
                                        value={form.data.contact_phone}
                                        onChange={(e) =>
                                            form.setData(
                                                'contact_phone',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Campo>
                                <Campo
                                    label="WhatsApp (opcional)"
                                    htmlFor="contact_whatsapp"
                                    error={form.errors.contact_whatsapp}
                                >
                                    <Input
                                        id="contact_whatsapp"
                                        type="tel"
                                        placeholder="56912345678"
                                        value={form.data.contact_whatsapp}
                                        onChange={(e) =>
                                            form.setData(
                                                'contact_whatsapp',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Campo>
                            </div>
                        </SeccionFicha>

                        <SeccionFicha titulo="Cargos de quien asiste">
                            <p className="text-muted-foreground mb-3 text-sm">
                                Es la lista que ve quien inscribe a sus
                                participantes. Vienen todos marcados: desmarca
                                los que este evento no recibe.
                            </p>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {cargosParticipante
                                    .filter((cargo) => cargo !== 'Otro')
                                    .map((cargo) => {
                                        const marcado =
                                            form.data.participant_positions.includes(
                                                cargo,
                                            );

                                        return (
                                            <label
                                                key={cargo}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={marcado}
                                                    onCheckedChange={(valor) =>
                                                        form.setData(
                                                            'participant_positions',
                                                            valor
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .participant_positions,
                                                                      cargo,
                                                                  ]
                                                                : form.data.participant_positions.filter(
                                                                      (c) =>
                                                                          c !==
                                                                          cargo,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {cargo}
                                            </label>
                                        );
                                    })}
                            </div>
                            <p className="text-muted-foreground mt-3 text-xs">
                                «Otro» siempre queda disponible: sin él, alguien
                                con un cargo distinto no puede inscribirse.
                            </p>
                        </SeccionFicha>

                        <SeccionFicha titulo="Programa">
                            <div className="space-y-5">
                                <Adjuntos
                                    eventoId={evento.id}
                                    adjuntos={evento.adjuntos}
                                    maximo={evento.max_adjuntos}
                                    maxMb={evento.max_mb}
                                />
                                {/* Texto de entrada del correo con el programa.
                                    Se saca de la pantalla a propósito: hoy el
                                    correo ya abre con un saludo que sirve, y
                                    una caja de texto más en esta pantalla se
                                    responde escribiendo cualquier cosa. La
                                    columna `program_email_intro` y su uso en
                                    la plantilla siguen en pie: si algún día se
                                    quiere personalizar por evento, se vuelve a
                                    mostrar este campo y funciona.

                                    <Campo
                                    label="Texto del correo con el programa"
                                    htmlFor="program_email_intro"
                                    error={form.errors.program_email_intro}
                                    ayuda="Son las primeras líneas del correo que recibe quien completa el formulario, después del saludo. Por ejemplo: «Gracias por tu interés en el seminario. Adjuntamos el programa con los relatores y los horarios de cada jornada.»"
                                    >
                                    <Textarea
                                    id="program_email_intro"
                                    rows={3}
                                    placeholder="Gracias por tu interés. Adjuntamos la información del evento."
                                    
                                    value={form.data.program_email_intro}
                                    onChange={(e) =>
                                    form.setData(
                                    'program_email_intro',
                                    e.target.value,
                                    )
                                    }
                                    />
                                    </Campo>
                                */}
                            </div>
                        </SeccionFicha>
                    </div>

                    <div className="lg:sticky lg:top-4">
                        <VistaPrevia
                            campos={preguntas}
                            consentimiento={evento.consentimiento}
                        />
                    </div>
                </div>
            </form>
        </>
    );
}
