<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un rol/puesto de trabajo, configurable por empresa (antes era un enum fijo
 * en código). Cada uno se apoya en un `arquetipo` — el comportamiento de
 * fondo que ya existía (vendedor, supervisor, conductor, taller,
 * despachador) y que sigue determinando el cálculo de comisiones, caja, etc.
 * sin que este modelo lo toque.
 */
class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'clave', 'nombre', 'arquetipo', 'activo', 'orden',
        'acceso_redes', 'acceso_comisiones', 'recarga_telas', 'acceso_surtir',
        'acceso_costos', 'acceso_proveedores', 'acceso_despacho', 'acceso_produccion', 'acceso_reserva', 'acceso_nomina',
        'acceso_compras', 'modulos_venta',
    ];

    /**
     * Los roles que vinieron de fábrica. Para ellos `usuarios.rol` es su
     * propia clave, como siempre: el código pregunta en ~200 sitios
     * `rol === 'supervisor'`, `rol === 'ebanista'`... y así sigue.
     */
    public const CLAVES_DE_FABRICA = ['vendedor', 'supervisor', 'conductor', 'ebanista', 'despachador', 'costurero'];

    /**
     * Con qué palabra se comporta un rol creado por la empresa: la de su
     * arquetipo. El de taller NO está a propósito y se queda con su clave:
     * en producción hay ~22 de fábrica con roles nuevos de taller (Tapicero,
     * Lijador, Pintor, Operario…) que ya funcionan bien así, porque lo del
     * taller se decide por el arquetipo y los pasos asignados. Pasarlos a
     * `ebanista` les abriría lo que el código todavía le da a ese nombre
     * (costos en Consultas, avisos de consultas de precio).
     */
    public const PALABRA_DEL_ARQUETIPO = [
        'vendedor'    => 'vendedor',
        'supervisor'  => 'supervisor',
        'conductor'   => 'conductor',
        'despachador' => 'despachador',
    ];

    /**
     * Lo que se guarda en `usuarios.rol` para quien tiene este rol.
     *
     * Antes se guardaba la clave siempre, y un rol nuevo ("Gerente", de tipo
     * supervisor) quedaba como `gerente`: no era supervisor en ningún lado
     * (no aprobaba cambios, no entraba a Gestión), porque todo el programa
     * pregunta por la palabra `supervisor`. Ahora un rol nuevo se guarda con
     * la palabra de su arquetipo y se comporta como lo que dice ser; el
     * nombre que ve la gente sale de `rol_id` (`rol_nombre`), no de aquí.
     */
    public function rolEnElCodigo(): string
    {
        if (in_array($this->clave, self::CLAVES_DE_FABRICA, true)) {
            return $this->clave;
        }

        return self::PALABRA_DEL_ARQUETIPO[$this->arquetipo] ?? $this->clave;
    }

    protected function casts(): array
    {
        return [
            'activo'              => 'boolean',
            'orden'                => 'integer',
            'acceso_redes'         => 'boolean',
            'acceso_comisiones'    => 'boolean',
            'recarga_telas'        => 'boolean',
            'acceso_surtir'        => 'boolean',
            'acceso_costos'        => 'boolean',
            'acceso_proveedores'   => 'boolean',
            'acceso_despacho'      => 'boolean',
            'acceso_produccion'    => 'boolean',
            'acceso_reserva'       => 'boolean',
            'acceso_nomina'        => 'boolean',
            'acceso_compras'       => 'boolean',
            // Los módulos de venta que trae el rol (ModulosDeVenta::delRol).
            'modulos_venta'        => 'array',
        ];
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'rol_id');
    }
}
