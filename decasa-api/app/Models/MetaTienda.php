<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaTienda extends Model
{
    protected $table = 'metas_tienda';

    protected $fillable = ['tienda_id', 'mes', 'meta', 'divisor_asesores'];

    protected function casts(): array
    {
        return ['meta' => 'decimal:2'];
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }
}
