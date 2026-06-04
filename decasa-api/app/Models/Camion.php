<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Camion extends Model
{
    protected $table = 'camiones';

    public $timestamps = false;

    protected $fillable = ['nombre', 'placa', 'conductor_id', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function conductor()
    {
        return $this->belongsTo(Usuario::class, 'conductor_id');
    }

    public function despachos()
    {
        return $this->hasMany(Despacho::class, 'camion_id');
    }
}
