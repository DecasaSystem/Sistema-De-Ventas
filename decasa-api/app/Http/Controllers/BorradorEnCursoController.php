<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El respaldo de Nueva orden guardado en la cuenta, para seguirla en otro aparato.
 *
 * El teléfono sigue guardando su copia (IndexedDB, con las fotos tal cual);
 * esto es la copia que ven los demás aparatos de la misma persona. Las fotos
 * llegan aquí ya subidas a Cloudinary (carpeta `borradores`), como enlaces.
 *
 * Cada guardado dice sobre qué `revision` trabajó. Si no es la última, otro
 * aparato guardó (o terminó la orden) mientras tanto: se contesta 409 con lo
 * que hay, y la pantalla le pregunta a la persona con cuál se queda. Así un
 * celular que quedó abierto con la versión de hace una hora no pisa lo que se
 * hizo después en el computador, ni revive una orden que ya se creó.
 *
 * Nada de esto es plata ni toca órdenes: es el formulario a medio llenar.
 */
class BorradorEnCursoController extends Controller
{
    /** Lo mismo que el respaldo del teléfono: después de dos días ya no se ofrece. */
    private const CADUCIDAD_HORAS = 48;

    /** Un formulario pesa pocos KB; esto deja de sobra y frena lo que no es un formulario. */
    private const MAX_BYTES = 1_000_000;

    private const CLAVE = '/^[A-Za-z0-9:_\-]{1,150}$/';

    /** GET /borradores-en-curso/{clave} */
    public function show(Request $request, string $clave)
    {
        $this->validarClave($clave);
        $fila = $this->fila($request, $clave);

        return response()->json($this->respuesta($fila, conDatos: true));
    }

    /**
     * PUT /borradores-en-curso/{clave}
     *
     * `revision_base` null = el aparato no alcanzó a preguntar qué había (sin
     * señal al abrir): vale solo si no hay otra copia viva.
     */
    public function guardar(Request $request, string $clave)
    {
        $this->validarClave($clave);
        $data = $request->validate([
            'revision_base' => 'nullable|integer|min:0',
            'datos'         => 'required|array',
            'resumen'       => 'nullable|array',
            'resumen.titulo'  => 'nullable|string|max:200',
            'resumen.detalle' => 'nullable|string|max:200',
            'aparato_id'    => 'nullable|string|max:64',
            'aparato'       => 'nullable|string|max:40',
            'forzar'        => 'nullable|boolean',
        ]);

        $datos = json_encode($data['datos'], JSON_UNESCAPED_UNICODE);
        if ($datos === false || strlen($datos) > self::MAX_BYTES) {
            return response()->json(['message' => 'El borrador es demasiado grande para guardarlo en la cuenta.'], 413);
        }

        $usuarioId = $request->user()->id;

        return DB::transaction(function () use ($data, $datos, $clave, $usuarioId) {
            $fila = DB::table('borradores_en_curso')
                ->where('usuario_id', $usuarioId)->where('clave', $clave)
                ->lockForUpdate()->first();

            if ($fila && empty($data['forzar'])) {
                $base = $data['revision_base'] ?? null;
                $viva = $fila->eliminado_at === null && ! $this->vencida($fila);
                $choca = $base === null
                    ? $viva                                // no sabía qué había y hay otra copia viva
                    : (int) $base !== (int) $fila->revision; // alguien guardó o terminó después
                if ($choca) {
                    return response()->json(
                        ['message' => 'Esta orden se siguió en otro aparato.'] + $this->respuesta($fila, conDatos: true),
                        409
                    );
                }
            }

            $ahora = now();
            $valores = [
                'revision'     => ((int) ($fila->revision ?? 0)) + 1,
                'datos'        => $datos,
                'resumen'      => isset($data['resumen']) ? json_encode($data['resumen'], JSON_UNESCAPED_UNICODE) : null,
                'aparato_id'   => $data['aparato_id'] ?? null,
                'aparato'      => $data['aparato'] ?? null,
                'guardado_at'  => $ahora,
                'eliminado_at' => null,
                'updated_at'   => $ahora,
            ];

            if ($fila) {
                DB::table('borradores_en_curso')->where('id', $fila->id)->update($valores);
            } else {
                DB::table('borradores_en_curso')->insert($valores + [
                    'usuario_id' => $usuarioId,
                    'clave'      => $clave,
                    'created_at' => $ahora,
                ]);
            }

            return response()->json([
                'revision'    => $valores['revision'],
                'guardado_at' => $ahora->toIso8601String(),
            ]);
        });
    }

    /**
     * DELETE /borradores-en-curso/{clave}
     *
     * La orden se creó, se guardó como borrador o se empezó de cero. Siempre
     * vale (no mira la revisión): terminar la orden manda sobre cualquier copia.
     * La fila queda marcada para que los otros aparatos no la vuelvan a ofrecer.
     */
    public function borrar(Request $request, string $clave)
    {
        $this->validarClave($clave);
        $usuarioId = $request->user()->id;

        return DB::transaction(function () use ($clave, $usuarioId) {
            $fila = DB::table('borradores_en_curso')
                ->where('usuario_id', $usuarioId)->where('clave', $clave)
                ->lockForUpdate()->first();
            if (! $fila) {
                return response()->json(['revision' => 0]);
            }
            if ($fila->eliminado_at !== null) {
                return response()->json(['revision' => (int) $fila->revision]);
            }

            $revision = (int) $fila->revision + 1;
            DB::table('borradores_en_curso')->where('id', $fila->id)->update([
                'revision'     => $revision,
                'datos'        => null,
                'resumen'      => null,
                'eliminado_at' => now(),
                'updated_at'   => now(),
            ]);

            return response()->json(['revision' => $revision]);
        });
    }

    private function validarClave(string $clave): void
    {
        abort_unless(preg_match(self::CLAVE, $clave), 422, 'Clave de borrador inválida.');
    }

    private function fila(Request $request, string $clave): ?object
    {
        return DB::table('borradores_en_curso')
            ->where('usuario_id', $request->user()->id)->where('clave', $clave)
            ->first();
    }

    private function vencida(object $fila): bool
    {
        return $fila->guardado_at === null
            || now()->diffInHours($fila->guardado_at, absolute: true) >= self::CADUCIDAD_HORAS;
    }

    /**
     * `existe` = hay una copia para ofrecer. Una vencida o borrada no trae datos,
     * pero sí su revisión: el aparato la necesita para guardar encima.
     */
    private function respuesta(?object $fila, bool $conDatos): array
    {
        if (! $fila) {
            return ['existe' => false, 'revision' => 0, 'eliminado' => false];
        }

        $eliminado = $fila->eliminado_at !== null;
        $existe    = ! $eliminado && ! $this->vencida($fila) && $fila->datos !== null;

        return [
            'existe'       => $existe,
            'revision'     => (int) $fila->revision,
            'eliminado'    => $eliminado,
            'eliminado_at' => $eliminado ? \Illuminate\Support\Carbon::parse($fila->eliminado_at)->toIso8601String() : null,
            'guardado_at'  => $fila->guardado_at ? \Illuminate\Support\Carbon::parse($fila->guardado_at)->toIso8601String() : null,
            'aparato_id'   => $fila->aparato_id,
            'aparato'      => $fila->aparato,
            'resumen'      => $existe && $fila->resumen ? json_decode($fila->resumen, true) : null,
            'datos'        => $existe && $conDatos ? json_decode($fila->datos, true) : null,
        ];
    }
}
