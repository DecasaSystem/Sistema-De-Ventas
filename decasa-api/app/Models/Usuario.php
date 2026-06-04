<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'usuarios';

    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'rol',
        'facturacion',
        'es_tapicero',
        'notif_asignar_fecha',
        'acceso_redes',
        'tienda_default_id',
        'activo',
        'firma_url',
        'created_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password'    => 'hashed',
            'activo'             => 'boolean',
            'facturacion'        => 'boolean',
            'es_tapicero'        => 'boolean',
            'notif_asignar_fecha' => 'boolean',
            'acceso_redes'       => 'boolean',
        ];
    }

    public function tiendaDefault()
    {
        return $this->belongsTo(Tienda::class, 'tienda_default_id');
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'vendedor_id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'vendedor_id');
    }

    public function movimientos()
    {
        return $this->hasMany(InventarioMovimiento::class, 'usuario_id');
    }
}
