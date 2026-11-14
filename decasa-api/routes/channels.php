<?php

use App\Http\Controllers\OrdenMensajeController;
use App\Models\AnexoGarantia;
use App\Models\ConsultaCosto;
use App\Models\Orden;
use Illuminate\Support\Facades\Broadcast;

/*
 * Quién puede escuchar cada canal de tiempo real.
 *
 * TODOS los canales son privados (revisión de seguridad, 2026-11-14). Antes
 * eran públicos (`new Channel`), y en un canal público estas reglas NO se
 * aplican: cualquiera con la clave de Reverb (va dentro de la app, la puede
 * ver quien abra el navegador) podía escuchar sin iniciar sesión los avisos de
 * cada persona, el chat de cada orden y las conversaciones de WhatsApp con el
 * teléfono del cliente. Ahora la app pide permiso en /api/broadcasting/auth
 * con su sesión y aquí se decide, con la misma regla de la pantalla.
 *
 * Un evento nuevo: `new PrivateChannel(...)` y su regla aquí. Nunca
 * `new Channel(...)`.
 */

$suyo = fn ($user, $id) => (int) $user->id === (int) $id;
$conSesion = fn ($user) => (bool) $user;

Broadcast::channel('App.Models.User.{id}', $suyo);

// Lo de todo el equipo (sin datos de plata): basta con tener sesión.
Broadcast::channel('ordenes',    $conSesion);
Broadcast::channel('inventario', $conSesion);
Broadcast::channel('produccion', $conSesion);
Broadcast::channel('despacho',   $conSesion);

// Avisos: los de cada persona, solo ella. Los generales, los supervisores.
Broadcast::channel('notificaciones.{userId}', $suyo);
Broadcast::channel('notificaciones', fn ($user) => $user->rol === 'supervisor');

Broadcast::channel('supervisor', fn ($user) => $user->rol === 'supervisor');
Broadcast::channel('supervisor.{id}', $suyo);
Broadcast::channel('vendedor.{id}',   $suyo);
Broadcast::channel('conductor.{id}',  $suyo);

// Redes (WhatsApp/Instagram): lo mismo que deja ver la pantalla.
Broadcast::channel('redes', fn ($user) => $user->rol === 'supervisor' || (bool) $user->acceso_redes);

// Chat de la orden: quien puede participar en él (OrdenMensajeController).
Broadcast::channel('orden.{ordenId}', function ($user, $ordenId) {
    $orden = Orden::find($ordenId);
    return $orden && OrdenMensajeController::puedeParticipar($user, $orden);
});

// Consulta de costo: el supervisor y sus participantes (ConsultaCostoController::show).
Broadcast::channel('consulta.{consultaId}', function ($user, $consultaId) {
    if ($user->rol === 'supervisor') return true;
    $c = ConsultaCosto::find($consultaId);
    return $c && ((int) $c->asignado_a_id === (int) $user->id || (int) $c->solicitado_por_id === (int) $user->id);
});

// Firma remota: quien mandó el anexo, un supervisor o quien puede editar su orden
// (AnexoGarantiaController::esSuyo).
Broadcast::channel('anexo.{anexoId}', function ($user, $anexoId) {
    $a = AnexoGarantia::find($anexoId);
    if (! $a) return false;
    if ((int) $a->vendedor_id === (int) $user->id || $user->rol === 'supervisor') return true;
    return $a->orden_id && ($o = Orden::find($a->orden_id)) && $o->laPuedeEditar($user);
});
