<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\ClienteRed;
use App\Models\ConversacionWa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/**
 * Arma y actualiza la ficha del cliente de redes con cada aviso de los agentes.
 *
 * Una sola puerta: la llama el webhook de Redes DESPUÉS de crear la tarjeta y
 * dentro de un try/catch. Si esto falla, la tarjeta y los avisos al celular
 * salen igual: perder la ficha es malo, perder la tarjeta es perder la venta.
 *
 * Lo que manda el agente en `contacto` es texto de un chat: se limpia aquí (se
 * recorta, se normaliza el celular) en vez de validarlo en el webhook, porque un
 * 422 hace que el agente dé el aviso por rechazado y lo descarte.
 */
class ClientesRedes
{
    /** Avisos que no son de un cliente: no crean ficha. */
    private const NO_SON_CLIENTES = ['PROVEEDOR / PROPUESTA COMERCIAL'];

    public static function registrarDesdeAviso(ConversacionWa $conv, ?array $contacto = null, bool $esCancelacion = false): ?ClienteRed
    {
        if (! Schema::hasTable('clientes_redes')) return null;
        foreach (self::NO_SON_CLIENTES as $marca) {
            if (str_contains((string) $conv->resumen, $marca)) return null;
        }

        $canal = $conv->fuente === 'instagram' ? 'instagram' : 'whatsapp';
        $identificador = mb_substr(trim((string) $conv->telefono), 0, 80);
        if ($identificador === '') return null;

        try {
            return self::guardar($conv, $canal, $identificador, $contacto ?? [], $esCancelacion);
        } catch (UniqueConstraintViolationException) {
            // Dos avisos de la misma persona al mismo tiempo: el otro ya creó la fila.
            return self::guardar($conv, $canal, $identificador, $contacto ?? [], $esCancelacion);
        }
    }

    private static function guardar(ConversacionWa $conv, string $canal, string $identificador, array $c, bool $esCancelacion): ClienteRed
    {
        $ficha = ClienteRed::firstOrNew(['canal' => $canal, 'identificador' => $identificador]);
        $esNueva = ! $ficha->exists;

        // Lo nuevo manda, salvo que venga vacío: un aviso sin ciudad no borra la que dio antes.
        $nombre = self::texto($c['nombre'] ?? null, 120);
        if ($nombre) {
            $ficha->nombre = $nombre;
        } elseif (! $ficha->nombre && $conv->nombre_cliente) {
            // Respaldo: el nombre del perfil de WhatsApp o de Instagram.
            $ficha->nombre = self::texto($conv->nombre_cliente, 120);
        }

        $telefono = self::telefono($c['telefono'] ?? null);
        if ($telefono) {
            $ficha->telefono = $telefono;
        } elseif (! $ficha->telefono && $canal === 'whatsapp') {
            // En WhatsApp el número del chat ya es un celular verificado.
            $ficha->telefono = self::telefono($identificador);
        }

        foreach (['usuario_red' => 80, 'ciudad' => 80, 'forma_pago' => 40, 'espacio' => 120] as $campo => $max) {
            $valor = self::texto($c[$campo] ?? null, $max);
            if ($valor) $ficha->{$campo} = $valor;
        }
        if (is_numeric($c['presupuesto'] ?? null) && (int) $c['presupuesto'] > 0) {
            $ficha->presupuesto = (int) $c['presupuesto'];
        }
        foreach (['preferencias' => 5, 'productos_interes' => 6] as $campo => $max) {
            $lista = self::lista($c[$campo] ?? null, $max);
            if ($lista) $ficha->{$campo} = $lista;
        }
        if (! empty($c['no_quiso_dar_datos'])) {
            $ficha->no_quiso_dar_datos = true;
        } elseif ($nombre || $telefono) {
            $ficha->no_quiso_dar_datos = false;
        }

        if ($conv->contacto_url) $ficha->contacto_url = mb_substr($conv->contacto_url, 0, 255);
        if ($conv->tienda_id) $ficha->tienda_id = $conv->tienda_id;

        $ficha->ultimo_interes = mb_substr((string) $conv->resumen, 0, 1000);
        $ficha->ultimo_tipo = mb_substr((string) $conv->tipo, 0, 20);
        $ficha->ultima_conversacion_id = $conv->id;
        $ficha->total_conversaciones = ($ficha->total_conversaciones ?? 0) + 1;
        $ficha->primer_contacto_at ??= now();
        $ficha->ultimo_contacto_at = now();

        // Estado: quien ya compró o se había dado por perdido y vuelve a escribir es una
        // oportunidad nueva. Si alguien lo está trabajando ("contactado"), se respeta. La
        // cancelación de una cita no reabre nada.
        if ($esNueva) {
            $ficha->estado = 'nuevo';
        } elseif (! $esCancelacion && in_array($ficha->estado, ['compro', 'perdido'], true)) {
            $ficha->estado = 'nuevo';
        }

        if (! $ficha->cliente_id && $ficha->telefono) {
            $ficha->cliente_id = self::clientePorTelefono($ficha->telefono);
        }

        $ficha->save();
        return $ficha;
    }

    /**
     * Cliente oficial con el mismo celular (últimos 10 dígitos), para enlazarlos.
     * En `clientes` el teléfono está escrito de mil formas ("300 123 4567",
     * "+57 3001234567"), por eso se compara sin espacios, guiones ni "+".
     */
    public static function clientePorTelefono(?string $telefono): ?int
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono);
        if (strlen($digitos) < 10) return null;
        $ultimos = substr($digitos, -10);

        return Cliente::whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(telefono, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?",
            ['%' . $ultimos]
        )->orderBy('id')->value('id');
    }

    /** "+57 300-123 4567" → "+573001234567". Lo que no parezca un teléfono → null. */
    public static function telefono($valor): ?string
    {
        $crudo = trim((string) $valor);
        if ($crudo === '') return null;
        $digitos = preg_replace('/\D/', '', $crudo);
        if (strlen($digitos) === 12 && str_starts_with($digitos, '57')) $digitos = substr($digitos, 2);
        if (strlen($digitos) === 10 && preg_match('/^(3|60)/', $digitos)) return '+57' . $digitos;
        if (str_starts_with($crudo, '+') && strlen($digitos) >= 8 && strlen($digitos) <= 15) return '+' . $digitos;
        return null;
    }

    private static function texto($valor, int $max): ?string
    {
        if (! is_scalar($valor)) return null;
        $limpio = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $valor)));
        return $limpio === '' ? null : mb_substr($limpio, 0, $max);
    }

    private static function lista($valor, int $max): ?array
    {
        if (! is_array($valor)) return null;
        $lista = array_values(array_filter(array_map(fn ($v) => self::texto($v, 80), $valor)));
        return $lista ? array_slice($lista, 0, $max) : null;
    }
}
