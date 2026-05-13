<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'cedula',
        'telefono',
        'email',
        'direccion',
        'canal_pref',
        'tipo',
        'categorias_interes',
        'notas_interes',
    ];

    protected function casts(): array
    {
        return [
            'categorias_interes' => 'array',
        ];
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'cliente_id');
    }
}
