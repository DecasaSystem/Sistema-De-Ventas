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
        'perfil_produccion_id',
        'independiente',
        'notif_asignar_fecha',
        'notif_stock',
        'acceso_redes',
        'acceso_comisiones',
        'recarga_telas',
        'acceso_surtir',
        'acceso_costos',
        'acceso_proveedores',
        'acceso_despacho',
        'acceso_metricas',
        'acceso_produccion',
        'acceso_reserva',
        've_todas_ordenes',
        'tienda_default_id',
        'activo',
        'firma_url',
        'created_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password'            => 'hashed',
            'activo'              => 'boolean',
            'facturacion'         => 'boolean',
            'es_tapicero'         => 'boolean',
            'independiente'       => 'boolean',
            'notif_asignar_fecha' => 'boolean',
            'notif_stock'         => 'boolean',
            'acceso_redes'        => 'boolean',
            'acceso_comisiones'   => 'boolean',
            'recarga_telas'       => 'boolean',
            'acceso_surtir'       => 'boolean',
            'acceso_costos'       => 'boolean',
            'acceso_proveedores'  => 'boolean',
            'acceso_despacho'     => 'boolean',
            'acceso_metricas'     => 'boolean',
            'acceso_produccion'   => 'boolean',
            'acceso_reserva'      => 'boolean',
            've_todas_ordenes'    => 'boolean',
        ];
    }

    public function tiendaDefault()
    {
        return $this->belongsTo(Tienda::class, 'tienda_default_id');
    }

    public function perfilProduccion()
    {
        return $this->belongsTo(PerfilProduccion::class, 'perfil_produccion_id');
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

    public function comisiones()
    {
        return $this->hasMany(\App\Models\Comision::class, 'vendedor_id');
    }
}
