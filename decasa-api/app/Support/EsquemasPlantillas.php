<?php

namespace App\Support;

/**
 * Los textos y parámetros de Gestión → Documentos que son una lista de campos
 * (sin secciones como el anexo): datos de la empresa, plazos de garantía,
 * condiciones de la cotización, acta de satisfacción y mensajes de WhatsApp.
 *
 * Cada esquema dice qué campos tiene, de qué tipo, su texto de fábrica y qué
 * datos se pueden poner entre llaves. La pantalla de Gestión se arma con esto
 * (un solo editor para todos) y PlantillasDocumento los valida con esto.
 *
 * Tipos de campo:
 *  - texto:      un renglón.
 *  - multilinea: un texto que conserva los saltos de línea (mensajes).
 *  - parrafos:   una lista de párrafos (un renglón = un párrafo).
 *  - numero:     un entero entre `min` y `max`.
 */
final class EsquemasPlantillas
{
    public const EMPRESA    = 'empresa';
    public const PLAZOS     = 'plazos_garantia';
    public const COTIZACION = 'cotizacion';
    public const ACTA       = 'acta_entrega';
    public const MENSAJES   = 'mensajes_whatsapp';

    /** Los datos de los mensajes de WhatsApp, iguales en todos. */
    private const DATOS_MENSAJE = ['cliente', 'asesor', 'empresa', 'empresa_completo', 'referencia', 'enlace'];

    public static function claves(): array
    {
        return [self::ACTA, self::COTIZACION, self::EMPRESA, self::PLAZOS, self::MENSAJES];
    }

    public static function tiene(string $clave): bool
    {
        return in_array($clave, self::claves(), true);
    }

    /** @return array{nombre:string,descripcion:string,grupo:string,datos:array,aviso:?string,campos:array} */
    public static function de(string $clave): array
    {
        return match ($clave) {
            self::EMPRESA => [
                'nombre'      => 'Datos de la empresa',
                'descripcion' => 'El nombre y los datos que salen en los PDF (orden, cotización, actas, anexo, nómina), en los correos y en los mensajes de WhatsApp.',
                'grupo'       => 'negocio',
                'datos'       => [],
                'aviso'       => null,
                'campos'      => [
                    self::campo('nombre', 'texto', 'Nombre corto', 'Decasa', max: 60, requerido: true,
                        ayuda: 'El que va en los asuntos de correo y los pies de página.'),
                    self::campo('nombre_completo', 'texto', 'Nombre completo', 'Decasa Muebles y Decoración', max: 120, requerido: true,
                        ayuda: 'El que va en los encabezados de los documentos y en los saludos.'),
                    self::campo('nit', 'texto', 'NIT', '', max: 30),
                    self::campo('direccion', 'texto', 'Dirección principal', '', max: 150),
                    self::campo('telefono', 'texto', 'Teléfono', '', max: 40),
                    self::campo('correo', 'texto', 'Correo', '', max: 120),
                    self::campo('sitio_web', 'texto', 'Página web', '', max: 120),
                ],
            ],
            self::PLAZOS => [
                'nombre'      => 'Plazos de garantía',
                'descripcion' => 'Con estos meses el sistema calcula si un daño reportado todavía está en garantía. Deben decir lo mismo que el anexo.',
                'grupo'       => 'negocio',
                'datos'       => [],
                'aviso'       => 'Un cambio vale para las garantías que se reporten desde hoy: las ya reportadas conservan la fecha de vencimiento que se les calculó. Cambia también el texto del anexo para que diga lo mismo.',
                'campos'      => [
                    self::campo('madera_elite_promocional', 'numero', 'Madera — línea élite y promocional (meses)', 60, min: 0, max: 240, requerido: true),
                    self::campo('madera_economica', 'numero', 'Madera — línea económica (meses)', 24, min: 0, max: 240, requerido: true),
                    self::campo('tela_espuma', 'numero', 'Telas y espumas (meses)', 6, min: 0, max: 240, requerido: true),
                ],
            ],
            self::COTIZACION => [
                'nombre'      => 'Cotización',
                'descripcion' => 'Las condiciones que salen al final del PDF de la cotización y cuántos días vale por defecto.',
                'grupo'       => 'documentos',
                'datos'       => ['valida_hasta'],
                'aviso'       => null,
                'campos'      => [
                    self::campo('condiciones', 'parrafos', 'Condiciones', [
                        'Esta cotización es informativa y no reserva mercancía. La disponibilidad se confirma al generar la orden.',
                        'Precios válidos hasta el {valida_hasta} y sujetos a cambio después de esa fecha.',
                        'Los tiempos de entrega se acuerdan al confirmar la orden y registrar el anticipo.',
                    ], max: 600, maxItems: 10,
                        ayuda: 'Cada renglón es una condición. El renglón que lleve {valida_hasta} solo sale si la cotización tiene fecha de validez.'),
                    self::campo('dias_vigencia', 'numero', 'Días que vale por defecto', 15, min: 1, max: 180, requerido: true,
                        ayuda: 'Lo que sale escogido en Nueva orden al cotizar; el vendedor lo puede cambiar.'),
                ],
            ],
            self::ACTA => [
                'nombre'      => 'Acta de satisfacción',
                'descripcion' => 'Lo que firma quien recibe en la entrega. Sale en la pantalla de entrega y en el PDF del acta.',
                'grupo'       => 'documentos',
                'datos'       => ['referencia'],
                'aviso'       => 'Cada acta sale con el texto que estaba vigente el día de la entrega: cambiarlo no cambia las actas ya firmadas.',
                'campos'      => [
                    self::campo('titulo', 'texto', 'Título', 'ACTA DE SATISFACCIÓN', max: 60, requerido: true),
                    self::campo('declaracion', 'multilinea', 'Declaración', 'Declaro que recibí los productos relacionados en esta acta, correspondientes a la orden {referencia}, en la fecha y dirección indicadas.', max: 1500, requerido: true),
                    self::campo('texto_parcial', 'multilinea', 'Si es entrega parcial, se agrega', 'Esta es una entrega parcial: lo que aparece como pendiente se entregará aparte.', max: 500),
                    self::campo('texto_conforme', 'multilinea', 'Si llegó bien, se agrega', 'Manifiesto que llegaron en buen estado y a satisfacción.', max: 500, requerido: true),
                    self::campo('texto_novedad', 'multilinea', 'Si hubo novedad, se agrega', 'Dejo constancia de la siguiente novedad al momento de recibir.', max: 500, requerido: true),
                    self::campo('firma_recibe', 'texto', 'Rótulo bajo la firma', 'Recibe conforme', max: 60, requerido: true),
                ],
            ],
            self::MENSAJES => [
                'nombre'      => 'Mensajes de WhatsApp',
                'descripcion' => 'Los textos que se le abren al vendedor para mandarle al cliente: el enlace para firmar, el resumen del pedido y el saludo de Redes y Citas.',
                'grupo'       => 'negocio',
                'datos'       => self::DATOS_MENSAJE,
                'aviso'       => 'Si el cliente no tiene nombre, {cliente} se quita junto con el espacio de antes ("Hola, soy…").',
                'campos'      => [
                    self::campo('firma_pedido', 'multilinea', 'Enlace para firmar un pedido nuevo (Nueva orden)',
                        'Hola {cliente}, soy {asesor} de {empresa}. Aquí puedes revisar tu pedido completo y firmarlo junto con el documento de garantías: {enlace}',
                        max: 1000, requerido: true, ayuda: 'Tiene que llevar {enlace}.'),
                    self::campo('firma_orden', 'multilinea', 'Enlace para firmar una orden ya creada',
                        'Hola {cliente}, soy {asesor} de {empresa}. Aquí puedes revisar tu orden {referencia} completa y firmarla junto con el documento de garantías: {enlace}',
                        max: 1000, requerido: true, ayuda: 'Tiene que llevar {enlace}.'),
                    self::campo('resumen_saludo', 'multilinea', 'Resumen del pedido — comienzo',
                        "Hola {cliente} 👋\n\nAquí tienes el resumen de tu pedido en *{empresa}* (Orden {referencia}):",
                        max: 1000, requerido: true, ayuda: 'Después de esto el sistema pone los productos, el total, lo pagado y el saldo.'),
                    self::campo('resumen_despedida', 'multilinea', 'Resumen del pedido — final',
                        "Adjunto encontrarás la cotización en PDF con todos los detalles.\n¡Gracias por tu compra! 🛋️",
                        max: 1000),
                    self::campo('saludo_redes', 'multilinea', 'Saludo al tomar un cliente de Redes o Citas',
                        'Hola {cliente}, soy {asesor} de {empresa_completo} 🛋️ Me da mucho gusto atenderte.',
                        max: 1000, requerido: true, ayuda: 'Después el sistema agrega lo de la cita o lo que el cliente pidió.'),
                ],
            ],
        };
    }

    /** El texto de fábrica de un esquema. */
    public static function fabrica(string $clave): array
    {
        $out = [];
        foreach (self::de($clave)['campos'] as $c) $out[$c['campo']] = $c['defecto'];

        return $out;
    }

    /** Campos que tienen que llevar un dato sí o sí (sin él el mensaje no sirve). */
    public static function datoObligatorio(string $clave, string $campo): ?string
    {
        return $clave === self::MENSAJES && in_array($campo, ['firma_pedido', 'firma_orden'], true) ? 'enlace' : null;
    }

    private static function campo(
        string $campo, string $tipo, string $etiqueta, mixed $defecto,
        int $max = 255, bool $requerido = false, ?string $ayuda = null,
        int $min = 0, int $maxItems = 20,
    ): array {
        return compact('campo', 'tipo', 'etiqueta', 'defecto', 'max', 'requerido', 'ayuda', 'min', 'maxItems');
    }
}
