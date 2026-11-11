<?php

namespace App\Services\Alegra;

use App\Support\ReglasFacturacion;
use Illuminate\Support\Facades\Cache;

/**
 * ¿Sirve todavía una numeración (resolución DIAN) de Alegra?
 *
 * Caso real (2026-11-11): de las 4 numeraciones de Decasa, FEU había vencido
 * cuatro días antes y FP PEREIRA en febrero, y a FEN le quedaban 83 números.
 * Una factura con una resolución vencida o agotada la rechaza la DIAN, y eso
 * solo se veía al tocar Facturar. Ahora se dice antes: en Ajustes, al lado de
 * cada numeración, y en la revisión de cada factura.
 *
 * Alegra trae `endDate` (hasta cuándo vale), `maxInvoiceNumber` (último número
 * autorizado), `nextInvoiceNumber` y `status`. Si alguno no viene, se saca del
 * texto de la resolución ("…hasta N° FEN2000 con vigencia hasta 2027-02-20").
 */
class EstadoNumeracion
{
    /** Minutos que se recuerda lo que dijo Alegra (la revisión se abre muchas veces). */
    private const MINUTOS_CACHE = 10;

    /**
     * @return array{id: string, nombre: string, prefijo: string, vence: ?string, siguiente: ?int,
     *               maximo: ?int, quedan: ?int, dias: ?int, estado: string, sirve: bool, mensaje: string}
     */
    public static function de(array $n): array
    {
        $texto = implode(' ', array_filter([
            is_string($n['invoiceText'] ?? null) ? $n['invoiceText'] : null,
            is_string($n['text'] ?? null) ? $n['text'] : null,
            is_string($n['resolution'] ?? null) ? $n['resolution'] : null,
        ]));

        $vence = self::fecha($n['endDate'] ?? null);
        if (! $vence && preg_match('/vigencia\s+hasta\s+(\d{4}-\d{2}-\d{2})/i', $texto, $m)) $vence = $m[1];

        $maximo = is_numeric($n['maxInvoiceNumber'] ?? null) ? (int) $n['maxInvoiceNumber'] : null;
        // "Desde N° FEP212 hasta FEP1000 con vigencia hasta 2026-02-18": el primer
        // "hasta" es el del rango (el de la fecha va después).
        if ($maximo === null && preg_match('/hasta\s+(?:N[°º]?\s*)?[A-Za-z]*\s*(\d+)(?!-)/u', $texto, $m)) $maximo = (int) $m[1];

        $siguiente = is_numeric($n['nextInvoiceNumber'] ?? null) ? (int) $n['nextInvoiceNumber'] : null;
        $quedan = ($maximo !== null && $siguiente !== null) ? max(0, $maximo - $siguiente + 1) : null;

        $hoy  = now('America/Bogota')->startOfDay();
        $dias = $vence ? (int) $hoy->diffInDays(\Carbon\Carbon::parse($vence, 'America/Bogota')->startOfDay(), false) : null;

        $avisoDias    = (int) ReglasFacturacion::get('aviso_numeracion_dias');
        $avisoNumeros = (int) ReglasFacturacion::get('aviso_numeracion_numeros');
        $nombre = trim((string) ($n['name'] ?? '')) ?: (string) ($n['prefix'] ?? 'Numeración');

        [$estado, $mensaje] = match (true) {
            ($n['status'] ?? 'active') === 'inactive'
                => ['inactiva', "La numeración {$nombre} está inactiva en Alegra."],
            $dias !== null && $dias < 0
                => ['vencida', "La numeración {$nombre} venció el " . self::bonita($vence) . ': la DIAN rechaza lo que se facture con ella. Hay que pedir una resolución nueva.'],
            $quedan === 0
                => ['agotada', "La numeración {$nombre} ya usó todos sus números (hasta el {$maximo}). Hay que pedir una resolución nueva."],
            $dias !== null && $dias <= $avisoDias
                => ['por_vencer', "La numeración {$nombre} vence el " . self::bonita($vence) . " (en {$dias} días). Pidan la nueva a tiempo."],
            $quedan !== null && $quedan <= $avisoNumeros
                => ['pocos', "A la numeración {$nombre} le quedan {$quedan} números. Pidan la ampliación a tiempo."],
            default
                => ['vigente', "Numeración {$nombre} vigente."],
        };

        return [
            'id'        => (string) ($n['id'] ?? ''),
            'nombre'    => $nombre,
            'prefijo'   => (string) ($n['prefix'] ?? ''),
            'vence'     => $vence,
            'siguiente' => $siguiente,
            'maximo'    => $maximo,
            'quedan'    => $quedan,
            'dias'      => $dias,
            'estado'    => $estado,
            // Vencida, agotada o inactiva: no sirve para facturar.
            'sirve'     => ! in_array($estado, ['vencida', 'agotada', 'inactiva'], true),
            'mensaje'   => $mensaje,
        ];
    }

    /** Lo que dice Alegra hoy de esa numeración; null si no se pudo preguntar. */
    public static function consultar(ClienteAlegra $alegra, string $id): ?array
    {
        if (! ClienteAlegra::configurado()) return null;

        $clave = 'alegra_numeracion_' . md5($id);
        if (is_array($guardado = Cache::get($clave))) return self::de($guardado);

        try {
            $r = $alegra->get('number-templates/' . rawurlencode($id));
        } catch (SinRespuestaDeAlegra) {
            return null;
        }
        if (! $r->ok || ! is_array($r->json)) return null;

        Cache::put($clave, $r->json, now()->addMinutes(self::MINUTOS_CACHE));

        return self::de($r->json);
    }

    private static function fecha($v): ?string
    {
        if (! is_string($v) || ! preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m)) return null;

        return $m[1];
    }

    private static function bonita(string $fecha): string
    {
        return \Carbon\Carbon::parse($fecha)->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
