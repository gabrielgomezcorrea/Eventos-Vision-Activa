<style>
    :root{
        --primario:#084887; --primario-oscuro:#063a6d;
        --texto:#1F2937; --texto-suave:#667085; --borde:#D0D5DD; --fondo:#F7F5FB;
        --exito:#2E7D32; --exito-fondo:#EAF6ED; --exito-borde:#A6D6B4;
        --aviso:#B45309; --aviso-fondo:#FFF4E5; --aviso-borde:#FDCE8D;
        --error:#DC2626; --error-fondo:#FEF2F2; --error-borde:#FECACA;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--fondo);color:var(--texto);
         font:17px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
         -webkit-text-size-adjust:100%}
    .envoltura{max-width:620px;margin:0 auto;padding:14px 14px 60px}

    .barra{display:flex;justify-content:space-between;align-items:center;gap:12px;
           margin-bottom:14px;flex-wrap:wrap}
    .barra h1{font-size:1.1rem;margin:0}
    .barra .usuario{font-size:.82rem;color:var(--texto-suave)}
    .selector-evento{width:100%;padding:11px 12px;font:inherit;border:1px solid var(--borde);
                     border-radius:10px;background:#fff;margin-bottom:14px}

    .tarjeta{background:#fff;border:1px solid var(--borde);border-radius:14px;
             padding:16px;margin-bottom:14px}
    .tarjeta h2{font-size:1rem;margin:0 0 4px}
    .tarjeta .sub{margin:0 0 12px;color:var(--texto-suave);font-size:.88rem}

    /* Resultado: tiene que leerse de un vistazo, a un brazo de distancia. */
    .resultado{border-radius:14px;border:3px solid;padding:18px;margin-bottom:14px}
    .resultado.exito{background:var(--exito-fondo);border-color:var(--exito-borde)}
    .resultado.aviso{background:var(--aviso-fondo);border-color:var(--aviso-borde)}
    .resultado.error{background:var(--error-fondo);border-color:var(--error-borde)}
    .resultado-titulo{display:flex;align-items:center;gap:10px;font-size:1.3rem;
                      font-weight:800;margin:0 0 6px;line-height:1.2}
    .resultado.exito .resultado-titulo{color:var(--exito)}
    .resultado.aviso .resultado-titulo{color:var(--aviso)}
    .resultado.error .resultado-titulo{color:var(--error)}
    .resultado-mensaje{margin:0 0 14px;font-size:.95rem}
    .simbolo{font-size:1.6rem;line-height:1}

    .persona{background:#fff;border-radius:10px;padding:14px;margin-bottom:14px}
    .persona .nombre{font-size:1.5rem;font-weight:800;margin:0;line-height:1.15}
    .persona .institucion{margin:4px 0 0;color:var(--texto-suave);font-size:.92rem}
    .persona .acceso{margin:10px 0 0;font-size:1.05rem;font-weight:600}
    .persona .orden{margin:6px 0 0;font-size:.82rem;color:var(--texto-suave)}

    /* La pulsera es la instrucción operativa: es lo más grande de la pantalla. */
    .pulsera{display:flex;align-items:center;gap:14px;margin-top:14px;padding:14px;
             border:2px solid var(--borde);border-radius:12px;background:#fff}
    .pulsera-muestra{width:52px;height:52px;border-radius:50%;flex-shrink:0;
                     border:2px solid rgba(0,0,0,.2)}
    .pulsera-texto .etiqueta{margin:0;font-size:.72rem;letter-spacing:.06em;
                             text-transform:uppercase;color:var(--texto-suave);font-weight:700}
    .pulsera-texto .valor{margin:2px 0 0;font-size:1.25rem;font-weight:800;line-height:1.2}

    /* Botones grandes: se usan de pie y muchas veces con guantes o apuro. */
    .btn{display:block;width:100%;padding:18px;font:inherit;font-size:1.1rem;font-weight:700;
         border:0;border-radius:12px;cursor:pointer;text-align:center;text-decoration:none}
    .btn-confirmar{background:var(--exito);color:#fff}
    .btn-primario{background:var(--primario);color:#fff}
    .btn-secundario{background:#fff;color:var(--texto);border:2px solid var(--borde);
                    font-size:1rem;padding:14px}
    .btn+.btn{margin-top:10px}

    label{display:block;font-weight:700;font-size:.88rem;margin-bottom:6px}
    input[type=text],input[type=search]{width:100%;padding:15px 14px;font:inherit;font-size:1.1rem;
                                        border:2px solid var(--borde);border-radius:10px;background:#fff}
    input:focus{outline:3px solid var(--primario);outline-offset:1px;border-color:var(--primario)}
    .codigo-entrada{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
                    letter-spacing:.12em;text-transform:uppercase}

    #lector{width:100%;border-radius:12px;overflow:hidden;background:#000;min-height:60px}
    #lector video{width:100%!important;display:block}
    .lector-aviso{margin:10px 0 0;font-size:.85rem;color:var(--texto-suave)}

    .coincidencia{display:flex;justify-content:space-between;align-items:center;gap:12px;
                  padding:14px;border-bottom:1px solid var(--borde)}
    .coincidencia:last-child{border-bottom:0}
    .coincidencia .datos strong{display:block;font-size:1.05rem}
    .coincidencia .datos span{font-size:.84rem;color:var(--texto-suave)}
    .coincidencia form{margin:0}
    .coincidencia .btn{width:auto;padding:12px 18px;font-size:.95rem}
    .marca-acreditado{font-size:.82rem;font-weight:700;color:var(--exito);white-space:nowrap}

    .vacio{padding:20px;text-align:center;color:var(--texto-suave);font-size:.92rem}
</style>
