<?php

namespace App\Support;

/**
 * El anexo que firma el cliente en una RESTAURACIÓN (refacción de un mueble
 * suyo), tal como está en el papel "FORMATO ANEXO REFACCIONES.docx".
 *
 * Antes (hasta 2026-11-17) una restauración mostraba el anexo de garantías de
 * VENTAS, que habla de productos nuevos, plazos de fabricación y otras
 * garantías: no era el que firma el cliente de una refacción (queja del
 * usuario). Ahora una orden que es solo restauración (Orden::esSoloRestauracion)
 * firma este; una que mezcla producto y restauración es venta y firma el de
 * ventas.
 *
 * Cambio pedido por el usuario al pasarlo: la garantía de la espuma es de
 * 6 meses (el papel decía 1 año).
 *
 * Este es el texto de FÁBRICA. El negocio lo cambia en Gestión → Documentos
 * (clave PlantillasDocumento::ANEXO_RESTAURACION), con versiones, igual que el
 * de ventas. Si se cambia el texto de aquí, se sube VERSION.
 */
final class AnexoRestauracionTexto
{
    public const VERSION = 'restauracion-2026-11';

    public const TITULO = 'Anexo informativo orden de pedido — Refacciones';

    /** @return array<int,array{id:string,titulo:string,parrafos:array<int,string>}> */
    public static function secciones(): array
    {
        return [
            [
                'id'     => 'estado_del_mueble',
                'titulo' => 'Estado del mueble',
                'parrafos' => [
                    'La empresa no se hace responsable por enfermedades en la madera, hongos, broma, desajustes, fallas en la madera como dilataciones o deformaciones y defectos de fabricación que presente el mueble para el momento de la restauración.',
                ],
            ],
            [
                'id'     => 'garantia_tela',
                'titulo' => 'Garantía de la tela',
                'parrafos' => [
                    'La garantía de la tela es de 6 meses a partir del día de la entrega del mueble. Esta no cubre maltrato, descuido, mala manipulación ni exposición al sol, por lo tanto es necesario que tenga en cuenta las siguientes instrucciones para el cuidado:',
                    'Limpiar con una tela suave y agua.',
                    'No utilizar líquidos o sustancias corrosivas tales como varsol, thinner o límpido en el mantenimiento y limpieza de la tela.',
                ],
            ],
            [
                'id'     => 'garantia_espuma',
                'titulo' => 'Garantía de la espuma',
                'parrafos' => [
                    'La garantía de la espuma es de 6 meses a partir de la fecha de entrega de su mueble.',
                ],
            ],
            [
                'id'     => 'entrega_bodegaje',
                'titulo' => 'Entrega, bodegaje y retiro del mueble',
                'parrafos' => [
                    'En el momento de concretar el negocio y definir el tiempo de restauración, la empresa le dará 15 días más después de la fecha pactada de entrega para recibir su mueble y ser cancelado en su totalidad; de no ser así, se iniciará un cobro mensual de bodegaje.',
                    'Se dará un máximo de tres meses para retirar o reclamar el artículo; de lo contrario, la empresa no responderá por el artículo y nos exoneramos de toda responsabilidad.',
                ],
            ],
            [
                'id'     => 'telas_pintura_cotizacion',
                'titulo' => 'Telas, pintura y cotización',
                'parrafos' => [
                    'Se aclara que las telas que la empresa utiliza tienen un rango de costo de acuerdo a la gama; son telas importadas y dependemos de la disponibilidad del proveedor. En caso de agotarse, deberá elegir otra opción existente en el almacén.',
                    'Se aclara que la tonalidad de la pintura puede variar dependiendo de la clase de madera de la que esté fabricado su mueble y de los tratamientos de pintura que haya tenido anteriormente.',
                    'La cotización de la refacción se realiza por lo que se pueda ver en su exterior. Una vez ingresado el mueble a la fábrica y destapado, si requiere arreglos adicionales tales como cambio de espuma adicional o reparación de la estructura de la madera, se le informará con previo aviso para su decisión y para dar a conocer el costo adicional que tendría.',
                    'Una vez cancelado el 50% y definidas las telas, colores y características, no se podrán realizar cambios.',
                ],
            ],
            [
                'id'     => 'aceptacion',
                'titulo' => 'Aceptación',
                'parrafos' => [
                    'Acepto haber recibido y entendido la anterior información para hacer uso de la garantía y las condiciones antes expuestas.',
                ],
            ],
        ];
    }

    /** El papel no trae check list; se le pueden agregar preguntas desde Gestión. */
    public static function checklist(): array
    {
        return [];
    }
}
