<?php

namespace App\Services;

use App\Models\Encargo;
use App\Models\EncargoRevision;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cuándo le toca la próxima revista a cada quien.
 *
 * No se guarda una "fecha de próxima revisión" en ninguna columna: se calcula
 * de la última revista más el intervalo. Una fecha guardada se desincroniza en
 * cuanto se cambia cada cuánto se revisa —o en cuanto se borra una revista— y
 * entonces el módulo avisa cuando no toca, o peor, se queda callado cuando sí.
 *
 * El intervalo sale del trabajador si se le puso uno propio; si no, del número
 * general que se ajusta desde la pantalla de Encargos.
 */
class RevisionEncargos
{
    /** Cada cuántos días se revisa, si nadie ha configurado nada. */
    public const DIAS_POR_DEFECTO = 30;

    /** Se avisa desde tantos días antes de que se venza. */
    private const DIAS_AVISO = 3;

    /** El intervalo general, el que aplica a quien no tenga uno propio. */
    public static function diasGenerales(): int
    {
        $valor = DB::table('configuracion')->where('clave', 'encargos_dias_revision')->value('valor');

        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : self::DIAS_POR_DEFECTO;
    }

    public static function guardarDiasGenerales(int $dias): void
    {
        DB::table('configuracion')->updateOrInsert(
            ['clave' => 'encargos_dias_revision'],
            ['valor' => (string) $dias, 'updated_at' => now()],
        );
    }

    /** Cada cuántos días le toca a esta persona. */
    public static function diasDe(Usuario $trabajador): int
    {
        return $trabajador->encargo_revision_dias ?: self::diasGenerales();
    }

    /**
     * Cómo va de revista: el estado, cuándo fue la última y cuándo toca.
     *
     * Quien no tiene nada a cargo no tiene nada que revisar — sale como
     * 'sin_encargos' y no aparece en los avisos, porque llamar a alguien a
     * contar cero herramientas es solo ruido.
     *
     * @return array{estado:string, ultima:?string, proxima:?string, dias_restantes:?int, dias:int}
     */
    public static function estadoDe(Usuario $trabajador, ?Carbon $hoy = null): array
    {
        $hoy  = ($hoy ?? Carbon::today())->copy()->startOfDay();
        $dias = self::diasDe($trabajador);

        $ultima = EncargoRevision::where('usuario_id', $trabajador->id)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->value('fecha');

        // Sin nada a cargo no hay revista pendiente, aunque sí puede haber
        // revistas viejas: se le entregó, se le revisó y devolvió todo.
        $tieneACargo = Encargo::where('usuario_id', $trabajador->id)
            ->where('estado', 'a_cargo')->exists();

        if (! $tieneACargo) {
            return [
                'estado'         => 'sin_encargos',
                'ultima'         => $ultima ? Carbon::parse($ultima)->toDateString() : null,
                'proxima'        => null,
                'dias_restantes' => null,
                'dias'           => $dias,
            ];
        }

        // Nunca se le ha revisado: el reloj arranca el día que se le entregó
        // lo primero que todavía tiene. Si arrancara hoy, a nadie se le
        // vencería nunca la primera revista.
        $desde = $ultima
            ? Carbon::parse($ultima)
            : Carbon::parse(
                Encargo::where('usuario_id', $trabajador->id)
                    ->where('estado', 'a_cargo')
                    ->min('fecha_entrega')
            );

        $proxima    = $desde->copy()->startOfDay()->addDays($dias);
        $restantes  = (int) $hoy->diffInDays($proxima, false);

        return [
            'estado'         => $restantes < 0 ? 'vencida' : ($restantes <= self::DIAS_AVISO ? 'pronto' : 'al_dia'),
            'ultima'         => $ultima ? Carbon::parse($ultima)->toDateString() : null,
            'proxima'        => $proxima->toDateString(),
            'dias_restantes' => $restantes,
            'dias'           => $dias,
        ];
    }
}
