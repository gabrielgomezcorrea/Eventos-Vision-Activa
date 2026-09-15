import { useState } from 'react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';

/**
 * Deja solo dígitos y una K final, y arma 12.345.678-9 mientras se escribe.
 * Acepta también un RUT ya guardado sin puntos, para mostrarlo formateado.
 */
export function formatearRut(valor: string): string {
    const limpio = valor.replace(/[^0-9kK]/g, '').toUpperCase();

    if (limpio === '') {
        return '';
    }

    // La K solo puede ser dígito verificador: se descarta en el cuerpo.
    const cuerpo = limpio.slice(0, -1).replace(/K/g, '').slice(0, 8);
    const dv = limpio.slice(-1);

    if (cuerpo === '') {
        return dv;
    }

    return `${cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}-${dv}`;
}

/** Valida el dígito verificador con módulo 11. */
export function rutValido(valor: string): boolean {
    const limpio = valor.replace(/[^0-9kK]/g, '').toUpperCase();

    if (limpio.length < 2) {
        return false;
    }

    const cuerpo = limpio.slice(0, -1);
    let suma = 0;
    let factor = 2;

    for (let i = cuerpo.length - 1; i >= 0; i--) {
        suma += Number(cuerpo[i]) * factor;
        factor = factor === 7 ? 2 : factor + 1;
    }

    const resto = 11 - (suma % 11);
    const esperado = resto === 11 ? '0' : resto === 10 ? 'K' : String(resto);

    return limpio.slice(-1) === esperado;
}

type Props = Omit<ComponentProps<typeof Input>, 'value' | 'onChange'> & {
    value: string;
    onChange: (valor: string) => void;
};

/** Campo de RUT que se formatea solo y avisa al salir si el dígito verificador no calza. */
export function RutInput({ value, onChange, onBlur, ...props }: Props) {
    const [tocado, setTocado] = useState(false);
    const invalido = tocado && value !== '' && !rutValido(value);

    return (
        <>
            <Input
                {...props}
                value={formatearRut(value)}
                onChange={(e) => onChange(formatearRut(e.target.value))}
                onBlur={(e) => {
                    setTocado(true);
                    onBlur?.(e);
                }}
                maxLength={12}
                autoComplete="off"
                placeholder={props.placeholder ?? '12.345.678-9'}
                aria-invalid={invalido || props['aria-invalid']}
            />
            {invalido && (
                <p className="text-destructive text-sm">
                    Este RUT no es válido. Revisa el dígito después del guion.
                </p>
            )}
        </>
    );
}
