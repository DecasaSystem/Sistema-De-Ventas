<?php

namespace App\Support;

/**
 * Qué avisos manda el sistema, agrupados como los entiende la gente, para que
 * en Trabajadores se puedan apagar por persona.
 *
 * Pedido del dueño (2026-10-22): "hay supervisores que no necesitan las de
 * cambios de dinero, ni productos atrasados ni las de próximos de entrega pero
 * sí las de nuevas ventas, chats…". Se apaga por GRUPO y no por tipo: los ~60
 * tipos del código son detalles ("orden lista", "paso terminado", "en
 * producción" son todos el avance del taller) y una lista de 60 interruptores
 * nadie la entiende.
 *
 * Cada tipo que se le pase a NotificacionService::crear tiene que estar en un
 * grupo (lo revisa TiposNotificacionTest). Un tipo nuevo sin grupo llega siempre.
 *
 * `columna`: dos grupos ya tenían su interruptor propio en `usuarios` desde
 * antes (y se revisan donde se manda el aviso, no aquí): se siguen guardando
 * ahí para no cambiar a quién le llegan hoy. El de agotados es al revés que
 * los demás: le llega solo a quien lo tenga prendido (el que surte).
 *
 * `aviso`: grupos de algo que esa persona tiene que HACER (aprobar, validar,
 * salir a una ruta). Se pueden apagar igual, pero la pantalla advierte.
 */
class TiposNotificacion
{
    public const GRUPOS = [
        'ventas' => [
            'nombre'      => 'Nuevas ventas',
            'descripcion' => 'Cada orden o restauración nueva, la venta de una tienda con producto de otra y el producto tomado de la reserva de fábrica.',
            'tipos'       => ['venta_nueva', 'venta_otra_tienda', 'reserva_fabrica'],
        ],
        'asignar_fecha' => [
            'nombre'      => 'Órdenes para asignar fecha',
            'descripcion' => 'Cuando entra una orden que necesita fecha de entrega. Solo a supervisores.',
            'tipos'       => ['asignar_fecha'],
            'columna'     => 'notif_asignar_fecha',
        ],
        'pagos' => [
            'nombre'      => 'Abonos y facturación',
            'descripcion' => 'Cada pago registrado en una orden (a quien factura) y los cambios por garantía con diferencia para revisar la factura.',
            'tipos'       => ['abono_registrado', 'facturar'],
        ],
        'cambios_dinero' => [
            'nombre'      => 'Cambios de plata en órdenes',
            'descripcion' => 'Cuando cambia el valor de una orden ya facturada, o el descuento por pago en efectivo se pierde o se devuelve.',
            'tipos'       => ['cambio_dinero', 'descuento_devuelto', 'descuento_revertido'],
        ],
        'solicitudes' => [
            'nombre'      => 'Solicitudes de cambio',
            'descripcion' => 'Lo que un vendedor le pide aprobar a un supervisor (precio, abono, anticipo) y la respuesta que recibe el vendedor.',
            'tipos'       => ['solicitud_cambio', 'solicitud_cambio_respuesta'],
            'aviso'       => 'Si es quien aprueba, sin este aviso tiene que revisar las solicitudes a mano.',
        ],
        'ordenes' => [
            'nombre'      => 'Cambios en las órdenes',
            'descripcion' => 'Una orden editada o cancelada, y cuando ya tiene fecha de entrega.',
            'tipos'       => ['orden_editada', 'cancelado', 'fecha_asignada'],
        ],
        'produccion' => [
            'nombre'      => 'Avance de producción',
            'descripcion' => 'Lo que entra al taller, cada paso terminado o asignado, y cuando una orden queda lista para entregar.',
            'tipos'       => ['en_produccion', 'paso_produccion', 'listo_entrega', 'produccion'],
        ],
        'atrasados' => [
            'nombre'      => 'Productos atrasados',
            'descripcion' => 'Cuando un producto pasó la fecha prometida y no está listo.',
            'tipos'       => ['retrasado'],
        ],
        'por_entregar' => [
            'nombre'      => 'Próximos a entregar',
            'descripcion' => 'Las órdenes que se entregan hoy y las que están por vencer su fecha.',
            'tipos'       => ['entrega_hoy', 'por_vencer'],
        ],
        'entregas' => [
            'nombre'      => 'Despacho y entregas',
            'descripcion' => 'Orden entregada, entrega con novedad, ruta asignada o reprogramada, producto que volvió en el camión y recogidas.',
            'tipos'       => ['entregado', 'entrega_con_novedad', 'despacho_asignado', 'ruta_atrasada', 'devolucion', 'despacho'],
            'aviso'       => 'A un conductor este aviso le dice qué ruta le asignaron.',
        ],
        'chats' => [
            'nombre'      => 'Mensajes y chats',
            'descripcion' => 'Cuando le escriben en el chat de una orden o de una consulta de precio.',
            'tipos'       => ['orden_mensaje', 'consulta_costo_mensaje'],
        ],
        'consultas_precio' => [
            'nombre'      => 'Consultas de precio',
            'descripcion' => 'Una consulta de precio nueva para responder, y cuando le responden la suya.',
            'tipos'       => ['consulta_costo_nueva', 'consulta_costo_respondida'],
            'aviso'       => 'Si es quien pone los precios, sin este aviso no se entera de las consultas nuevas.',
        ],
        'cotizaciones' => [
            'nombre'      => 'Cotizaciones por vencer',
            'descripcion' => 'Una cotización suya que está por vencer o que ya venció.',
            'tipos'       => ['cotizacion_por_vencer', 'cotizacion_vencida'],
        ],
        'garantias' => [
            'nombre'      => 'Garantías',
            'descripcion' => 'Garantías reportadas, aprobadas o con visita programada.',
            'tipos'       => ['garantia'],
        ],
        'firmas' => [
            'nombre'      => 'Firmas del cliente',
            'descripcion' => 'Cuando el cliente firma el anexo por el enlace o pide un cambio en lo que va a firmar.',
            'tipos'       => ['anexo_firmado', 'anexo_cambio'],
        ],
        'traslados' => [
            'nombre'      => 'Traslados y surtido',
            'descripcion' => 'Mercancía que se manda entre tiendas: enviada, recibida, aceptada, rechazada o que no se pudo mover.',
            'tipos'       => [
                'traslado_pendiente', 'traslado_aceptado', 'traslado_rechazado', 'traslado_recibido',
                'traslado_completado', 'traslado_fallido',
                'surtido_enviado', 'surtido_aceptado', 'surtido_rechazado',
            ],
            'aviso'       => 'Si es quien valida lo que llega a su tienda, sin este aviso tiene que revisarlo a mano.',
        ],
        'agotados' => [
            'nombre'      => 'Producto agotado',
            'descripcion' => 'Cuando se vende la última unidad de un producto. Le llega solo a quien lo tenga prendido (normalmente quien surte).',
            'tipos'       => ['stock_agotado'],
            'columna'     => 'notif_stock',
        ],
        'telas' => [
            'nombre'      => 'Telas recargadas',
            'descripcion' => 'Cuando se agregan metros de una tela al inventario.',
            'tipos'       => ['tela_recargada'],
        ],
        'redes' => [
            'nombre'      => 'Redes y citas',
            'descripcion' => 'Clientes de WhatsApp e Instagram que pide pasar Elena y los recordatorios de citas.',
            'tipos'       => ['redes', 'cita_recordatorio'],
        ],
        'encargos' => [
            'nombre'      => 'Encargos y herramientas',
            'descripcion' => 'Cuando toca revisar los encargos y los descuentos por herramienta perdida.',
            'tipos'       => ['encargo_revision', 'encargo_descuento'],
        ],
        'comisiones' => [
            'nombre'      => 'Comisiones',
            'descripcion' => 'El día de pago: cuántas personas y cuánta plata hay en comisiones listas para entregar.',
            'tipos'       => ['comisiones'],
        ],
        'finanzas' => [
            'nombre'      => 'Finanzas',
            'descripcion' => 'Gastos por vencer y el resumen financiero del mes.',
            'tipos'       => ['finanzas'],
        ],
    ];

    private static ?array $grupoDeTipo = null;

    /** El grupo al que pertenece un tipo, o null si no está en ninguno (llega siempre). */
    public static function grupoDe(string $tipo): ?string
    {
        if (self::$grupoDeTipo === null) {
            self::$grupoDeTipo = [];
            foreach (self::GRUPOS as $clave => $g) {
                foreach ($g['tipos'] as $t) self::$grupoDeTipo[$t] = $clave;
            }
        }
        return self::$grupoDeTipo[$tipo] ?? null;
    }

    /** Claves que se guardan en `usuarios.notificaciones_apagadas` (sin las de columna propia). */
    public static function clavesApagables(): array
    {
        return array_keys(array_filter(self::GRUPOS, fn ($g) => empty($g['columna'])));
    }

    // ── Quién tiene qué apagado (se lee una vez por petición) ───────────────

    private static ?array $apagadas = null;

    /**
     * ¿Esta persona apagó los avisos de este tipo? Una sola consulta por
     * petición para todos: crear un aviso no puede costar una consulta más
     * por destinatario (cada una son ~200 ms con la base lejos).
     */
    public static function estaApagado(int $usuarioId, string $tipo): bool
    {
        $grupo = self::grupoDe($tipo);
        if ($grupo === null || ! empty(self::GRUPOS[$grupo]['columna'])) return false;

        return in_array($grupo, self::apagadasDe($usuarioId), true);
    }

    public static function apagadasDe(int $usuarioId): array
    {
        if (self::$apagadas === null) {
            self::$apagadas = [];
            if (Esquema::columna('usuarios', 'notificaciones_apagadas')) {
                $filas = \DB::table('usuarios')
                    ->whereNotNull('notificaciones_apagadas')
                    ->pluck('notificaciones_apagadas', 'id');
                foreach ($filas as $id => $json) {
                    $lista = json_decode((string) $json, true);
                    if (is_array($lista) && $lista) self::$apagadas[(int) $id] = $lista;
                }
            }
        }
        return self::$apagadas[$usuarioId] ?? [];
    }

    public static function olvidarCache(): void
    {
        self::$apagadas = null;
    }
}
