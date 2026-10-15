<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una versión del texto de un documento (anexo de garantías, orden de
 * entrega). Nunca se edita ni se borra: guardar crea otra. La lógica vive en
 * App\Support\PlantillasDocumento.
 */
class PlantillaDocumento extends Model
{
    protected $table = 'plantillas_documento';

    protected $fillable = ['clave', 'version', 'contenido', 'nota', 'creado_por'];

    protected function casts(): array
    {
        return ['contenido' => 'array'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }
}
