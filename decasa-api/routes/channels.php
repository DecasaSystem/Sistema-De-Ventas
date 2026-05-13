<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canales públicos
Broadcast::channel('ordenes',         fn () => true);
Broadcast::channel('inventario',      fn () => true);
Broadcast::channel('produccion',      fn () => true);
Broadcast::channel('notificaciones',          fn () => true);
Broadcast::channel('notificaciones.{userId}', fn () => true);
Broadcast::channel('despacho',  fn () => true);
Broadcast::channel('supervisor', fn ($user) => $user->rol === 'supervisor');

// Canal privado del conductor
Broadcast::channel('conductor.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
