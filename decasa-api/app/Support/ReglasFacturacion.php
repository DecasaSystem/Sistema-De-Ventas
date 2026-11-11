<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cómo se factura: todo lo decide el negocio desde Facturación electrónica →
 * Ajustes, nada fijo en el código (dueño, 2026-11-09: "puede que algún día
 * cambien la manera de facturar; cambiarlo desde el programa es la mejor
 * opción"). Se guarda en `configuracion` (clave `facturacion_electronica`),
 * como ConfigFinanzas.
 *
 * Los valores de fábrica son los que el dueño pidió ese día: se factura cuando
 * la orden se entrega, las FV2 no se facturan y antes de facturar el
 * facturador lo revisa y toca el botón. Y el módulo viene APAGADO: hasta que
 * un supervisor lo prenda y conecte Alegra, el sistema funciona como antes.
 */
class ReglasFacturacion
{
    public const CLAVE = 'facturacion_electronica';

    /** Los medios de pago que guarda el sistema en `pagos.metodo`. */
    public const METODOS = ['efectivo', 'transferencia', 'tarjeta', 'addi', 'otro'];

    /** Cuándo se factura. */
    public const MOMENTOS = [
        // Una factura por orden, con todo, cuando el cliente ya lo recibió todo.
        'al_entregar',
        // Una factura por orden, con todo, apenas entra el primer pago.
        'al_confirmar',
        // Una factura por cada pago (anticipo, abono, saldo), por lo que pagó.
        'por_pago',
    ];

    /** Medios de pago de la DIAN (catálogo de Alegra para Colombia) que se ofrecen. */
    public const MEDIOS_DIAN = [
        'CASH'                  => 'Efectivo',
        'CREDIT_TRANSFER'       => 'Transferencia crédito',
        'DEBIT_TRANSFER'        => 'Transferencia débito',
        'CREDIT_TRANSFER_BANK'  => 'Transferencia crédito bancario',
        'DEBIT_TRANSFER_BANK'   => 'Transferencia débito bancaria',
        'BANK_DEPOSIT'          => 'Consignación bancaria',
        'CREDIT_CARD'           => 'Tarjeta crédito',
        'DEBIT_CARD'            => 'Tarjeta débito',
        'CHECK'                 => 'Cheque',
        'MUTUAL_AGREEMENT'      => 'Otro (acuerdo mutuo)',
        'INSTRUMENT_NOT_DEFINED' => 'Instrumento no definido',
    ];

    /** Cómo se llama cada pago en Alegra (atributo paymentMethod de payments). */
    public const MEDIOS_PAGO_ALEGRA = ['cash', 'transfer', 'deposit', 'check', 'credit-card', 'debit-card'];

    public const DEFECTO = [
        // ── Encendido ──────────────────────────────────────────────────────────
        'activo' => false,
        // 'borrador': crea la factura en Alegra sin mandarla a la DIAN (para
        // probar semanas comparando con lo que se hace a mano). 'emitir': la
        // manda a la DIAN y ya es legal.
        'modo'   => 'borrador',
        // Solo lo de esta fecha en adelante (Y-m-d). Lo de antes se facturó a mano.
        'desde'  => null,

        // ── Qué y cuándo ───────────────────────────────────────────────────────
        'momento'                 => 'al_entregar',
        'facturar_fv2'            => false,
        'facturar_restauraciones' => true,
        'facturar_independientes' => true,
        // El facturador revisa y toca "Facturar". Apagado, una tarea cada 5
        // minutos factura sola lo que no tenga ninguna advertencia.
        'requiere_revision'       => true,

        // ── Cliente ────────────────────────────────────────────────────────────
        // Sin cédula: 'bloquear' (el facturador la completa) o 'consumidor_final'.
        'sin_documento'           => 'bloquear',
        'consumidor_final_id'     => null,

        // ── Plata ──────────────────────────────────────────────────────────────
        // Los precios del sistema traen IVA; la factura los manda sin IVA.
        'iva_pct'                 => 19,
        'iva_en_restauraciones'   => true,
        // Decimales con que se manda el precio sin IVA a Alegra.
        'decimales'               => 2,
        // Registrar en Alegra los pagos del cliente (a qué caja/banco entró cada uno).
        'registrar_pagos'         => true,
        // Si queda saldo, la factura sale a crédito con este plazo.
        'dias_credito'            => 30,
        // Avisa si un precio queda por debajo de este % del de lista (o por encima
        // de 100 + este %): el caso "se les fue un cero".
        'alerta_precio_pct'       => 50,
        // Horas después de un cambio de plata en las que se pide mirar otra vez.
        'horas_cambio_reciente'   => 24,
        // Avisar cuando a la resolución DIAN le falten estos días o estos números
        // (2026-11-11: FEN tenía 83 números y vencía en 3 meses).
        'aviso_numeracion_dias'    => 30,
        'aviso_numeracion_numeros' => 100,

        // ── Alegra ─────────────────────────────────────────────────────────────
        'impuesto_iva_id'         => null,
        'item_producto_id'        => null,
        'item_servicio_id'        => null,
        // { "*": idNumeracion, "<tienda_id>": idNumeracion }
        'numeraciones'            => [],
        // { "*": { "efectivo": idCuenta, ... }, "<tienda_id>": { ... } }
        'cuentas'                 => [],
        'medios_dian'             => [
            'efectivo'      => 'CASH',
            'transferencia' => 'CREDIT_TRANSFER',
            'tarjeta'       => 'CREDIT_CARD',
            'addi'          => 'MUTUAL_AGREEMENT',
            'otro'          => 'MUTUAL_AGREEMENT',
        ],
        'medios_pago'             => [
            'efectivo'      => 'cash',
            'transferencia' => 'transfer',
            'tarjeta'       => 'credit-card',
            'addi'          => 'transfer',
            'otro'          => 'cash',
        ],
        'enviar_correo'           => false,
        // Texto que se ve en el PDF ("Notas"). {orden} se cambia por la referencia.
        'nota_factura'            => 'Orden de venta {orden}',
    ];

    private static ?array $cache = null;

    public static function olvidar(): void
    {
        self::$cache = null;
        Configuracion::olvidar();
    }

    public static function todo(): array
    {
        if (self::$cache !== null) return self::$cache;

        $guardado = [];
        try {
            if (Esquema::tabla('configuracion')) {
                $v = Configuracion::valor(self::CLAVE);
                $guardado = $v ? (json_decode($v, true) ?: []) : [];
            }
        } catch (\Throwable) {
            $guardado = [];
        }

        $todo = array_merge(self::DEFECTO, array_intersect_key($guardado, self::DEFECTO));
        // Los mapas por método se completan con los de fábrica: un método nuevo
        // no se queda sin equivalencia.
        $todo['medios_dian'] = array_merge(self::DEFECTO['medios_dian'], (array) $todo['medios_dian']);
        $todo['medios_pago'] = array_merge(self::DEFECTO['medios_pago'], (array) $todo['medios_pago']);

        return self::$cache = $todo;
    }

    public static function get(string $clave)
    {
        return self::todo()[$clave] ?? null;
    }

    public static function activo(): bool
    {
        return (bool) self::get('activo');
    }

    public static function emite(): bool
    {
        return self::get('modo') === 'emitir';
    }

    public static function porPago(): bool
    {
        return self::get('momento') === 'por_pago';
    }

    /**
     * Guarda lo que llegue, limpiando cada valor. Lo que no se reconoce se
     * ignora; un valor raro vuelve al de fábrica en vez de romper la factura.
     *
     * @return array{0: array, 1: string[]} [reglas guardadas, errores]
     */
    public static function guardar(array $valores): array
    {
        $actual  = self::todo();
        $nuevo   = $actual;
        $errores = [];

        foreach (['activo', 'facturar_fv2', 'facturar_restauraciones', 'facturar_independientes',
                  'requiere_revision', 'iva_en_restauraciones', 'registrar_pagos', 'enviar_correo'] as $b) {
            if (array_key_exists($b, $valores)) $nuevo[$b] = (bool) $valores[$b];
        }

        if (array_key_exists('modo', $valores)) {
            in_array($valores['modo'], ['borrador', 'emitir'], true)
                ? $nuevo['modo'] = $valores['modo']
                : $errores[] = 'El modo es "borrador" o "emitir".';
        }
        if (array_key_exists('momento', $valores)) {
            in_array($valores['momento'], self::MOMENTOS, true)
                ? $nuevo['momento'] = $valores['momento']
                : $errores[] = 'Ese momento para facturar no existe.';
        }
        if (array_key_exists('sin_documento', $valores)) {
            in_array($valores['sin_documento'], ['bloquear', 'consumidor_final'], true)
                ? $nuevo['sin_documento'] = $valores['sin_documento']
                : $errores[] = 'Sin documento: "bloquear" o "consumidor_final".';
        }
        if (array_key_exists('desde', $valores)) {
            $d = $valores['desde'];
            if ($d === null || $d === '') {
                $nuevo['desde'] = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
                $nuevo['desde'] = $d;
            } else {
                $errores[] = 'La fecha "desde" va como AAAA-MM-DD.';
            }
        }

        $numeros = [
            'iva_pct' => [0, 100], 'decimales' => [0, 6], 'dias_credito' => [0, 365],
            'alerta_precio_pct' => [0, 100], 'horas_cambio_reciente' => [0, 720],
            'aviso_numeracion_dias' => [0, 365], 'aviso_numeracion_numeros' => [0, 100000],
        ];
        foreach ($numeros as $n => [$min, $max]) {
            if (! array_key_exists($n, $valores)) continue;
            $v = $valores[$n];
            if (! is_numeric($v) || $v < $min || $v > $max) {
                $errores[] = "{$n} va entre {$min} y {$max}.";
                continue;
            }
            $nuevo[$n] = in_array($n, ['iva_pct'], true) ? (float) $v : (int) $v;
        }

        foreach (['impuesto_iva_id', 'item_producto_id', 'item_servicio_id', 'consumidor_final_id'] as $id) {
            if (array_key_exists($id, $valores)) $nuevo[$id] = self::id($valores[$id]);
        }
        if (array_key_exists('nota_factura', $valores)) {
            $nuevo['nota_factura'] = mb_substr(trim((string) $valores['nota_factura']), 0, 400);
        }

        if (array_key_exists('numeraciones', $valores)) {
            $nuevo['numeraciones'] = [];
            foreach ((array) $valores['numeraciones'] as $tienda => $id) {
                if (($t = self::llaveTienda($tienda)) !== null && ($i = self::id($id)) !== null) {
                    $nuevo['numeraciones'][$t] = $i;
                }
            }
        }
        if (array_key_exists('cuentas', $valores)) {
            $nuevo['cuentas'] = [];
            foreach ((array) $valores['cuentas'] as $tienda => $porMetodo) {
                if (($t = self::llaveTienda($tienda)) === null) continue;
                foreach ((array) $porMetodo as $metodo => $id) {
                    if (in_array($metodo, self::METODOS, true) && ($i = self::id($id)) !== null) {
                        $nuevo['cuentas'][$t][$metodo] = $i;
                    }
                }
            }
        }
        if (array_key_exists('medios_dian', $valores)) {
            foreach ((array) $valores['medios_dian'] as $metodo => $codigo) {
                if (in_array($metodo, self::METODOS, true) && array_key_exists($codigo, self::MEDIOS_DIAN)) {
                    $nuevo['medios_dian'][$metodo] = $codigo;
                }
            }
        }
        if (array_key_exists('medios_pago', $valores)) {
            foreach ((array) $valores['medios_pago'] as $metodo => $codigo) {
                if (in_array($metodo, self::METODOS, true) && in_array($codigo, self::MEDIOS_PAGO_ALEGRA, true)) {
                    $nuevo['medios_pago'][$metodo] = $codigo;
                }
            }
        }

        // Prenderlo sin fecha "desde" llenaría la lista con todo lo vendido en
        // la historia (ya facturado a mano): se pone hoy.
        if ($nuevo['activo'] && ! $nuevo['desde']) {
            $nuevo['desde'] = now('America/Bogota')->toDateString();
        }
        if ($nuevo['sin_documento'] === 'consumidor_final' && ! $nuevo['consumidor_final_id']) {
            $errores[] = 'Para facturar a consumidor final hay que escoger ese contacto de Alegra.';
            $nuevo['sin_documento'] = $actual['sin_documento'];
        }

        if ($errores) return [$actual, $errores];

        DB::table('configuracion')->updateOrInsert(
            ['clave' => self::CLAVE],
            ['valor' => json_encode($nuevo)],
        );
        self::olvidar();

        return [self::todo(), []];
    }

    /** Numeración de la tienda, o la general. */
    public static function numeracionDe(?int $tiendaId): ?string
    {
        $n = (array) self::get('numeraciones');

        return $n[(string) $tiendaId] ?? $n['*'] ?? null;
    }

    /** Cuenta de Alegra (caja o banco) donde entra un pago de esa tienda con ese medio. */
    public static function cuentaDe(?int $tiendaId, ?string $metodo): ?string
    {
        $c = (array) self::get('cuentas');
        $metodo = $metodo ?: 'otro';

        return $c[(string) $tiendaId][$metodo] ?? $c['*'][$metodo] ?? null;
    }

    private static function id($v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' || ! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) ? null : $v;
    }

    private static function llaveTienda($t): ?string
    {
        $t = (string) $t;

        return $t === '*' || ctype_digit($t) ? $t : null;
    }
}
