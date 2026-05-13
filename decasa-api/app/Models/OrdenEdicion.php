<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenEdicion extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $table = 'orden_ediciones';

    protected $fillable = ['orden_id', 'usuario_id', 'cambios'];

    protected $casts = [
        'cambios'    => 'array',
        'created_at' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }
}
