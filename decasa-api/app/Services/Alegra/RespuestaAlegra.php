<?php

namespace App\Services\Alegra;

final class RespuestaAlegra
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly mixed $json,
        public readonly ?string $error,
    ) {}
}
