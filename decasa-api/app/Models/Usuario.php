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
        // Trabajador de fábrica: sin login, sin tienda, sin permisos.
        'cedula',
        'no_usa_programa',
        'apto_comisiones',
        'nomina_sueldo_id',
        'nomina_bonificacion_id',
        'periodicidad',
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
            'no_usa_programa'     => 'boolean',
            'apto_comisiones'     => 'boolean',
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

    // ── Nómina ───────────────────────────────────────────────────────────────
    // Lo que antes vivía en el modelo Empleado, que era una segunda tabla de
    // personas paralela a esta. Un trabajador se crea una sola vez acá y
    // aparece solo en Nómina.

    public function sueldo()
    {
        return $this->belongsTo(NominaSueldo::class, 'nomina_sueldo_id');
    }

    /** El esquema de bonificación asignado. Null = no aplica para bono. */
    public function bonificacion()
    {
        return $this->belongsTo(NominaBonificacion::class, 'nomina_bonificacion_id');
    }

    public function pagosNomina()
    {
        return $this->hasMany(NominaPago::class, 'usuario_id');
    }

    public function ausencias()
    {
        return $this->hasMany(NominaAusencia::class, 'usuario_id');
    }

    public function ajustes()
    {
        return $this->hasMany(NominaAjuste::class, 'usuario_id');
    }

    public function producciones()
    {
        return $this->hasMany(NominaProduccion::class, 'usuario_id');
    }

    /** Lo que gana en un día completo. Sin sueldo asignado todavía, 0. */
    public function valorDiaEfectivo(): float
    {
        return $this->sueldo?->valorDiaEquivalente() ?? 0.0;
    }

    /** Lo que gana por hora — con esto se descuentan las faltas parciales. */
    public function valorHoraEfectivo(): float
    {
        return $this->sueldo?->valorHoraEquivalente() ?? 0.0;
    }

    /** Cuántas horas dura un día completo para este trabajador. */
    public function horasDiaEfectivo(): float
    {
        return (float) ($this->sueldo?->horas_dia ?? 8);
    }

    /** El nombre del sueldo que tiene asignado. */
    public function labelEfectivo(): string
    {
        return $this->sueldo?->nombre ?? 'Sin sueldo asignado';
    }

    public function labelPeriodicidad(): string
    {
        return \App\Services\CicloNomina::label($this->periodicidad);
    }

    /**
     * ¿Se le puede liquidar nómina? Hace falta tener un sueldo asignado; sin
     * eso la liquidación daría $0 y ese cero se vería igual que un pago real.
     */
    public function liquidable(): bool
    {
        return $this->activo && $this->nomina_sueldo_id !== null;
    }

    /** Procesos que se le asignaron a esta persona en concreto. */
    public function procesosAsignados()
    {
        return $this->belongsToMany(TipoProceso::class, 'proceso_trabajadores', 'usuario_id', 'tipo_proceso_id');
    }

    /** Cada paso de producción en el que participó, con horas y calificación. */
    public function participacionesPaso()
    {
        return $this->hasMany(PasoTrabajador::class, 'usuario_id');
    }

    /**
     * La hoja de vida del trabajador en el taller.
     *
     * No se guarda en columnas: se calcula. Un promedio denormalizado se
     * desincroniza en cuanto alguien corrige una calificación o se borra un
     * paso, y un ranking equivocado manda trabajo a quien no debe.
     *
     * @return array{calificaciones:int, calidad_promedio:?float, pasos:int, horas_totales:float, horas_promedio:?float}
     */
    public function desempenoTaller(): array
    {
        $filas = $this->participacionesPaso()
            ->selectRaw('COUNT(*) AS pasos')
            ->selectRaw('COUNT(calidad) AS calificaciones')
            ->selectRaw('AVG(calidad) AS calidad_promedio')
            ->selectRaw('COALESCE(SUM(horas), 0) AS horas_totales')
            ->selectRaw('AVG(horas) AS horas_promedio')
            ->first();

        return [
            'pasos'            => (int) ($filas->pasos ?? 0),
            'calificaciones'   => (int) ($filas->calificaciones ?? 0),
            'calidad_promedio' => $filas?->calidad_promedio !== null
                ? round((float) $filas->calidad_promedio, 2) : null,
            'horas_totales'    => round((float) ($filas->horas_totales ?? 0), 2),
            'horas_promedio'   => $filas?->horas_promedio !== null
                ? round((float) $filas->horas_promedio, 2) : null,
        ];
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
