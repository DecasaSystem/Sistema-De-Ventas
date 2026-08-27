<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La participación de una persona en un paso de producción.
 *
 * Se crea al asignar (con horas y calidad en null) y se completa al cerrar el
 * paso. Que sea una fila y no un nombre suelto es lo que permite responder
 * "cuánto trabajo lleva Jhon" y "qué tan bien lo hace".
 */
class PasoTrabajador extends Model
{
    protected $table = 'paso_trabajadores';

    /**
     * Cuántas horas es un día de taller.
     *
     * El tiempo se guarda siempre en horas, pero en el taller se cuenta por
     * días ("se demoró tres días"), así que la pantalla deja escribirlo así y
     * aquí se convierte. El día vale 8 horas para todos a propósito: la
     * jornada de nómina (`horas_dia` del sueldo) sirve para pagar, no para
     * medir, y si el día de uno valiera 9 y el de otro 8 las horas de cada
     * quien dejarían de ser comparables.
     */
    public const HORAS_POR_DIA = 8;

    /** El número que escribió una persona, en horas. */
    public static function aHoras(float $valor, ?string $unidad): float
    {
        return $unidad === 'dia' ? $valor * self::HORAS_POR_DIA : $valor;
    }

    protected $fillable = [
        'paso_id',
        'usuario_id',
        'asignado_por',
        'asignado_at',
        'horas',
        'calidad',
        'comentario',
        'calificado_por',
        'calificado_at',
    ];

    protected function casts(): array
    {
        return [
            'asignado_at'   => 'datetime',
            'calificado_at' => 'datetime',
            'horas'         => 'decimal:2',
            'calidad'       => 'integer',
        ];
    }

    public function paso()
    {
        return $this->belongsTo(ProduccionPaso::class, 'paso_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function asignadoPor()
    {
        return $this->belongsTo(Usuario::class, 'asignado_por');
    }

    public function calificadoPor()
    {
        return $this->belongsTo(Usuario::class, 'calificado_por');
    }
}
