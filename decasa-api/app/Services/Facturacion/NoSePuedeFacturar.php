<?php

namespace App\Services\Facturacion;

/** No se facturó y no se mandó nada a Alegra (o se frenó a tiempo). */
class NoSePuedeFacturar extends \RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly int $status = 422,
        public readonly array $alertas = [],
        public readonly array $sinAceptar = [],
    ) {
        parent::__construct($mensaje);
    }
}
