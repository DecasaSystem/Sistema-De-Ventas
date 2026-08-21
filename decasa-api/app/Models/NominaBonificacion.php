<?php

namespace App\Models;

use App\Services\CicloNomina;
use Illuminate\Database\Eloquent\Model;

/**
 * Un esquema de bonificación por producción, con nombre ("Bonos del
 * mínimo", "Bono para gente cool"): un tope mínimo que hay que producir
 * para recibir algo, y de ahí para arriba una escalera de metas que dice
 * cuánto se paga en cada tramo.
 *
 * Se pueden tener varios y asignarle a cada trabajador el que le toque,
 * porque no todos tienen las mismas condiciones.
 */
class NominaBonificacion extends Model
{
    protected $table = 'nomina_bonificaciones';

    protected $fillable = ['nombre', 'periodo', 'tope', 'tope_activo', 'activo'];

    protected function casts(): array
    {
        return [
            'tope'        => 'decimal:2',
            'tope_activo' => 'boolean',
            'activo'      => 'boolean',
        ];
    }

    /**
     * Sobre qué ventana se mide el tope: 'ciclo' es el ciclo de pago de cada
     * trabajador (le sale distinto al quincenal que al mensual), y cualquier
     * otro valor es una ventana fija igual para todos.
     */
    public function labelPeriodo(): string
    {
        return $this->periodo === 'ciclo'
            ? 'Por ciclo de pago'
            : CicloNomina::label($this->periodo);
    }

    public function metas()
    {
        return $this->hasMany(NominaBonificacionMeta::class)->orderBy('desde');
    }

    public function trabajadores()
    {
        return $this->hasMany(Usuario::class, 'nomina_bonificacion_id');
    }

    /**
     * Cuánto le corresponde a alguien que produjo $produccion, y por qué.
     *
     * Devuelve también el porqué (si alcanzó el tope, cuánto le falta, en
     * qué tramo cayó) para poder mostrarlo en pantalla sin recalcularlo
     * del lado del front.
     *
     * Requiere `metas` cargada.
     */
    public function evaluar(float $produccion): array
    {
        $base = [
            'bonificacion_id'     => $this->id,
            'bonificacion_nombre' => $this->nombre,
            'aplica'              => true,
            'tope'                => $this->tope_activo ? (float) $this->tope : null,
            'alcanzo_tope'        => true,
            'falta_para_tope'     => 0.0,
            'meta'                => null,
            'monto'               => 0.0,
        ];

        if (! $this->activo) {
            return array_merge($base, ['aplica' => false, 'alcanzo_tope' => false]);
        }

        if ($this->tope_activo && $produccion < (float) $this->tope) {
            return array_merge($base, [
                'alcanzo_tope'    => false,
                'falta_para_tope' => round((float) $this->tope - $produccion),
            ]);
        }

        $meta = $this->metaPara($produccion);

        return array_merge($base, [
            'meta'  => $meta?->etiqueta(),
            'monto' => $meta ? (float) $meta->monto : 0.0,
        ]);
    }

    /**
     * El tramo en el que cae una producción: el de `desde` más alto que no
     * la pase, siempre que su techo la cubra. Se recorre de mayor a menor
     * para que quien produzca de más caiga en el tramo más alto y no en el
     * primero que le sirva.
     */
    public function metaPara(float $produccion): ?NominaBonificacionMeta
    {
        return $this->metas
            ->filter(fn (NominaBonificacionMeta $m) => $m->activo)
            // Por (float) y no por la columna: el cast decimal:2 devuelve
            // string, y ordenar strings pondría "900000" por encima de
            // "2800000" — el tramo más alto dejaría de ganar.
            ->sortByDesc(fn (NominaBonificacionMeta $m) => (float) $m->desde)
            ->first(fn (NominaBonificacionMeta $m) => $produccion >= (float) $m->desde
                && ($m->hasta === null || $produccion <= (float) $m->hasta));
    }

    /** Lo que se guarda en el pago para dejar dicho de dónde salió el bono. */
    public static function sinEsquema(): array
    {
        return [
            'bonificacion_id'     => null,
            'bonificacion_nombre' => null,
            'aplica'              => false,
            'tope'                => null,
            'alcanzo_tope'        => false,
            'falta_para_tope'     => 0.0,
            'meta'                => null,
            'monto'               => 0.0,
        ];
    }
}
