<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoTela extends Model
{
    protected $table = 'catalogo_telas';

    protected $fillable = ['marca', 'tipo', 'color', 'referencia', 'textura', 'foto_url', 'activo', 'metros_disponibles', 'metros_reservados'];

    protected $casts = ['activo' => 'boolean'];
}
