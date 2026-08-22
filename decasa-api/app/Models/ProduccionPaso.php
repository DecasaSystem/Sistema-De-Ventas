<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduccionPaso extends Model
{
    protected $table = 'produccion_pasos';

    /**
     * El paso que cierra el taller. Es un proceso más del catálogo; lo único
     * especial es que va siempre de último y que al completarse la producción
     * queda lista para entrega.
     */
    public const DESPACHO = 'despacho';

    public $timestamps = false;

    protected $fillable = [
        'produccion_id',
        'tipo_proceso',
        'orden',
        'estado',
        'iniciado_at',
        'completado_por',
        'completado_at',
        'trabajadores',
        'rechazos',
        'ultimo_rechazo',
        'rechazado_por_id',
        'rechazado_at',
    ];

    protected function casts(): array
    {
        return [
            'iniciado_at'    => 'datetime',
            'completado_at'  => 'datetime',
            'rechazado_at'   => 'datetime',
            'trabajadores'   => 'array',
        ];
    }

    public function produccion()
    {
        return $this->belongsTo(Produccion::class, 'produccion_id');
    }

    /** Quién autorizó que el paso siguiera al siguiente. */
    public function completadoPor()
    {
        return $this->belongsTo(Usuario::class, 'completado_por');
    }

    /** Quiénes lo hicieron, con sus horas y su calificación. */
    public function participantes()
    {
        return $this->hasMany(PasoTrabajador::class, 'paso_id');
    }

    /**
     * Los nombres de quienes participaron, vengan de donde vengan.
     *
     * Los pasos cerrados antes de que existieran los participantes sólo tienen
     * la lista de nombres escrita a mano. Mezclarlas acá evita que el detalle
     * de una orden vieja aparezca sin nadie.
     */
    public function nombresParticipantes(): array
    {
        $deVerdad = $this->relationLoaded('participantes')
            ? $this->participantes->map(fn ($p) => $p->usuario?->nombre)->filter()->all()
            : [];

        return $deVerdad ?: array_values(array_filter((array) $this->trabajadores));
    }

    /** Horas que tomó el paso: la suma de lo que reportó cada participante. */
    public function horasTotales(): ?float
    {
        if (! $this->relationLoaded('participantes')) return null;
        $con = $this->participantes->whereNotNull('horas');

        return $con->isEmpty() ? null : (float) $con->sum('horas');
    }

    /**
     * El nombre visible de un proceso.
     *
     * Ya no es una lista fija: los procesos los mantiene el taller desde
     * Produccion, asi que sale del catalogo.
     */
    public static function labelProceso(string $tipo): string
    {
        return TipoProceso::nombreDe($tipo);
    }
}
