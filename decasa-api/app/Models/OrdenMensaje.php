<?php

namespace App\Models;

use App\Events\OrdenMensajeEnviado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrdenMensaje extends Model
{
    protected $table = 'orden_mensajes';

    protected $fillable = ['orden_id', 'usuario_id', 'mensaje', 'imagen_url', 'imagenes', 'mencionados', 'es_sistema'];

    protected function casts(): array
    {
        return [
            'mencionados' => 'array',
            'imagenes'    => 'array',
            'es_sistema'  => 'boolean',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /**
     * Una nota automática en el chat: "se canceló lo que faltaba", "garantía
     * resuelta", "el cliente firmó"… La única puerta para escribirlas.
     *
     * Antes salían como si la persona que hizo la acción las hubiera escrito,
     * no aparecían en vivo y la metían en la conversación: desde ahí le
     * llegaban todos los mensajes de esa orden. Ahora llevan la marca
     * `es_sistema` (la pantalla las pinta aparte, con quién hizo la acción),
     * salen en vivo y no cuentan como "ya escribió" para los avisos.
     */
    public static function delSistema(array $datos): self
    {
        if (self::hayColumnaSistema()) $datos['es_sistema'] = true;

        $msg = self::create($datos);

        // Al terminar la transacción (si hay una): que no salga en vivo algo
        // que después se deshace. Si el tiempo real falla, el flujo sigue.
        DB::afterCommit(function () use ($msg) {
            try {
                $msg->loadMissing('usuario:id,nombre,rol');
                event(new OrdenMensajeEnviado($msg->aPantalla(), (int) $msg->orden_id));
            } catch (\Throwable) {}
        });

        return $msg;
    }

    /** Lo que recibe la pantalla (la lista del chat y el tiempo real). */
    public function aPantalla(): array
    {
        return [
            'id'          => $this->id,
            'mensaje'     => $this->mensaje,
            'imagen_url'  => $this->imagen_url,
            // Todas las fotos del mensaje; los viejos traen solo `imagen_url`.
            'imagenes'    => $this->imagenes ?: ($this->imagen_url ? [$this->imagen_url] : []),
            'mencionados' => $this->mencionados ?? [],
            'es_sistema'  => (bool) $this->es_sistema,
            'created_at'  => $this->created_at,
            'usuario'     => [
                'id'     => $this->usuario?->id,
                'nombre' => $this->usuario?->nombre,
                'rol'    => $this->usuario?->rol,
            ],
        ];
    }

    private static ?bool $haySistema = null;

    /** Las pruebas montan el esquema a mano y casi ninguna tiene la columna. */
    public static function hayColumnaSistema(): bool
    {
        return self::$haySistema ??= Schema::hasColumn('orden_mensajes', 'es_sistema');
    }

    /** Ver CachesDePeticion. */
    public static function olvidarCache(): void
    {
        self::$haySistema = null;
    }
}
