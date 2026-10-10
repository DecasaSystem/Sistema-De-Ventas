<?php

namespace App\Services\Finanzas;

use App\Models\CierreFinanciero;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * El cierre de mes: cuando un mes ya se revisó (con el contador, por ejemplo),
 * se congela su estado de resultados. Lo que llegue después —un gasto que se
 * registró tarde, una comisión que se recalculó— no lo mueve, y no se pueden
 * registrar ni anular gastos de ese mes. Para cambiarlo se reabre, con motivo
 * (queda quién y por qué).
 *
 * Mismo principio que `nomina_pagos`: lo cerrado guarda su copia.
 */
class Cierres
{
    private static ?array $cerrados = null;

    public static function olvidarCache(): void
    {
        self::$cerrados = null;
    }

    /** @return array<string, CierreFinanciero> [mes => cierre] solo los cerrados */
    public static function cerrados(): array
    {
        if (self::$cerrados !== null) return self::$cerrados;
        if (! Schema::hasTable('cierres_financieros')) return self::$cerrados = [];

        return self::$cerrados = CierreFinanciero::where('estado', 'cerrado')->get()->keyBy('mes')->all();
    }

    public static function estaCerrado(string $mes): bool
    {
        return isset(self::cerrados()[$mes]);
    }

    /** Frena un cambio de plata en un mes cerrado. */
    public static function exigirAbierto(string $mes): void
    {
        if (self::estaCerrado($mes)) {
            throw ValidationException::withMessages([
                'mes' => [Periodo::nombre($mes) . ' está cerrado. Para cambiarlo, reábrelo en Finanzas → Resultados.'],
            ]);
        }
    }

    /** Cierra un mes que ya terminó, guardando su estado de resultados tal como está hoy. */
    public static function cerrar(string $mes, int $usuarioId): CierreFinanciero
    {
        if ($mes >= Periodo::mesActual()) {
            throw ValidationException::withMessages(['mes' => ['Solo se cierra un mes que ya terminó.']]);
        }
        if (self::estaCerrado($mes)) {
            throw ValidationException::withMessages(['mes' => ['Ese mes ya está cerrado.']]);
        }

        self::olvidarCache();
        $fila = EstadoResultados::mes($mes);

        $cierre = CierreFinanciero::updateOrCreate(['mes' => $mes], [
            'snapshot' => $fila, 'estado' => 'cerrado', 'cerrado_por' => $usuarioId, 'cerrado_at' => now(),
            'reabierto_por' => null, 'reabierto_at' => null, 'motivo_reapertura' => null,
        ]);
        self::olvidarCache();

        return $cierre;
    }

    public static function reabrir(string $mes, string $motivo, int $usuarioId): void
    {
        $c = CierreFinanciero::where('mes', $mes)->where('estado', 'cerrado')->first();
        if (! $c) {
            throw ValidationException::withMessages(['mes' => ['Ese mes no está cerrado.']]);
        }
        $c->update(['estado' => 'reabierto', 'reabierto_por' => $usuarioId, 'reabierto_at' => now(), 'motivo_reapertura' => $motivo]);
        self::olvidarCache();
    }

    /** Cambia las filas calculadas de los meses cerrados por su copia congelada. */
    public static function aplicar(array $filas): array
    {
        $cerrados = self::cerrados();
        if (! $cerrados) return $filas;

        return array_map(function ($f) use ($cerrados) {
            if (! isset($cerrados[$f['mes']])) return $f;
            $c = $cerrados[$f['mes']];

            // array_merge y no `+`: la copia ya trae 'cerrado' => false.
            return array_merge($c->snapshot, [
                'cerrado' => true,
                'cerrado_at' => $c->cerrado_at?->toIso8601String(),
                'cerrado_por' => $c->cerradoPor?->nombre,
            ]);
        }, $filas);
    }
}
