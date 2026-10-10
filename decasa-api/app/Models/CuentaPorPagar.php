<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una factura de proveedor a crédito (30 o 60 días). Es gasto del mes de la
 * factura (estado de resultados) y sale de caja cuando se paga, en uno o
 * varios abonos: cada abono es un `Gasto` con `cuenta_por_pagar_id`, que
 * Finanzas cuenta solo como caja (el gasto ya contó con la factura).
 */
class CuentaPorPagar extends Model
{
    protected $table = 'cuentas_por_pagar';

    protected $fillable = [
        'proveedor_id', 'proveedor_nombre', 'concepto', 'numero_factura', 'categoria_gasto_id', 'tienda_id',
        'monto', 'fecha_factura', 'fecha_vencimiento', 'estado', 'comprobante_fotos', 'notas',
        'motivo_anulacion', 'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'monto'             => 'decimal:2',
            'fecha_factura'     => 'date',
            'fecha_vencimiento' => 'date',
            'comprobante_fotos' => 'array',
        ];
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_gasto_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    public function abonos()
    {
        return $this->hasMany(Gasto::class, 'cuenta_por_pagar_id')->where('estado', Gasto::PAGADO);
    }

    public function pagado(): float
    {
        return (float) ($this->relationLoaded('abonos') ? $this->abonos->sum('monto') : $this->abonos()->sum('monto'));
    }

    public function saldo(): float
    {
        return max(0.0, round((float) $this->monto - $this->pagado(), 2));
    }

    public function nombreProveedor(): ?string
    {
        return $this->proveedor?->nombre ?? $this->proveedor_nombre;
    }
}
