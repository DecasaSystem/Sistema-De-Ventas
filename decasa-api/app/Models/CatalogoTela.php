<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoTela extends Model
{
    protected $table = 'catalogo_telas';

    protected $fillable = ['marca', 'tipo', 'color', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
