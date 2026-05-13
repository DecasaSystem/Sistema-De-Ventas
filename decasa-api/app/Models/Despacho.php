<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Despacho extends Model
{
    protected $table = 'despachos';

    protected $fillable = [
        'conductor_id',
        'supervisor_id',
        'estado',
        'notas',
    ];

    public function conductor()
    {
        return $this->belongsTo(Usuario::class, 'conductor_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(Usuario::class, 'supervisor_id');
    }

    public function items()
    {
        return $this->hasMany(DespachoItem::class, 'despacho_id');
    }

    public function ordenes()
    {
        return $this->hasManyThrough(
            Orden::class,
            DespachoItem::class,
            'despacho_id',
            'id',
            'id',
            'orden_id'
        );
    }
}
