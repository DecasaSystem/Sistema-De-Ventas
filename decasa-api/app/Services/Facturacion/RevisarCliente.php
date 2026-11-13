<?php

namespace App\Services\Facturacion;

/**
 * Errores típicos en los datos del cliente que la DIAN rechaza o que dejan la
 * factura mal hecha, con el arreglo que se le propone al facturador.
 *
 * Por qué (usuario, 2026-11-13): la #4421 era de "INVERSIONES CONVERSA SAS" y
 * estaba guardada como cédula, persona natural, con el número 9006232083: es el
 * NIT 900623208 con el dígito de verificación (3) pegado al final. Facturada
 * así, la empresa no la puede usar y la DIAN la puede rechazar.
 *
 * Cada problema: ['tipo' => bloqueo|advertencia|info, 'codigo', 'mensaje',
 * 'arreglo' => null | ['texto' => …, 'campos' => [columnas de clientes]]].
 * Solo revisa: no cambia nada. El arreglo lo aplica una persona con un botón.
 */
class RevisarCliente
{
    /** Errores comunes al escribir el correo. */
    private const DOMINIOS = [
        'gmail.con' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com',
        'gmai.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.cm' => 'gmail.com',
        'hotmail.con' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmial.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com',
        'hotmai.com' => 'hotmail.com', 'outlook.con' => 'outlook.com', 'outlok.com' => 'outlook.com', 'outlook.co' => 'outlook.com',
        'yahoo.con' => 'yahoo.com', 'yaho.com' => 'yahoo.com', 'icloud.con' => 'icloud.com',
    ];

    /**
     * @param array $c lo que va a la DIAN: nombre, documento, tipo_documento, dv, tipo_persona, email, telefono
     */
    public static function problemas(array $c): array
    {
        $salida = [];
        $nombre   = trim((string) ($c['nombre'] ?? ''));
        $doc      = (string) ($c['documento'] ?? '');
        $tipo     = (string) ($c['tipo_documento'] ?? '');
        $dv       = trim((string) ($c['dv'] ?? ''));
        $persona  = (string) ($c['tipo_persona'] ?? 'natural');
        $nitEmpresa = self::nitDeEmpresa($doc);

        // 1. Nombre de empresa (SAS, LTDA…) con cédula o como persona natural.
        if ($nombre !== '' && self::pareceEmpresa($nombre) && ($tipo !== 'NIT' || $persona !== 'juridica')) {
            $campos = ['tipo_documento' => 'NIT', 'tipo_persona' => 'juridica'];
            $texto  = 'Persona jurídica con NIT';
            if ($nitEmpresa) {
                $campos += ['cedula' => $nitEmpresa[0], 'dv' => $nitEmpresa[1]];
                $texto = "NIT {$nitEmpresa[0]}-{$nitEmpresa[1]}, persona jurídica";
            }
            $salida[] = self::problema('advertencia', 'cliente_empresa',
                "\"{$nombre}\" es una empresa, pero está como "
                . ($tipo !== 'NIT' ? self::nombreTipo($tipo) : 'persona natural')
                . ($nitEmpresa && $nitEmpresa[0] !== $doc ? ". El número {$doc} parece el NIT {$nitEmpresa[0]} con el dígito de verificación {$nitEmpresa[1]} pegado al final" : '')
                . '. A una empresa se le factura con NIT y como persona jurídica, o no la puede usar.',
                ['texto' => $texto, 'campos' => $campos]);
        }
        // 1b. "Cédula" de 10 cifras que empieza por 8 o 9 y cuadra con el dígito de
        // verificación: es un NIT de empresa (las cédulas de 10 cifras empiezan por 1).
        elseif ($tipo === 'CC' && $nitEmpresa && $nitEmpresa[0] !== $doc) {
            $salida[] = self::problema('advertencia', 'cedula_parece_nit',
                "El número {$doc} no parece una cédula: parece el NIT {$nitEmpresa[0]} de una empresa, con el dígito de verificación {$nitEmpresa[1]} pegado.",
                ['texto' => "NIT {$nitEmpresa[0]}-{$nitEmpresa[1]}, persona jurídica",
                 'campos' => ['tipo_documento' => 'NIT', 'tipo_persona' => 'juridica', 'cedula' => $nitEmpresa[0], 'dv' => $nitEmpresa[1]]]);
        }
        // 2. NIT con el dígito de verificación pegado (10 cifras que empiezan por 8 o 9).
        elseif ($tipo === 'NIT' && $nitEmpresa && $nitEmpresa[0] !== $doc) {
            $salida[] = self::problema('advertencia', 'nit_con_dv',
                "El NIT {$doc} parece traer el dígito de verificación pegado: sería {$nitEmpresa[0]}-{$nitEmpresa[1]}.",
                ['texto' => "NIT {$nitEmpresa[0]}-{$nitEmpresa[1]}", 'campos' => ['cedula' => $nitEmpresa[0], 'dv' => $nitEmpresa[1]]]);
        }

        // 3. Dígito de verificación que no corresponde: la DIAN la rechaza.
        if ($tipo === 'NIT' && ctype_digit($doc) && $dv !== '' && ! ($nitEmpresa && $nitEmpresa[0] !== $doc)) {
            $bueno = (string) ArmarFactura::digitoVerificacion($doc);
            if ($dv !== $bueno) {
                $salida[] = self::problema('bloqueo', 'dv_malo',
                    "El dígito de verificación del NIT {$doc} es {$bueno}, no {$dv}. Con el que está, la DIAN rechaza la factura.",
                    ['texto' => "Dígito de verificación {$bueno}", 'campos' => ['dv' => $bueno]]);
            }
        }

        // 4. Persona jurídica con cédula: no existe.
        if ($persona === 'juridica' && in_array($tipo, ['CC', 'TI', 'RC'], true)) {
            $campos = ['tipo_documento' => 'NIT'] + ($nitEmpresa ? ['cedula' => $nitEmpresa[0], 'dv' => $nitEmpresa[1]] : []);
            $salida[] = self::problema('bloqueo', 'juridica_con_cedula',
                'Una persona jurídica (empresa) no tiene ' . self::nombreTipo($tipo) . ': se identifica con NIT.',
                ['texto' => $nitEmpresa ? "NIT {$nitEmpresa[0]}-{$nitEmpresa[1]}" : 'Cambiar a NIT', 'campos' => $campos]);
        }

        // 5. La cédula: solo números y un largo posible (las de hoy tienen hasta 10 cifras).
        if ($tipo === 'CC' && $doc !== '') {
            if (! ctype_digit($doc)) {
                $salida[] = self::problema('bloqueo', 'cedula_con_letras', "La cédula \"{$doc}\" tiene letras: una cédula es solo números.");
            } elseif (strlen($doc) < 5 || strlen($doc) > 10) {
                $salida[] = self::problema('advertencia', 'cedula_rara',
                    "La cédula {$doc} tiene " . strlen($doc) . ' cifras: las colombianas tienen entre 5 y 10. Revísala con el cliente.');
            }
        }

        // 6. El correo: que tenga forma de correo y sin los errores de siempre (gmail.con).
        $email = trim((string) ($c['email'] ?? ''));
        if ($email !== '') {
            $dominio = strtolower(substr(strrchr($email, '@') ?: '', 1));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $salida[] = self::problema('bloqueo', 'correo_malo', "El correo \"{$email}\" no está bien escrito: la DIAN no le podrá mandar la factura.");
            } elseif (isset(self::DOMINIOS[$dominio])) {
                $bueno = substr($email, 0, -strlen($dominio)) . self::DOMINIOS[$dominio];
                $salida[] = self::problema('advertencia', 'correo_dudoso',
                    "El correo termina en \"@{$dominio}\": ¿no será {$bueno}? Si está mal, al cliente no le llega la factura.",
                    ['texto' => "Usar {$bueno}", 'campos' => ['email' => $bueno]]);
            }
        }

        // 7. El teléfono con letras o notas ("3128251383 JUAN M"): se deja el número.
        $tel = trim((string) ($c['telefono'] ?? ''));
        if ($tel !== '' && preg_match('/[A-Za-zÁÉÍÓÚáéíóúÑñ]/u', $tel)) {
            $numero = preg_match('/\+?\d[\d\s-]{6,}\d/', $tel, $m) ? preg_replace('/[\s-]/', '', $m[0]) : null;
            $salida[] = self::problema('info', 'telefono_con_texto',
                "El teléfono dice \"{$tel}\": en la factura va solo el número.",
                $numero ? ['texto' => "Dejar {$numero}", 'campos' => ['telefono' => $numero]] : null);
        }

        return $salida;
    }

    /**
     * Nombres de empresa en Colombia: SAS, LTDA, S.A., E.U., cooperativas,
     * fundaciones… Se compara sin tildes, sin puntos y en mayúscula.
     */
    public static function pareceEmpresa(string $nombre): bool
    {
        $n = strtoupper(\Illuminate\Support\Str::ascii($nombre));
        $n = preg_replace('/[.,]/', '', $n);
        $n = preg_replace('/\s+/', ' ', trim($n));

        return (bool) preg_match('/\b(S ?A ?S|LTDA|LIMITADA|S EN C|S ?C ?A|COOPERATIVA|FUNDACION|ASOCIACION|CORPORACION|CONSORCIO|UNION TEMPORAL|SOCIEDAD)\b/', $n)
            || (bool) preg_match('/\b(S ?A|E ?U|E ?S ?P|S ?A ?S BIC)$/', $n);
    }

    /**
     * Si el número es un NIT de empresa: [nit sin dv, dv]. Los de empresa
     * empiezan por 8 o 9 y tienen 9 cifras; con 10 que cuadren con el dígito de
     * verificación, el último es el dígito pegado.
     */
    public static function nitDeEmpresa(string $doc): ?array
    {
        if (! ctype_digit($doc) || ! in_array($doc[0] ?? '', ['8', '9'], true)) return null;
        if (strlen($doc) === 9) {
            return [$doc, (string) ArmarFactura::digitoVerificacion($doc)];
        }
        if (strlen($doc) === 10) {
            $base = substr($doc, 0, 9);
            if ((string) ArmarFactura::digitoVerificacion($base) === substr($doc, -1)) {
                return [$base, substr($doc, -1)];
            }
        }

        return null;
    }

    private static function nombreTipo(string $tipo): string
    {
        return match ($tipo) {
            'CC' => 'cédula', 'CE' => 'cédula de extranjería', 'TI' => 'tarjeta de identidad', 'RC' => 'registro civil',
            'PP' => 'pasaporte', '' => 'sin tipo de documento', default => $tipo,
        };
    }

    private static function problema(string $tipo, string $codigo, string $mensaje, ?array $arreglo = null): array
    {
        return compact('tipo', 'codigo', 'mensaje', 'arreglo');
    }
}
