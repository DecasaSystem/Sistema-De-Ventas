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
        'acceso_nomina',
        'acceso_compras',
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
            'acceso_nomina'       => 'boolean',
            'acceso_compras'      => 'boolean',
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

    /** Procesos que se le asignaron a esta persona en concreto. */
    public function procesosAsignados()
    {
        return $this->belongsToMany(TipoProceso::class, 'proceso_trabajadores', 'usuario_id', 'tipo_proceso_id');
    }

    /**
     * Qué procesos del taller puede trabajar: los de su especialidad MÁS los
     * que se le asignaron a dedo. Es la única fuente de verdad de esto — la
     * usan por igual "Mis pasos", el permiso para marcar un paso listo y a
     * quién se le notifica, para que los tres no puedan discrepar.
     *
     * @return array<int, string> claves de tipos_proceso
     */
    public function procesosQuePuedeTrabajar(): array
    {
        $porEspecialidad = ($clave = $this->perfilProduccion?->clave)
            ? TipoProceso::clavesDePerfil($clave)
            : [];

        // Solo procesos activos: uno apagado no debe seguir apareciéndole a
        // nadie, igual que ya pasa con los que llegan por especialidad.
        $asignados = $this->procesosAsignados()->where('activo', true)->pluck('clave')->all();

        return array_values(array_unique(array_merge($porEspecialidad, $asignados)));
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
