<?php

namespace App\Services;

use App\Events\NuevaNotificacion;
use App\Models\Notificacion;
use App\Models\Usuario;
use App\Services\PushService;

class NotificacionService
{
    public static function crear(
        string $tipo,
        string $titulo,
        string $mensaje,
        array  $datos = [],
        ?int   $usuarioId = null,
        bool   $urgente = false,
    ): Notificacion {
        if ($usuarioId === null) {
            $supervisores = Usuario::where('rol', 'supervisor')
                ->where('activo', true)
                ->get();

            $last = null;
            foreach ($supervisores as $sup) {
                $last = self::crearParaUsuario($tipo, $titulo, $mensaje, $datos, $sup->id, $urgente);
            }
            return $last ?? self::crearParaUsuario($tipo, $titulo, $mensaje, $datos, null, $urgente);
        }

        return self::crearParaUsuario($tipo, $titulo, $mensaje, $datos, $usuarioId, $urgente);
    }

    private static function crearParaUsuario(
        string $tipo,
        string $titulo,
        string $mensaje,
        array  $datos,
        ?int   $usuarioId,
        bool   $urgente = false,
    ): Notificacion {
        $n = Notificacion::create([
            'usuario_id' => $usuarioId,
            'tipo'       => $tipo,
            'titulo'     => $titulo,
            'mensaje'    => $mensaje,
            'urgente'    => $urgente,
            'datos'      => $datos ?: null,
        ]);

        try {
            event(new NuevaNotificacion($n));
        } catch (\Throwable) {
            // Reverb offline — notificación guardada en BD, broadcast ignorado
        }

        if ($usuarioId) {
            try {
                PushService::enviarAUsuario($usuarioId, $titulo, $mensaje, $datos, $tipo);
            } catch (\Throwable $e) {
                \Log::warning("Push notification fallida para usuario {$usuarioId}", [
                    'tipo'  => $tipo,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $n;
    }
}
