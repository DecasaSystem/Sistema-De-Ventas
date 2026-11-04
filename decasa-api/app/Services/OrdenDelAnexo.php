<?php

namespace App\Services;

use App\Events\AnexoFirmado;
use App\Http\Controllers\OrdenController;
use App\Models\AnexoGarantia;
use App\Models\Cliente;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Crear la orden que viaja con el enlace de firma (2026-10-28).
 *
 * Al mandar el enlace desde Nueva orden, la pantalla guarda en el anexo la
 * orden completa, tal como la mandaría a POST /ordenes (`orden_pendiente`).
 * Cuando el cliente firma, la orden se crea:
 *  - sola, si el vendedor lo escogió al mandar (job CrearOrdenFirmada), o
 *  - cuando el vendedor le da "Crear la orden" (POST /anexos/{id}/crear-orden).
 *
 * Por qué así y no "la pantalla la crea al ver la firma": el cliente puede
 * firmar a los tres días, con el teléfono del vendedor apagado y el formulario
 * de Nueva orden ya vencido (48 h). La orden tiene que poder crearse sin él.
 *
 * Una sola puerta: se crea por OrdenController::store, a nombre del vendedor
 * que mandó el enlace, con el `anexo_id` (la firma del cliente) y la misma
 * `clave_envio` del formulario. Así valen todas las reglas de siempre (stock,
 * descuentos, anticipo, que la orden sea la que el cliente vio) y si el
 * vendedor también le da "Crear orden" en su pantalla, no salen dos.
 */
class OrdenDelAnexo
{
    /**
     * @return array{ok: bool, orden_id?: int, referencia?: string, mensaje?: string, ocupado?: bool}
     */
    public static function crear(AnexoGarantia $anexo): array
    {
        // El job y el vendedor (o dos pestañas) a la vez: uno la crea, el otro espera.
        $candado = Cache::lock("anexo-orden:{$anexo->id}", 120);
        if (! $candado->get()) {
            return ['ok' => false, 'ocupado' => true, 'mensaje' => 'La orden ya se está creando. Espera unos segundos.'];
        }

        try {
            $anexo->refresh();
            if ($anexo->orden_id) {
                $orden = Orden::find($anexo->orden_id);
                return ['ok' => true, 'orden_id' => (int) $anexo->orden_id, 'referencia' => $orden?->referencia];
            }
            if (! $anexo->estaFirmado()) {
                return ['ok' => false, 'mensaje' => 'El cliente todavía no ha firmado.'];
            }
            if ($anexo->orden_auto_estado === 'descartada') {
                return ['ok' => false, 'mensaje' => 'Este pedido se quitó o se reemplazó por otro enlace: ya no se crea.'];
            }
            if (empty($anexo->orden_pendiente)) {
                return ['ok' => false, 'mensaje' => 'Este enlace no trae la orden guardada: créala desde Nueva orden.'];
            }
            $vendedor = Usuario::find($anexo->vendedor_id);
            if (! $vendedor || ! $vendedor->activo) {
                return self::fallo($anexo, 'El asesor que mandó el enlace ya no está activo. Un supervisor tiene que crear la orden.');
            }

            [$estado, $cuerpo] = self::comoVendedor($vendedor, self::payload($anexo));

            $ordenId = $cuerpo['id'] ?? $cuerpo['orden_id'] ?? null;
            // 201: se creó. 200 con `ya_existia`: el vendedor ya la había
            // creado desde su pantalla con la misma clave; es esa misma. 409:
            // la misma venta del mismo vendedor hace segundos (store la frena).
            if (($estado < 300 || $estado === 409) && $ordenId) {
                $orden = Orden::find($ordenId);
                // Creada desde la pantalla sin pegarle el anexo (lo hizo antes
                // de que el cliente firmara): la firma es de esa orden.
                if ($orden && (int) $orden->cliente_id === (int) $anexo->cliente_id) {
                    $anexo->forceFill(['orden_id' => $orden->id])->save();
                    if (! $orden->firma_url && $anexo->firma_url) $orden->update(['firma_url' => $anexo->firma_url]);
                }
                $anexo->forceFill(['orden_auto_estado' => 'creada', 'orden_auto_error' => null])->save();

                return ['ok' => true, 'orden_id' => (int) $ordenId, 'referencia' => $orden?->referencia];
            }

            $mensaje = $cuerpo['message'] ?? "No se pudo crear la orden (error {$estado}).";
            if (! empty($cuerpo['errors']) && is_array($cuerpo['errors'])) {
                $primero = collect($cuerpo['errors'])->flatten()->first();
                if ($primero && ! str_contains($mensaje, $primero)) $mensaje .= " ({$primero})";
            }

            return self::fallo($anexo, $mensaje);
        } catch (\Throwable $e) {
            report($e);
            return self::fallo($anexo, 'No se pudo crear la orden por un error del servidor. Inténtalo desde Nueva orden.');
        } finally {
            $candado->release();
        }
    }

    /**
     * Lo de crear(), y además avisarle al vendedor cómo le fue: en su teléfono
     * y en la pantalla que esté esperando. Lo usa el job (nadie está mirando).
     */
    public static function crearYAvisar(AnexoGarantia $anexo): array
    {
        $r = self::crear($anexo);
        if (! empty($r['ocupado'])) return $r;

        $cliente = Cliente::find($anexo->cliente_id)?->nombre ?? 'El cliente';
        try {
            if ($anexo->vendedor_id) {
                $r['ok']
                    ? NotificacionService::crear(
                        'anexo_firmado',
                        'El cliente firmó y se creó la orden',
                        "{$cliente} revisó su pedido y firmó. Se creó la orden" . (! empty($r['referencia']) ? " {$r['referencia']}" : '') . '.',
                        ['anexo_id' => $anexo->id, 'orden_id' => $r['orden_id']],
                        (int) $anexo->vendedor_id,
                    )
                    : NotificacionService::crear(
                        'anexo_firmado',
                        'El cliente firmó: falta crear la orden',
                        "{$cliente} firmó, pero la orden no se pudo crear sola: {$r['mensaje']} Entra a Nueva orden para revisarla y crearla.",
                        ['anexo_id' => $anexo->id],
                        (int) $anexo->vendedor_id,
                    );
            }
        } catch (\Throwable $e) {
            report($e);
        }
        // La pantalla de Nueva orden que lo espera pregunta otra vez y ve la orden.
        try { event(new AnexoFirmado($anexo->id)); } catch (\Throwable) {}

        return $r;
    }

    /**
     * La orden guardada, lista para POST /ordenes. Lo que no puede venir de la
     * pantalla lo pone el anexo: el cliente que firmó y su firma. Nunca como
     * borrador: se crea de verdad.
     */
    private static function payload(AnexoGarantia $anexo): array
    {
        $p = $anexo->orden_pendiente;
        unset($p['guardar_borrador'], $p['firma_url'], $p['anexo_id'], $p['anexo_foto_url']);
        $p['cliente_id'] = $anexo->cliente_id;
        $p['anexo_id']   = $anexo->id;

        return $p;
    }

    /**
     * Llama a OrdenController::store como si la pidiera el vendedor desde su
     * pantalla (sin pasar por HTTP). Devuelve [status, cuerpo].
     */
    private static function comoVendedor(Usuario $vendedor, array $payload): array
    {
        $req = Request::create('/api/ordenes', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');
        $req->setUserResolver(fn () => $vendedor);

        $antes = app()->bound('request') ? app('request') : null;
        app()->instance('request', $req);
        try {
            $respuesta = app(OrdenController::class)->store($req);

            return [$respuesta->getStatusCode(), json_decode($respuesta->getContent(), true) ?: []];
        } catch (ValidationException $e) {
            return [422, ['message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()]];
        } catch (HttpExceptionInterface $e) {
            return [$e->getStatusCode(), ['message' => $e->getMessage() ?: 'No se pudo crear la orden.']];
        } finally {
            if ($antes) app()->instance('request', $antes);
        }
    }

    private static function fallo(AnexoGarantia $anexo, string $mensaje): array
    {
        $anexo->forceFill(['orden_auto_estado' => 'fallo', 'orden_auto_error' => mb_substr($mensaje, 0, 1000)])->save();

        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
