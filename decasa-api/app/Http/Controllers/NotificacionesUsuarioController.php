<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Esquema;
use App\Support\TiposNotificacion;
use Illuminate\Http\Request;

/**
 * Qué avisos le llegan a cada persona, y cuáles como urgentes.
 *
 * Trabajadores → Notificaciones (supervisor): prende y apaga grupos, y escoge
 * cuáles le llegan urgentes. Apagar un grupo hace que esos avisos no se creen
 * para esa persona ni le lleguen al celular (NotificacionService::crear). No
 * borra los que ya tiene.
 *
 * Perfil → Avisos urgentes (la persona misma, 2026-11-17): solo escoge cuáles
 * le llegan urgentes. Apagar avisos sigue siendo del supervisor.
 *
 * Urgente = rojo y fijado arriba de la campana hasta que lo ve o lo borra, y en
 * el celular se queda en la pantalla. Solo se ofrece lo que tiene prendido y le
 * puede llegar por su rol (TiposNotificacion::leLlegaPorRol).
 */
class NotificacionesUsuarioController extends Controller
{
    /** GET /usuarios/{id}/notificaciones */
    public function show(int $id)
    {
        $u = Usuario::findOrFail($id);
        return response()->json($this->respuesta($u));
    }

    /**
     * PUT /usuarios/{id}/notificaciones
     *   { grupos: { ventas: true, atrasados: false, … }, urgentes: { menciones: true, chats: false, … } }
     *
     * Se manda solo lo que cambia: lo que no viene queda como estaba.
     */
    public function update(Request $request, int $id)
    {
        $u = Usuario::findOrFail($id);
        $data = $request->validate([
            'grupos'     => 'required_without:urgentes|array',
            'grupos.*'   => 'boolean',
            'urgentes'   => 'required_without:grupos|array',
            'urgentes.*' => 'boolean',
        ]);
        $this->validarClaves($request, array_keys($data['grupos'] ?? []), array_keys(TiposNotificacion::GRUPOS), 'grupos');
        $this->validarClaves($request, array_keys($data['urgentes'] ?? []), TiposNotificacion::clavesUrgentes(), 'urgentes');

        $apagadas = $u->notificaciones_apagadas ?? [];
        foreach ($data['grupos'] ?? [] as $clave => $prendido) {
            $grupo = TiposNotificacion::GRUPOS[$clave];
            if (! empty($grupo['columna'])) {
                // Los que ya tenían su interruptor siguen en su columna: se
                // revisan donde se manda el aviso.
                $u->{$grupo['columna']} = (bool) $prendido;
                continue;
            }
            $apagadas = $prendido
                ? array_values(array_diff($apagadas, [$clave]))
                : array_values(array_unique([...$apagadas, $clave]));
        }

        if (isset($data['grupos']) && Esquema::columna('usuarios', 'notificaciones_apagadas')) {
            // En el orden del catálogo, y null si no hay ninguna apagada.
            $apagadas = array_values(array_intersect(TiposNotificacion::clavesApagables(), $apagadas));
            $u->notificaciones_apagadas = $apagadas ?: null;
        }
        if (isset($data['urgentes'])) $this->aplicarUrgentes($u, $data['urgentes']);

        $u->save();
        TiposNotificacion::olvidarCache();

        return response()->json($this->respuesta($u->fresh()));
    }

    /** GET /auth/mis-notificaciones — lo de uno mismo, solo para escoger urgentes. */
    public function mias(Request $request)
    {
        return response()->json($this->respuesta($request->user(), soloUrgentes: true));
    }

    /** PUT /auth/mis-notificaciones  { urgentes: { menciones: true, ventas: false, … } } */
    public function guardarMias(Request $request)
    {
        $u = $request->user();
        $data = $request->validate([
            'urgentes'   => 'required|array',
            'urgentes.*' => 'boolean',
        ]);
        $this->validarClaves($request, array_keys($data['urgentes']), TiposNotificacion::clavesUrgentes(), 'urgentes');

        $this->aplicarUrgentes($u, $data['urgentes']);
        $u->save();
        TiposNotificacion::olvidarCache();

        return response()->json($this->respuesta($u->fresh(), soloUrgentes: true));
    }

    private function validarClaves(Request $request, array $claves, array $validas, string $campo): void
    {
        $request->validate([
            $campo => [function ($attr, $valor, $fail) use ($claves, $validas) {
                $raras = array_diff($claves, $validas);
                if ($raras) $fail('No existe el tipo de aviso: ' . implode(', ', $raras) . '.');
            }],
        ]);
    }

    /**
     * Guarda la lista completa (no null) desde el primer cambio: así quitar
     * "menciones" se respeta aunque sea lo de fábrica.
     */
    private function aplicarUrgentes(Usuario $u, array $cambios): void
    {
        if (! Esquema::columna('usuarios', 'notificaciones_urgentes')) return;

        $lista = $u->notificaciones_urgentes ?? TiposNotificacion::URGENTES_DE_FABRICA;
        foreach ($cambios as $clave => $urgente) {
            $lista = $urgente
                ? array_values(array_unique([...$lista, $clave]))
                : array_values(array_diff($lista, [$clave]));
        }
        $u->notificaciones_urgentes = array_values(array_intersect(TiposNotificacion::clavesUrgentes(), $lista));
    }

    /**
     * `grupos`: todos, para prender y apagar (Trabajadores).
     * `urgentes`: solo lo que tiene prendido y le puede llegar, cada uno con si
     * le llega urgente. "Cuando me arroban" va primero.
     */
    private function respuesta(Usuario $u, bool $soloUrgentes = false): array
    {
        $apagadas   = $u->notificaciones_apagadas ?? [];
        $urgentesDe = $u->notificaciones_urgentes ?? TiposNotificacion::URGENTES_DE_FABRICA;
        $yaLlegaron = TiposNotificacion::gruposQueLeHanLlegado($u->id);

        $prendido = fn (string $clave, array $g) => ! empty($g['columna'])
            ? (bool) $u->{$g['columna']}
            : ! in_array($clave, $apagadas, true);

        $grupos   = [];
        $urgentes = [];

        // Las menciones son parte de "Mensajes y chats": si ese grupo está
        // apagado, no le llega ninguna y no hay nada que marcar urgente.
        if ($prendido('chats', TiposNotificacion::GRUPOS['chats'])
            && TiposNotificacion::leLlegaPorRol($u, TiposNotificacion::MENCIONES, $yaLlegaron)) {
            $urgentes[] = [
                'clave'       => TiposNotificacion::MENCIONES,
                'nombre'      => TiposNotificacion::MENCIONES_INFO['nombre'],
                'descripcion' => TiposNotificacion::MENCIONES_INFO['descripcion'],
                'urgente'     => in_array(TiposNotificacion::MENCIONES, $urgentesDe, true),
                'siempre'     => null,
            ];
        }

        foreach (TiposNotificacion::GRUPOS as $clave => $g) {
            $estaPrendido = $prendido($clave, $g);
            $grupos[] = [
                'clave'       => $clave,
                'nombre'      => $g['nombre'],
                'descripcion' => $g['descripcion'],
                'aviso'       => $g['aviso'] ?? null,
                'prendido'    => $estaPrendido,
            ];
            if ($estaPrendido && TiposNotificacion::leLlegaPorRol($u, $clave, $yaLlegaron)) {
                $urgentes[] = [
                    'clave'       => $clave,
                    'nombre'      => $g['nombre'],
                    'descripcion' => $g['descripcion'],
                    'urgente'     => in_array($clave, $urgentesDe, true),
                    'siempre'     => $g['urgente_siempre'] ?? null,
                ];
            }
        }

        return $soloUrgentes ? ['urgentes' => $urgentes] : ['grupos' => $grupos, 'urgentes' => $urgentes];
    }
}
