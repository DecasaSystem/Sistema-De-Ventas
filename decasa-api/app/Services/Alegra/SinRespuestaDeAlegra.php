<?php

namespace App\Services\Alegra;

/**
 * No se sabe qué pasó en Alegra (timeout, red, 5xx). Una factura que estaba
 * saliendo queda "incierta" y antes de reintentar se busca en Alegra.
 */
class SinRespuestaDeAlegra extends \RuntimeException {}
