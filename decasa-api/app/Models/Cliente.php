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
        'tienda_id',
        'categorias_interes',
        'notas_interes',
        // Datos que pide la DIAN para la factura electrónica (Facturación electrónica).
        'tipo_documento',
        'dv',
        'tipo_persona',
        'regimen',
        'ciudad',
        'departamento',
        'alegra_contacto_id',
    ];

    protected $hidden = [];

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
