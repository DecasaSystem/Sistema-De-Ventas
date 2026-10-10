<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una plantilla de lo que se repite: el internet del día 5, el arriendo, la
 * licencia anual. Sus obligaciones de cada periodo no se guardan: se calculan
 * del calendario (App\Services\Finanzas\ObligacionesRecurrentes), igual que
 * los ciclos de nómina. Solo cuando se paga (u omite) queda una fila en `gastos`.
 */
class GastoRecurrente extends Model
{
    protected $table = 'gastos_recurrentes';

    public const FRECUENCIAS = ['semanal', 'quincenal', 'mensual', 'bimestral', 'trimestral', 'semestral', 'anual'];

    protected $fillable = [
        'nombre', 'categoria_gasto_id', 'tienda_id', 'proveedor_id', 'monto', 'monto_estimado',
        'frecuencia', 'dia_pago', 'desde', 'hasta', 'prorratear', 'metodo_pago',
        'avisar_dias_antes', 'notas', 'activo', 'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'monto'             => 'decimal:2',
            'monto_estimado'    => 'boolean',
            'prorratear'        => 'boolean',
            'activo'            => 'boolean',
            'dia_pago'          => 'integer',
            'avisar_dias_antes' => 'integer',
            'desde'             => 'date',
            'hasta'             => 'date',
        ];
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_gasto_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function gastos()
    {
        return $this->hasMany(Gasto::class, 'gasto_recurrente_id');
    }
}
