<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un gasto pagado (o un periodo de plantilla que se omitió). Dos fechas:
 *
 * - `fecha_pago`: cuándo salió la plata (flujo de caja).
 * - `cubre_desde` / `cubre_hasta`: a qué meses pertenece (estado de
 *   resultados). El recibo de luz de septiembre pagado en octubre es gasto de
 *   septiembre; la licencia anual se reparte en sus doce meses.
 *
 * Un error se anula con motivo (`estado = anulado`), no se borra.
 */
class Gasto extends Model
{
    protected $table = 'gastos';

    public const PAGADO  = 'pagado';
    public const OMITIDO = 'omitido';
    public const ANULADO = 'anulado';

    public const METODOS = ['efectivo', 'transferencia', 'tarjeta', 'otro'];

    protected $fillable = [
        'categoria_gasto_id', 'gasto_recurrente_id', 'periodo', 'tienda_id', 'proveedor_id',
        'cuenta_por_pagar_id', 'canal',
        'concepto', 'monto', 'estado', 'fecha_pago', 'cubre_desde', 'cubre_hasta',
        'metodo_pago', 'comprobante_url', 'comprobante_fotos', 'notas',
        'motivo_anulacion', 'registrado_por', 'anulado_por', 'anulado_at',
    ];

    protected function casts(): array
    {
        return [
            'monto'             => 'decimal:2',
            'fecha_pago'        => 'date',
            'cubre_desde'       => 'date',
            'cubre_hasta'       => 'date',
            'comprobante_fotos' => 'array',
            'anulado_at'        => 'datetime',
        ];
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_gasto_id');
    }

    public function recurrente()
    {
        return $this->belongsTo(GastoRecurrente::class, 'gasto_recurrente_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }
}
