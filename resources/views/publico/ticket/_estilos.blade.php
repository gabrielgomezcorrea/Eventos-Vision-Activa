<style>
    :root {
        --primario:#084887; --texto:#1F2937; --texto-suave:#667085;
        --borde:#D0D5DD; --fondo:#F7F5FB;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--fondo);color:var(--texto);
         font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
    .envoltura{max-width:760px;margin:0 auto;padding:24px 16px 60px}
    .encabezado{margin-bottom:18px}
    .encabezado h1{font-size:1.25rem;margin:0 0 4px}
    .encabezado p{margin:0;color:var(--texto-suave);font-size:.92rem}

    .credencial{background:#fff;border:2px solid var(--primario);border-radius:12px;
                overflow:hidden;margin-bottom:18px;page-break-inside:avoid;break-inside:avoid}
    .credencial-cabecera{background:var(--primario);color:#fff;padding:12px 18px}
    .credencial-evento{margin:0;font-weight:700;font-size:1rem;line-height:1.25}
    .credencial-lugar{margin:2px 0 0;font-size:.82rem;opacity:.85}

    .credencial-cuerpo{display:flex;gap:20px;padding:18px;align-items:flex-start}
    .credencial-datos{flex:1;min-width:0}
    .credencial-qr{text-align:center;flex-shrink:0}
    /* 240px sobre 57 modulos (49 + zona de silencio) da 4.2px por
       modulo, sobre el minimo para escanear desde pantalla. */
    .credencial-qr svg{display:block;width:240px;height:240px}

    .etiqueta{margin:0;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;
              color:var(--texto-suave);font-weight:600}
    .nombre{margin:2px 0 0;font-size:1.3rem;font-weight:700;line-height:1.2}
    .institucion{margin:3px 0 0;color:var(--texto-suave);font-size:.9rem}
    .acceso{margin:2px 0 0;font-size:1.05rem;font-weight:600}
    .jornadas{margin:6px 0 0;padding-left:18px;font-size:.86rem;color:var(--texto-suave)}
    .pulsera{display:flex;align-items:center;gap:8px;margin:14px 0 0;font-weight:600;font-size:.92rem}
    .pulsera-punto{width:16px;height:16px;border-radius:50%;border:1px solid rgba(0,0,0,.25);flex-shrink:0}

    /* Grande y espaciado: en la puerta se dicta en voz alta. */
    .codigo{margin:2px 0 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
            font-size:1.35rem;font-weight:700;letter-spacing:.08em}

    .credencial-pie{border-top:1px solid var(--borde);padding:10px 18px;
                    font-size:.78rem;color:var(--texto-suave)}

    .acciones{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
    .btn{padding:11px 20px;font:inherit;font-weight:600;border:0;border-radius:8px;
         cursor:pointer;text-decoration:none;background:var(--primario);color:#fff}
    .aviso{background:#FDECEC;color:#B91C1C;border-radius:8px;padding:14px 16px;margin-bottom:18px}

    @media (max-width:560px){
        .credencial-cuerpo{flex-direction:column;align-items:center;text-align:center}
        .credencial-datos{text-align:center}
        .pulsera{justify-content:center}
    }

    /* La credencial se imprime en blanco y negro y debe seguir siendo legible:
       el QR mantiene contraste total y los bordes de color pasan a negro. */
    @media print{
        body{background:#fff}
        .envoltura{padding:0;max-width:none}
        .encabezado,.acciones{display:none}
        .credencial{border-color:#000;margin-bottom:10mm}
        .credencial-cabecera{background:#fff;color:#000;border-bottom:1px solid #000;
                             -webkit-print-color-adjust:exact;print-color-adjust:exact}
        .pulsera-punto{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
</style>
