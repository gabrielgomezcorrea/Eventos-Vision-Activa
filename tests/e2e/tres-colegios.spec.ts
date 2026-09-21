import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { limpiarEventoE2e, ordenesDelConjunto } from './support/artisan';
import {
    esperarCorreo,
    extraerEnlace,
    limpiarBandeja,
} from './support/mailpit';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/** La app valida "no futuro" contra su zona horaria (`America/Santiago`), no la del navegador. */
function hoyEnSantiago(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Santiago',
    }).format(new Date());
}

/**
 * Recorrido de Fase 6 (ROADMAP 6.2): un responsable inscribe 3 colegios en
 * un mismo conjunto, sube un comprobante distinto por colegio y Contabilidad
 * aprueba cada uno por separado desde el panel, todo contra Mailpit real.
 *
 * Requiere: `composer run dev` corriendo y Mailpit en 127.0.0.1:8025.
 * Solo para jugar en local: `npx playwright test`.
 */
const EVENTO_SLUG = 'e2e-colegios';
const CORREO = `e2e-${Date.now()}@colegio.cl`;
const COLEGIOS = ['Colegio Norte', 'Colegio Centro', 'Colegio Sur'] as const;
const FIXTURES = path.join(__dirname, 'fixtures');

test.beforeAll(() => {
    limpiarEventoE2e();
});

test.beforeEach(async () => {
    await limpiarBandeja();
});

test('3 colegios, 3 comprobantes distintos, 3 facturas', async ({ page }) => {
    // 1. Pide el enlace de acceso.
    await page.goto(`/i/${EVENTO_SLUG}`);
    await page.getByLabel('Correo electrónico').fill(CORREO);
    await page.getByRole('button', { name: 'Enviarme el enlace' }).click();
    await expect(page.getByText('Enlace enviado.')).toBeVisible();

    const correoEnlace = await esperarCorreo(
        CORREO,
        'Enlace para tu inscripción',
    );
    const enlaceAcceso = extraerEnlace(
        correoEnlace.HTML,
        /https?:\/\/[^"]*\/i\/acceso\/[^"]+/,
    );
    await page.goto(enlaceAcceso);

    // 2. Responsable.
    await page.locator('#kind').selectOption({ label: 'Institucional' });
    await page.locator('#responsible_name').fill('Ana');
    await page.locator('#responsible_lastname').fill('Pérez');
    await page
        .locator('#responsible_position')
        .selectOption({ label: 'Sostenedor/a' });
    await page.locator('#responsible_phone').fill('56912345678');
    await page.locator('#responsible_institution').fill('Sostenedor E2E');
    await page.getByRole('button', { name: 'Continuar' }).click();

    // 3. Los 3 establecimientos.
    for (const nombre of COLEGIOS) {
        await page.locator('#name').fill(nombre);
        await page.locator('#address').fill('Calle 1');
        await page.locator('#commune').fill('Talca');
        await page.getByRole('button', { name: 'Guardar' }).click();
        await expect(page.getByText(nombre, { exact: true })).toBeVisible();
    }
    await page.getByRole('link', { name: 'Continuar a participantes' }).click();

    // 4. Un participante por colegio.
    const NOMBRES_PARTICIPANTES = ['Carlos', 'Marta', 'Pedro'] as const;
    const RUTS_PARTICIPANTES = [
        '11.111.111-1',
        '22.222.222-2',
        '33.333.333-3',
    ] as const;
    for (const [indice, nombre] of COLEGIOS.entries()) {
        const nombreParticipante = NOMBRES_PARTICIPANTES[indice];
        await page.locator('#first_name').fill(nombreParticipante);
        await page.locator('#last_name').fill('Rojas');
        await page.locator('#rut').fill(RUTS_PARTICIPANTES[indice]);
        await page
            .locator('#position')
            .selectOption({ label: 'Docente Enseñanza Básica' });
        await page.locator('#establishment_id').selectOption({ label: nombre });
        await page.locator('#email').fill(`participante${indice}@colegio.cl`);
        await page.locator('#access_type_id').selectOption({ index: 1 });
        await page
            .getByRole('button', { name: 'Agregar participante' })
            .click();
        await expect(page.getByText(nombreParticipante)).toBeVisible();
    }
    await page.getByRole('link', { name: 'Continuar a facturación' }).click();

    // 5. Entidad pagadora.
    await page.locator('#name').fill('Sostenedor E2E');
    await page.locator('#rut').fill('76.086.428-5');
    await page.locator('#address').fill('Av. Siempre Viva 123');
    await page.locator('#commune').fill('Ñuñoa');
    await page.locator('#billing_email').fill('pagos@sostenedor-e2e.cl');
    await page.locator('#phone').fill('56912345678');
    await page.getByRole('button', { name: 'Continuar al resumen' }).click();

    // 6. Confirmar el conjunto.
    await page.getByRole('button', { name: /Confirmar inscripción/ }).click();
    await expect(
        page.getByRole('navigation', { name: 'Tu inscripción' }),
    ).toBeVisible();

    const ordenes = ordenesDelConjunto(CORREO, EVENTO_SLUG);
    expect(ordenes).toHaveLength(3);

    // 7. Un comprobante distinto por colegio, con el saldo completo cada uno.
    // Se ve un colegio a la vez: hay que elegirlo en el menú lateral.
    const idsDeCorreosVistos = new Set<string>();
    for (const orden of ordenes) {
        await page
            .getByRole('link', { name: new RegExp(orden.establecimiento) })
            .click();
        await expect(
            page.getByRole('heading', { name: `Inscripción ${orden.numero}` }),
        ).toBeVisible();

        await page.locator(`#amount-${orden.id}`).fill('90000');
        await page.locator(`#paid_on-${orden.id}`).fill(hoyEnSantiago());
        await page.locator(`#bank_name-${orden.id}`).fill('Banco Estado');
        await page.locator(`#payer_name-${orden.id}`).fill('Ana Pérez');
        await page.locator(`#payer_rut-${orden.id}`).fill('11.111.111-1');
        await page
            .locator(`#proof-${orden.id}`)
            .setInputFiles(
                path.join(FIXTURES, `comprobante-${orden.establecimiento}.pdf`),
            );
        await page
            .locator(`#proof-${orden.id}`)
            .evaluate((input) =>
                (input as HTMLInputElement).closest('form')?.requestSubmit(),
            );
        await page.waitForLoadState('networkidle');

        const correoComprobante = await esperarCorreo(
            CORREO,
            'Recibimos tu comprobante',
            {
                contieneTexto: orden.numero,
                idsYaVistos: idsDeCorreosVistos,
            },
        );
        idsDeCorreosVistos.add(correoComprobante.ID);
    }

    // 8. Contabilidad aprueba cada pago por separado, desde el panel.
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill('contabilidad@test.cl');
    await page
        .getByLabel('Contraseña', { exact: true })
        .fill('contabilidad@test.cl');
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(/dashboard/);

    const idsDePagoAprobadoVistos = new Set<string>();
    for (const orden of ordenes) {
        await page.goto('/comprobantes');
        await page.getByRole('link', { name: orden.numero! }).click();
        await page
            .getByRole('button', { name: 'Revisar el comprobante' })
            .click();
        await page.getByText('Aprobar el pago').click();
        await page.getByRole('button', { name: 'Confirmar' }).click();
        await expect(page.getByText('Comprobante aprobado')).toBeVisible();

        const correoAprobado = await esperarCorreo(CORREO, 'Pago confirmado', {
            contieneTexto: orden.numero,
            idsYaVistos: idsDePagoAprobadoVistos,
        });
        idsDePagoAprobadoVistos.add(correoAprobado.ID);
    }

    // 9. Una factura por colegio, cada una con su propio número.
    for (const [indice, orden] of ordenes.entries()) {
        await page.goto(`/inscripciones/${orden.id}`);
        await page.getByRole('button', { name: 'Registrar factura' }).click();
        await page.locator('#number').fill(`900${indice}`);
        await page.locator('#issued_on').fill(hoyEnSantiago());
        await page.locator('#factura-amount').fill('90000');
        await page.getByRole('button', { name: 'Registrar' }).click();
        await expect(
            page.getByText(`Factura N° 900${indice}`, { exact: true }),
        ).toBeVisible();
    }
});
