<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FichaTecnica extends Model
{
    protected $table = 'fichas_tecnicas';

    protected $fillable = [
        'nombre',
        'categoria',
        'costo_materiales',
        'costo_mano_obra',
        'costo_total',
        'ruta_excel',
    ];

    protected function casts(): array
    {
        return [
            'costo_materiales' => 'decimal:2',
            'costo_mano_obra'  => 'decimal:2',
            'costo_total'      => 'decimal:2',
        ];
    }

    public function items()
    {
        return $this->hasMany(FichaTecnicaItem::class)->orderBy('orden');
    }
}
