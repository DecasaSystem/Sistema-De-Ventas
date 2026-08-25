<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cosa que la empresa le entregó a un trabajador y por la que responde.
 *
 * `cantidad` es lo que tiene a cargo HOY: cuando en una revista se da algo
 * por perdido, se le resta. Así la lista siempre dice lo que de verdad
 * debería poder mostrar si se le pide, y no lo que se le entregó alguna vez.
 */
class Encargo extends Model
{
    protected $table = 'encargos';

    protected $fillable = [
        'usuario_id', 'nombre', 'cantidad', 'cantidad_danada', 'serial',
        'valor_unitario', 'fecha_entrega', 'foto_url', 'notas', 'estado',
        'cerrado_en', 'entregado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_entrega'  => 'date',
            'cerrado_en'     => 'date',
            'valor_unitario' => 'decimal:2',
        ];
    }

    public function trabajador()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function entregadoPor()
    {
        return $this->belongsTo(Usuario::class, 'entregado_por_id');
    }

    public function itemsRevision()
    {
        return $this->hasMany(EncargoRevisionItem::class, 'encargo_id');
    }

    /** ¿Todavía lo tiene? Lo devuelto, perdido o dado de baja ya no cuenta. */
    public function estaACargo(): bool
    {
        return $this->estado === 'a_cargo';
    }

    /** Lo que costaría reponer lo que tiene a cargo. */
    public function valorTotal(): float
    {
        return $this->estaACargo()
            ? (float) ($this->valor_unitario ?? 0) * $this->cantidad
            : 0.0;
    }

    public function scopeACargo($query)
    {
        return $query->where('estado', 'a_cargo');
    }
}
