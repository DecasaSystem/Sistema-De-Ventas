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

        $guardar = function () use ($conv, $canal, $identificador, $contacto, $esCancelacion) {
            $ficha = ClienteRed::firstOrNew(['canal' => $canal, 'identificador' => $identificador]);
            $esNueva = ! $ficha->exists;

            self::aplicarContacto($ficha, $canal, $identificador, $contacto ?? [], $conv->nombre_cliente);
            if ($conv->contacto_url) $ficha->contacto_url = mb_substr($conv->contacto_url, 0, 255);
            if ($conv->tienda_id) $ficha->tienda_id = $conv->tienda_id;

            $ficha->ultimo_interes = mb_substr((string) $conv->resumen, 0, 1000);
            $ficha->ultimo_tipo = mb_substr((string) $conv->tipo, 0, 20);
            $ficha->ultima_conversacion_id = $conv->id;
            $ficha->total_conversaciones = ($ficha->total_conversaciones ?? 0) + 1;

            return self::cerrar($ficha, $esNueva, reabrir: ! $esCancelacion);
        };

        try {
            return $guardar();
        } catch (UniqueConstraintViolationException) {
            // Dos avisos de la misma persona al mismo tiempo: el otro ya creó la fila.
            return $guardar();
        }
    }

    /**
     * El cliente dio sus datos (o se aprendió algo de lo que busca) en medio de la
     * conversación, sin pedir asesor: se guarda la ficha SIN crear tarjeta en Redes ni
     * avisar a nadie. Dueño, 2026-10-08: "que se guarden apenas da los datos".
     *
     * Solo se CREA la ficha si trae nombre o celular: una persona que solo saludó no es
     * todavía un cliente de redes. Si ya existe, se actualiza (por ejemplo, el interés).
     * Devuelve null si no había nada que guardar.
     */
    public static function registrarDesdeAgente(string $canal, string $identificador, array $contacto, ?string $contactoUrl = null): ?ClienteRed
    {
        if (! Schema::hasTable('clientes_redes')) return null;
        $canal = $canal === 'instagram' ? 'instagram' : 'whatsapp';
        $identificador = mb_substr(trim($identificador), 0, 80);
        if ($identificador === '') return null;

        $guardar = function () use ($canal, $identificador, $contacto, $contactoUrl) {
            $ficha = ClienteRed::firstOrNew(['canal' => $canal, 'identificador' => $identificador]);
            $esNueva = ! $ficha->exists;
            $traeDatos = self::texto($contacto['nombre'] ?? null, 120) || self::telefono($contacto['telefono'] ?? null);
            if ($esNueva && ! $traeDatos) return null;

            self::aplicarContacto($ficha, $canal, $identificador, $contacto);
            if ($contactoUrl) $ficha->contacto_url = mb_substr($contactoUrl, 0, 255);

            return self::cerrar($ficha, $esNueva, reabrir: true);
        };

        try {
            return $guardar();
        } catch (UniqueConstraintViolationException) {
            return $guardar();
        }
    }

    /** Fechas, estado y enlace con el cliente oficial; guarda. */
    private static function cerrar(ClienteRed $ficha, bool $esNueva, bool $reabrir): ClienteRed
    {
        $ficha->primer_contacto_at ??= now();
        $ficha->ultimo_contacto_at = now();

        // Estado: quien ya compró o se había dado por perdido y vuelve a escribir es una
        // oportunidad nueva. Si alguien lo está trabajando ("contactado"), se respeta. La
        // cancelación de una cita no reabre nada.
        if ($esNueva) {
            $ficha->estado = 'nuevo';
        } elseif ($reabrir && in_array($ficha->estado, ['compro', 'perdido'], true)) {
            $ficha->estado = 'nuevo';
        }

        if (! $ficha->cliente_id && $ficha->telefono) {
            $ficha->cliente_id = self::clientePorTelefono($ficha->telefono);
        }

        $ficha->save();
        return $ficha;
    }

    /**
     * Copia a la ficha lo que trae `contacto`. Lo nuevo manda, salvo que venga vacío: un
     * aviso sin ciudad no borra la que dio antes.
     */
    private static function aplicarContacto(ClienteRed $ficha, string $canal, string $identificador, array $c, ?string $nombreRespaldo = null): void
    {
        $nombre = self::texto($c['nombre'] ?? null, 120);
        if ($nombre) {
            $ficha->nombre = $nombre;
        } elseif (! $ficha->nombre && $nombreRespaldo) {
            // Respaldo: el nombre del perfil de WhatsApp o de Instagram.
            $ficha->nombre = self::texto($nombreRespaldo, 120);
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
        foreach (['preferencias' => 5, 'productos_interes' => 6, 'categorias_interes' => 6] as $campo => $max) {
            $lista = self::lista($c[$campo] ?? null, $max);
            if ($lista) $ficha->{$campo} = $lista;
        }
        // Lo que busca, en palabras de Elena ("cama queen para la habitación principal,
        // madera clara, máximo $3 M"). Lo va actualizando ella a medida que aprende.
        $interes = self::texto($c['interes'] ?? null, 500);
        if ($interes) $ficha->interes = $interes;

        if (! empty($c['no_quiso_dar_datos'])) {
            $ficha->no_quiso_dar_datos = true;
        } elseif ($nombre || $telefono) {
            $ficha->no_quiso_dar_datos = false;
        }
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
