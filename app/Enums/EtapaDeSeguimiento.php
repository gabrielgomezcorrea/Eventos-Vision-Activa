<?php

namespace App\Enums;

/**
 * Where a person stands in the funnel of one event, from the program request
 * to the paid enrollment. Derived from the request and its orders, never
 * stored: the rules live in `ProgramRequest::scopeEnEtapa()`.
 *
 * - Solicito: asked for the program and wrote no enrollment data yet. The team
 *   follows them up inside the system.
 * - PreInscrito: wrote any enrollment data, confirmed or paid part of it, but
 *   has not paid it all. This is the group sales writes to from Brevo.
 * - Inscrito: every live enrollment is fully paid (special guests included).
 * - NoParticipara: the team marked that they will not come; kept for next events.
 */
enum EtapaDeSeguimiento: string implements EstadoVisible
{
    case Solicito = 'solicito';
    case PreInscrito = 'pre_inscrito';
    case Inscrito = 'inscrito';
    case NoParticipara = 'no_participara';

    public function label(): string
    {
        return match ($this) {
            self::Solicito => 'Nueva solicitud',
            self::PreInscrito => 'Pre inscrito',
            self::Inscrito => 'Inscrito',
            self::NoParticipara => 'No participará',
        };
    }

    /** Name of the stage on its button and on the download. */
    public function plural(): string
    {
        return match ($this) {
            self::Solicito => 'Solicitudes',
            self::PreInscrito => 'Pre inscritos',
            self::Inscrito => 'Inscritos',
            self::NoParticipara => 'No participarán',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Solicito => 'Personas que solicitaron el programa.',
            self::PreInscrito => 'Personas que no completaron su inscripción.',
            self::Inscrito => 'Personas que ya pagaron su inscripción.',
            self::NoParticipara => 'Personas que no participarán.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Solicito => 'warning',
            self::PreInscrito => 'info',
            self::Inscrito => 'success',
            self::NoParticipara => 'gray',
        };
    }
}
