/**
 * Cliente mínimo de la API de Mailpit para leer los correos que el sistema
 * mandó de verdad durante un recorrido de Playwright en local.
 */
const MAILPIT_URL = process.env.MAILPIT_URL ?? 'http://127.0.0.1:8025';

type MailpitSummary = {
    ID: string;
    To: { Address: string }[];
    Subject: string;
    Created: string;
};

type MailpitMessage = {
    ID: string;
    Subject: string;
    HTML: string;
    Text: string;
};

export async function limpiarBandeja(): Promise<void> {
    await fetch(`${MAILPIT_URL}/api/v1/messages`, { method: 'DELETE' });
}

async function listarMensajes(): Promise<MailpitSummary[]> {
    const respuesta = await fetch(`${MAILPIT_URL}/api/v1/messages?limit=100`);
    const datos = await respuesta.json();

    return datos.messages ?? [];
}

async function leerMensaje(id: string): Promise<MailpitMessage> {
    const respuesta = await fetch(`${MAILPIT_URL}/api/v1/message/${id}`);

    return respuesta.json();
}

/**
 * Espera hasta que llegue un correo a `destinatario` cuyo asunto contenga
 * `asuntoContiene`, sin contar los `idsYaVistos` (los 3 colegios comparten el
 * mismo responsable, así que "Recibimos tu comprobante" llega tres veces y
 * hay que distinguirlos por contenido, no solo por asunto).
 *
 * La cola corre en modo sync en local, así que el correo suele estar en
 * Mailpit apenas termina el request; el sondeo es por si la cola real
 * (`queue:work`) todavía no lo procesó.
 */
export async function esperarCorreo(
    destinatario: string,
    asuntoContiene: string,
    opciones: {
        contieneTexto?: string;
        idsYaVistos?: Set<string>;
        timeoutMs?: number;
    } = {},
): Promise<MailpitMessage> {
    const {
        contieneTexto,
        idsYaVistos = new Set(),
        timeoutMs = 15_000,
    } = opciones;
    const limite = Date.now() + timeoutMs;

    while (Date.now() < limite) {
        const mensajes = await listarMensajes();
        const candidatos = mensajes.filter(
            (m) =>
                m.To.some((t) => t.Address === destinatario) &&
                m.Subject.includes(asuntoContiene) &&
                !idsYaVistos.has(m.ID),
        );

        for (const candidato of candidatos) {
            const completo = await leerMensaje(candidato.ID);

            if (!contieneTexto || completo.HTML.includes(contieneTexto)) {
                return completo;
            }
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
    }

    throw new Error(
        `No llegó a Mailpit un correo para ${destinatario} con asunto "${asuntoContiene}"` +
            (contieneTexto ? ` que contuviera "${contieneTexto}"` : ''),
    );
}

export function extraerEnlace(html: string, patron: RegExp): string {
    const match = html.match(patron);

    if (!match) {
        throw new Error(
            `No se encontró un enlace que calce con ${patron} en el correo`,
        );
    }

    return match[0].replace(/&amp;/g, '&');
}
