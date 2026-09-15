import { Campo } from '@/components/campo';
import { NativeSelect } from '@/components/native-select';
import { RutInput } from '@/components/rut-input';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type DatosCuenta = {
    label: string;
    holder_name: string;
    holder_rut: string;
    bank_name: string;
    account_type: string;
    account_number: string;
    email: string;
    payment_instructions: string;
    is_active: string;
};

export const cuentaVacia: DatosCuenta = {
    label: '',
    holder_name: '',
    holder_rut: '',
    bank_name: '',
    account_type: '',
    account_number: '',
    email: '',
    payment_instructions:
        'Indica el número de inscripción en el mensaje de la transferencia.',
    is_active: '1',
};

export type OpcionesCuenta = {
    bancos: string[];
    tiposDeCuenta: string[];
};

type Props = {
    data: Record<string, string>;
    set: (campo: string, valor: string) => void;
    errors: Partial<Record<string, string>>;
    opciones: OpcionesCuenta;
};

/** Un valor guardado antes de existir la lista se muestra igual, para no perderlo al editar. */
function conActual(lista: string[], actual: string): string[] {
    return actual && !lista.includes(actual) ? [actual, ...lista] : lista;
}

/** Los campos de una cuenta, iguales al crearla y al editarla. */
export function CamposCuentaBancaria({ data, set, errors, opciones }: Props) {
    const texto = (campo: keyof DatosCuenta) => ({
        id: `cuenta-${campo}`,
        value: data[campo] ?? '',
        onChange: (e: { target: { value: string } }) =>
            set(campo, e.target.value),
    });

    return (
        <div className="space-y-5">
            <Campo
                label="Nombre para reconocerla"
                htmlFor="cuenta-label"
                error={errors.label}
            >
                <Input
                    {...texto('label')}
                    placeholder="Cuenta corriente BCI"
                    required
                />
            </Campo>
            <div className="grid gap-5 sm:grid-cols-2">
                <Campo
                    label="Banco"
                    htmlFor="cuenta-bank_name"
                    error={errors.bank_name}
                >
                    <NativeSelect {...texto('bank_name')} required>
                        <option value="">Elige el banco</option>
                        {conActual(opciones.bancos, data.bank_name ?? '').map(
                            (banco) => (
                                <option key={banco} value={banco}>
                                    {banco}
                                </option>
                            ),
                        )}
                    </NativeSelect>
                </Campo>
                <Campo
                    label="Tipo de cuenta"
                    htmlFor="cuenta-account_type"
                    error={errors.account_type}
                >
                    <NativeSelect {...texto('account_type')}>
                        <option value="">Elige el tipo</option>
                        {conActual(
                            opciones.tiposDeCuenta,
                            data.account_type ?? '',
                        ).map((tipo) => (
                            <option key={tipo} value={tipo}>
                                {tipo}
                            </option>
                        ))}
                    </NativeSelect>
                </Campo>
            </div>
            <Campo
                label="Número de cuenta"
                htmlFor="cuenta-account_number"
                error={errors.account_number}
            >
                <Input {...texto('account_number')} required />
            </Campo>
            <div className="grid gap-5 sm:grid-cols-2">
                <Campo
                    label="Titular de la cuenta"
                    htmlFor="cuenta-holder_name"
                    error={errors.holder_name}
                >
                    <Input {...texto('holder_name')} required />
                </Campo>
                <Campo
                    label="RUT del titular"
                    htmlFor="cuenta-holder_rut"
                    error={errors.holder_rut}
                >
                    <RutInput
                        id="cuenta-holder_rut"
                        value={data.holder_rut ?? ''}
                        onChange={(valor) => set('holder_rut', valor)}
                        placeholder="76.123.456-7"
                    />
                </Campo>
            </div>
            <Campo
                label="Correo para avisos de pago"
                htmlFor="cuenta-email"
                error={errors.email}
            >
                <Input {...texto('email')} type="email" />
            </Campo>
            <Campo
                label="Instrucciones de pago"
                htmlFor="cuenta-payment_instructions"
                error={errors.payment_instructions}
            >
                <Textarea {...texto('payment_instructions')} rows={3} />
            </Campo>
            <div className="flex items-center gap-3">
                <Checkbox
                    id="cuenta-activa"
                    checked={data.is_active === '1'}
                    onCheckedChange={(valor) =>
                        set('is_active', valor === true ? '1' : '0')
                    }
                />
                <Label htmlFor="cuenta-activa">
                    Disponible para nuevos eventos
                </Label>
            </div>
        </div>
    );
}
