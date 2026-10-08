<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persona que escribió por WhatsApp o Instagram (ver la migración
 * create_clientes_redes_table). La arma y la actualiza el webhook de Redes con
 * lo que manda el agente; el asesor le cambia el estado y le pone notas.
 */
class ClienteRed extends Model
{
    protected $table = 'clientes_redes';

    const ESTADOS = ['nuevo', 'contactado', 'compro', 'perdido'];

    protected $fillable = [
        'canal', 'identificador', 'nombre', 'telefono', 'usuario_red', 'contacto_url',
        'ciudad', 'forma_pago', 'espacio', 'presupuesto', 'preferencias', 'productos_interes',
        'categorias_interes', 'interes',
        'ultimo_interes', 'ultimo_tipo', 'estado', 'notas', 'no_quiso_dar_datos',
        'tienda_id', 'cliente_id', 'ultima_conversacion_id', 'total_conversaciones',
        'primer_contacto_at', 'ultimo_contacto_at',
    ];

    protected $casts = [
        'preferencias'       => 'array',
        'productos_interes'  => 'array',
        'categorias_interes' => 'array',
        'presupuesto'        => 'integer',
        'no_quiso_dar_datos' => 'boolean',
        'primer_contacto_at' => 'datetime',
        'ultimo_contacto_at' => 'datetime',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }

    /** Las tarjetas de Redes de esta persona (mismo número o ig_<psid>). */
    public function conversaciones()
    {
        return ConversacionWa::where('telefono', $this->identificador)
            ->where('fuente', $this->canal);
    }
}
