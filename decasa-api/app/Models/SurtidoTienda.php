<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurtidoTienda extends Model
{
    protected $table = 'surtido_tiendas';

    public $timestamps = false;

    protected $fillable = [
        'surtido_id',
        'tienda_id',
        'vendedor_validador_id',
        'estado',
        'notas_vendedor',
        'respondido_at',
    ];

    protected $casts = [
        'respondido_at' => 'datetime',
    ];

    public function surtido()
    {
        return $this->belongsTo(Surtido::class, 'surtido_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }

    public function vendedorValidador()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_validador_id');
    }

    public function items()
    {
        return $this->hasMany(SurtidoItem::class, 'surtido_tienda_id');
    }
}
