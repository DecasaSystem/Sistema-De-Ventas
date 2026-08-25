<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo que se encontró de una cosa concreta en una revista.
 *
 * Las tres cantidades suman lo que la persona tenía a cargo ese día: se
 * cuenta pieza por pieza. De dos taladros puede salir uno bien y uno dañado,
 * y eso no es lo mismo que "los taladros están regular".
 */
class EncargoRevisionItem extends Model
{
    protected $table = 'encargo_revision_items';

    protected $fillable = [
        'revision_id', 'encargo_id', 'cantidad_ok', 'cantidad_danada',
        'cantidad_perdida', 'descuento', 'notas',
    ];

    protected function casts(): array
    {
        return ['descuento' => 'decimal:2'];
    }

    public function revision()
    {
        return $this->belongsTo(EncargoRevision::class, 'revision_id');
    }

    public function encargo()
    {
        return $this->belongsTo(Encargo::class, 'encargo_id');
    }
}
