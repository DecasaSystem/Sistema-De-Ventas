<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DespachoItem extends Model
{
    protected $table = 'despacho_items';

    public $timestamps = false;

    protected $fillable = [
        'despacho_id',
        'orden_id',
        'posicion',
        'estado',
        'foto_producto',
        'foto_pago',
        'entregado_at',
    ];

    protected function casts(): array
    {
        return [
            'entregado_at' => 'datetime',
        ];
    }

    public function despacho()
    {
        return $this->belongsTo(Despacho::class, 'despacho_id');
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function tieneFotos(): bool
    {
        return $this->foto_producto !== null && $this->foto_pago !== null;
    }

    public function tienePago(): bool
    {
        return $this->orden->pagos()
            ->where('created_at', '>=', $this->despacho->created_at)
            ->exists();
    }

    public function puedeEntregar(): bool
    {
        return $this->tieneFotos() && $this->tienePago();
    }
}
