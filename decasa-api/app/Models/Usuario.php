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

    /**
     * `rol` (el string) sigue siendo una columna real, sincronizada sola con
     * la `clave` del rol asignado. Así, los ~40 sitios del código que ya
     * comparan `$usuario->rol === 'ebanista'` —o lo hacen en una consulta
     * SQL, como `Usuario::where('rol', 'ebanista')`— siguen funcionando
     * exactamente igual, sin enterarse de que el rol ahora es configurable.
     */
    protected static function booted(): void
    {
        static::saving(function (Usuario $usuario) {
            if ($usuario->isDirty('rol_id') && $usuario->rol_id) {
                $usuario->rol = Rol::find($usuario->rol_id)?->clave ?? $usuario->rol;
            }
        });
    }

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'rol',
        'rol_id',
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

    public function rolAsignado()
    {
        return $this->belongsTo(Rol::class, 'rol_id');
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
