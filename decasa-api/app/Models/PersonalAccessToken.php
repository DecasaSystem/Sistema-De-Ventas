<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as TokenDeSanctum;

/**
 * El token de sesión, sin la escritura que se hacía en cada petición.
 *
 * Sanctum anota `last_used_at` CADA vez que alguien llama a la API: un UPDATE
 * a la base antes de responder, en todas las pantallas, todas las veces. Con
 * la base lejos del servidor eso eran ~200 ms por llamada (medido, ver
 * docs/plan-rendimiento.md), y el dato no lo lee nada del programa: la sesión
 * vence por `created_at` / `expires_at`, no por el último uso.
 *
 * Se sigue anotando, pero como mucho una vez cada MINUTOS_ENTRE_ANOTACIONES:
 * sigue diciendo si un token está vivo, que es para lo único que serviría.
 */
class PersonalAccessToken extends TokenDeSanctum
{
    public const MINUTOS_ENTRE_ANOTACIONES = 10;

    public function save(array $options = [])
    {
        if ($this->soloCambiaElUltimoUsoYEsReciente()) {
            // Nada que guardar: el que hay todavía sirve.
            $this->syncOriginalAttribute('last_used_at');
            return true;
        }

        return parent::save($options);
    }

    private function soloCambiaElUltimoUsoYEsReciente(): bool
    {
        if (! $this->exists || array_keys($this->getDirty()) !== ['last_used_at']) {
            return false;
        }

        $anterior = $this->getOriginal('last_used_at');

        return $anterior !== null
            && $anterior->gt(now()->subMinutes(self::MINUTOS_ENTRE_ANOTACIONES));
    }
}
