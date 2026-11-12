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
        // Contador, 2026-11-12: "las restauraciones no se facturan". En una orden
        // con muebles y restauración, la factura lleva solo los muebles.
        'facturar_restauraciones' => false,
        'facturar_independientes' => true,
        // El facturador revisa y toca "Facturar". Apagado, una tarea cada 5
        // minutos factura sola lo que no tenga ninguna advertencia.
        'requiere_revision'       => true,

        // ── Cliente ────────────────────────────────────────────────────────────
        // Sin cédula: 'bloquear' (el facturador la completa) o 'consumidor_final'.
        'sin_documento'           => 'bloquear',
        'consumidor_final_id'     => null,
        // Contador, 2026-11-12: lo mínimo es nombre completo, cédula y correo.
        'exigir_correo'           => true,

        // ── Plata ──────────────────────────────────────────────────────────────
        // Los precios del sistema traen IVA; la factura los manda sin IVA.
        'iva_pct'                 => 19,
        'iva_en_restauraciones'   => true,
        // Decimales con que se manda el precio sin IVA a Alegra.
        'decimales'               => 2,
        // Registrar en Alegra los pagos del cliente (a qué caja/banco entró cada uno).
        // Apagado de fábrica (2026-11-11): si hoy ya registran los recibos en
        // Alegra a mano, prenderlo duplicaría la caja allá. Lo prende el
        // supervisor cuando el contador confirme.
        'registrar_pagos'         => false,
        // Con qué fecha va cada pago a Alegra: 'factura' (el día de la factura,
        // siempre lo acepta) o 'recibido' (el día que entró la plata; un
        // anticipo de antes de la factura puede no aceptarse). Lo decide el contador.
        'fecha_pagos'             => 'factura',
        // Si queda saldo, la factura sale a crédito con este plazo. Contador,
        // 2026-11-12: "normalmente no se vende a crédito; que sea a un día, o 15
        // o 30, personalizable".
        'dias_credito'            => 1,
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
        // Cómo va cada mueble en la factura (FEN1917, 2026-11-12):
        //  'por_producto': cada mueble con SU producto de Alegra (así factura
        //   Decasa: ítem "SOFA TELAVID", descripción "TAUPE"); lo que no es del
        //   catálogo (diseños especiales) va con item_producto_id.
        //  'generico': todo con item_producto_id y el detalle en la descripción.
        'items_alegra'            => 'por_producto',
        // El producto "comodín": lo de diseño propio/personalizado sin producto
        // del catálogo, o todo si items_alegra = 'generico'.
        'item_producto_id'        => null,
        'item_servicio_id'        => null,
        // Bodega y centro de costo de Alegra por tienda (FEN1917: bodega
        // "3 - EDEN", centro de costo "EDEN"). { "*": id, "<tienda_id>": id }
        'bodegas'                 => [],
        'centros_costo'           => [],
        // { "*": idNumeracion, "<tienda_id>": idNumeracion }
        'numeraciones'            => [],
        // { "*": { "efectivo": idCuenta, ... }, "<tienda_id>": { ... } }
        'cuentas'                 => [],
        'medios_dian'             => [
            'efectivo'      => 'CASH',
            // Así salen hoy las de Decasa (FEN1917: "Transferencia débito").
            'transferencia' => 'DEBIT_TRANSFER',
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
        // Que el sistema le pida a Alegra mandar la factura por correo. Apagado:
        // Alegra ya se la manda sola al cliente al emitir (FEN1917: "Envío de
        // factura al cliente: Entregada"); prenderlo le haría llegar dos.
        'enviar_correo'           => false,
        // "Notas" del PDF. Va el texto que llevan hoy las facturas de Decasa (la
        // nota del régimen ZESE, FEN1917): mandar otro la reemplazaría. {orden}
        // se cambia por la referencia de la orden.
        'nota_factura'            => "Somo beneficiarios del régimen tributario especial ZESE, desde 01/07/2020. consagrado en el artículo 268 de la Ley 1955 de 2019 y reglamentado por el Decreto 2112 del 24 de noviembre de 2019. Tarifa general de renta, retención en la fuente y autorretención a título de impuesto sobre la renta (0%) por los primeros 5 años y del 50% durante los 5 años siguientes.\nOrden de venta {orden}",
        // "Términos y condiciones" del PDF, como en FEN1917.
        'terminos_factura'        => 'Esta factura se asimila en todos sus efectos a una letra de cambio de conformidad con el Art. 774 del código de comercio. Autorizo que en caso de incumplimiento de esta obligación sea reportado a las centrales de riesgo, se cobraran intereses por mora.',
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

        // La nota de fábrica de antes ("Orden de venta {orden}") taparía la nota
        // del régimen ZESE que llevan las facturas de Decasa: si quedó guardada
        // tal cual, se usa la nueva (2026-11-12).
        if (($guardado['nota_factura'] ?? null) === 'Orden de venta {orden}') unset($guardado['nota_factura']);

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
                  'requiere_revision', 'iva_en_restauraciones', 'registrar_pagos', 'enviar_correo', 'exigir_correo'] as $b) {
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
        if (array_key_exists('fecha_pagos', $valores)) {
            in_array($valores['fecha_pagos'], ['factura', 'recibido'], true)
                ? $nuevo['fecha_pagos'] = $valores['fecha_pagos']
                : $errores[] = 'La fecha de los pagos es "factura" o "recibido".';
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
        // Alegra deja 500 caracteres en cada uno.
        if (array_key_exists('nota_factura', $valores)) {
            $nuevo['nota_factura'] = mb_substr(trim((string) $valores['nota_factura']), 0, 480);
        }
        if (array_key_exists('terminos_factura', $valores)) {
            $nuevo['terminos_factura'] = mb_substr(trim((string) $valores['terminos_factura']), 0, 500);
        }
        if (array_key_exists('items_alegra', $valores)) {
            in_array($valores['items_alegra'], ['por_producto', 'generico'], true)
                ? $nuevo['items_alegra'] = $valores['items_alegra']
                : $errores[] = 'Los productos van "por_producto" o "generico".';
        }
        foreach (['bodegas', 'centros_costo'] as $mapa) {
            if (! array_key_exists($mapa, $valores)) continue;
            $nuevo[$mapa] = [];
            foreach ((array) $valores[$mapa] as $tienda => $id) {
                if (($t = self::llaveTienda($tienda)) !== null && ($i = self::id($id)) !== null) {
                    $nuevo[$mapa][$t] = $i;
                }
            }
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

    /** Bodega de Alegra de la tienda, o la general. */
    public static function bodegaDe(?int $tiendaId): ?string
    {
        $b = (array) self::get('bodegas');

        return $b[(string) $tiendaId] ?? $b['*'] ?? null;
    }

    /** Centro de costo de Alegra de la tienda, o el general. */
    public static function centroCostoDe(?int $tiendaId): ?string
    {
        $c = (array) self::get('centros_costo');

        return $c[(string) $tiendaId] ?? $c['*'] ?? null;
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
