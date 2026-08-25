<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La revista de un día: se contó lo que el trabajador tiene y se dejó
 * asentado qué estaba bien, qué dañado y qué faltaba.
 *
 * No se edita ni se vuelve a abrir. Si algo quedó mal contado se hace otra
 * revista: la fecha en que se contó importa tanto como lo que se contó, y
 * corregir la vieja borraría el rastro de cuándo se notó la pérdida.
 */
class EncargoRevision extends Model
{
    protected $table = 'encargo_revisiones';

    protected $fillable = [
        'usuario_id', 'revisado_por_id', 'fecha', 'notas',
        'descuento_total', 'nomina_ajuste_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha'           => 'date',
            'descuento_total' => 'decimal:2',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function revisadoPor()
    {
        return $this->belongsTo(Usuario::class, 'revisado_por_id');
    }

    public function items()
    {
        return $this->hasMany(EncargoRevisionItem::class, 'revision_id');
    }

    /** El descuento que se le mandó a Nómina, si se cobró. */
    public function ajuste()
    {
        return $this->belongsTo(NominaAjuste::class, 'nomina_ajuste_id');
    }
}
