<?php

namespace App\Support;

/**
 * El anexo de garantías e instructivo de uso de Decasa, tal como está en el
 * documento impreso ("ANEXO GARANTIAS DE CASA.pdf").
 *
 * Es la única copia del texto: la pantalla donde el cliente lo lee y firma y
 * el PDF que queda guardado salen de aquí, así que nunca dicen cosas
 * distintas. Si el texto cambia, se sube VERSION: cada anexo firmado guarda la
 * versión que leyó.
 */
final class AnexoGarantiaTexto
{
    public const VERSION = '2026-10';

    public const TITULO = 'Garantías e instructivo de uso de los productos adquiridos en Decasa Muebles y Decoración';

    /** @return array<int,array{id:string,titulo:string,parrafos:array<int,string>}> */
    public static function secciones(): array
    {
        return [
            [
                'id'     => 'garantia',
                'titulo' => 'Garantía de los productos',
                'parrafos' => [
                    'De Casa Muebles y Decoración ofrece la siguiente garantía para la ejecución, composición y funcionamiento de sus productos de acuerdo con:',
                    '1. Las presentes condiciones de garantía son válidas para el país en el que se produzca la venta, siempre y cuando el pedido se haya realizado correctamente al departamento de exportación.',
                    '2. De Casa Muebles y Decoración ofrece una garantía para todos los productos, con efecto desde su fecha de entrega.',
                    '3. Numeral I: Garantía de la madera por broma, desajuste y dilataciones por el término de 5 años en la línea élite y promocional; la línea económica, 2 años de garantía, contados a partir de la fecha de entrega. Nota: la madera pintada en colores claros o al natural deja observar las vetas de la madera y otros detalles tales como nudos, pegas, resanes, puntillas y manchas, las cuales son completamente normales.',
                    'Nuestra garantía incluye la reparación, la reposición o cambio del producto y/o componentes sin cargo alguno para el cliente, incluyendo mano de obra.',
                    'De Casa Muebles y Decoración se compromete a entregar el producto en un lapso no superior a treinta (30) días calendario contados a partir de la recepción del producto defectuoso en nuestros talleres. Nota: trabajamos con medidas aproximadas; nuestros productos son elaborados a mano y cada una de sus piezas es única y diferente.',
                    '3.1 Numeral I: La garantía de las telas y espumas es de 6 meses contados a partir de la fecha de entrega.',
                ],
            ],
            [
                'id'     => 'exclusiones',
                'titulo' => 'Cuándo no aplica la garantía',
                'parrafos' => [
                    '4. De conformidad con lo dispuesto en el artículo 16 de la Ley 1480 de 2011, la garantía que ofrece De Casa Muebles y Decoración no será válida en los siguientes casos:',
                    'Numeral I: Cuando el defecto se haya ocasionado por la exposición continua e ininterrumpida al sol, la humedad, la lluvia o al calor.',
                    'Numeral II: Cuando el uso y cuidado no hayan sido de acuerdo con las instrucciones contenidas en el instructivo de uso de productos.',
                    'Numeral III: Cuando el producto haya sido usado fuera de su capacidad, maltratado, golpeado o expuesto a algún líquido, sustancia corrosiva, telas que destiñen, así como también cualquier conducta atribuible al cliente.',
                    'Numeral IV: Cuando haya sido desarmado, modificado o reparado por personas no autorizadas por De Casa Muebles y Decoración.',
                    'Numeral V: Cuando ocurra fuerza mayor o caso fortuito.',
                    'Numeral VI: Cuando el defecto sea ocasionado por la conducta o hecho de un tercero.',
                    'Numeral VII: Cuando se dé un desajuste por maltrato, arrastre o mal levantamiento del producto.',
                ],
            ],
            [
                'id'     => 'outlet_transporte',
                'titulo' => 'Outlet, transporte y cómo pedir la garantía',
                'parrafos' => [
                    '5. Los productos en outlet se entregan tal y como se encuentren en la exhibición al momento de efectuar la compra.',
                    '6. Dentro de la garantía que ofrece De Casa Muebles y Decoración se aclara que dicha garantía se efectuará sin costo de transporte, siempre y cuando sea dentro de la ciudad de Armenia, Quindío. Por consiguiente, se especifica que si la garantía debe ser efectuada desde otra ciudad, quien debe correr con los costos de traslado es el cliente.',
                    'Nota: La empresa dejará registro fotográfico de los productos antes de ser enviados a otra ciudad en caso de presentarse algún tipo de anomalía a la hora de efectuarse la entrega; si quienes hacen el transporte de los productos son diferentes empresas transportadoras, ellos son quienes deben responder por las anomalías presentadas al momento de realizar la entrega.',
                    '7. Nuestros clientes deben tener en cuenta que para solicitar la garantía de sus productos deben comunicarse o dirigirse al punto de venta donde hayan adquirido los productos sobre los cuales desean efectuar una garantía. Por consiguiente, dicha solicitud será enviada al coordinador de garantías, donde ya se harán cargo de darle la más pronta respuesta a nuestros clientes sobre la garantía solicitada.',
                    'Nota: Los productos que serán recogidos por el cliente en nuestras instalaciones deberán ser coordinados con 48 horas de anterioridad con la empresa, dejando registro de la cita.',
                ],
            ],
            [
                'id'     => 'colchones_reclamacion',
                'titulo' => 'Colchones y cómo hacer una reclamación',
                'parrafos' => [
                    '8. La garantía de los colchones que manejamos en De Casa Muebles y Decoración es ofrecida por nuestros proveedores de colchones, por lo que se les recuerda a nuestros clientes que los colchones que sean adquiridos en De Casa Muebles y Decoración deben conservar su marquilla a la hora de efectuar una garantía. Nota: recordamos a nuestros clientes que los colchones de la línea económica resisten ciento cuarenta (140) kilos.',
                    'Señor cliente, le informamos que si su producto llega a presentar algún defecto le solicitamos informarnos de manera pronta y oportuna, además de agotar el trámite contenido en el artículo 58 de la Ley 1480 de 2011 —reclamación directa para hacer efectiva la garantía—, la cual será decidida mediante un dictamen técnico y jurídico dentro de un plazo no superior a quince (15) días hábiles contados a partir del día siguiente a la presentación de la reclamación.',
                ],
            ],
            [
                'id'     => 'instructivo_madera',
                'titulo' => 'Instructivo de uso: cuidado de la madera',
                'parrafos' => [
                    'Para De Casa Muebles y Decoración es importante que el producto adquirido por usted tenga una larga duración y que no existan alteraciones en sus características y calidades; sin embargo, esto depende del uso y cuidado que usted le dé a los mismos, teniendo en cuenta que de conformidad con lo establecido en el artículo 16 de la Ley 1480 de 2011 son causales de exoneración de la responsabilidad de la garantía: Numeral I: La fuerza mayor o caso fortuito. Numeral II: El hecho de un tercero. Numeral III: El uso indebido del bien por parte del consumidor. Numeral IV: Que el consumidor no atendió las instrucciones de instalación, uso o mantenimiento indicadas en el manual del producto y en la garantía.',
                    'Por lo tanto, es necesario que usted tenga en cuenta las siguientes instrucciones para cuidado, mantenimiento y conservación del producto. En cuanto al cuidado de la madera que hace parte del producto adquirido por usted, debe tener en cuenta inicialmente que De Casa Muebles y Decoración solamente está llamado a responder por la garantía en casos de broma, desajuste y dilataciones; sin embargo, usted debe tener muy presente que para el cuidado y conservación de la misma deberá seguir las siguientes instrucciones:',
                    'Numeral I: Evitar la exposición constante a rayos solares, humedad y agua.',
                    'Numeral II: Limpiar con una tela suave y baja cantidad de agua.',
                    'Numeral III: No utilizar líquidos o sustancias corrosivas o abrasivas tales como varsol, tíner, límpido (en la limpieza de estoperoles). No usar cubre rasguños en las maderas de color ni en ninguna de las rutinas de limpieza de sus productos.',
                    'Numeral IV: Evitar desarmar o reparar el producto; en caso de defecto deberá agotar el trámite establecido en el artículo 58 de la Ley 1480 de 2011.',
                    'Numeral V: No exponer la madera al contacto con pinturas y/o cubre rasguños, elementos cortopunzantes tales como llaves, cuchillos, bisturí y demás que puedan ocasionar rayones o alteraciones a las condiciones de la misma.',
                ],
            ],
            [
                'id'     => 'instructivo_telas',
                'titulo' => 'Instructivo de uso: telas y accesorios',
                'parrafos' => [
                    'Nota: Todas las telas que se manejan en De Casa Muebles y Decoración son de calidad garantizada por nuestros proveedores; sin embargo, aclaramos a nuestros clientes que las telas de característica antifluido tienen un tiempo de duración específico. Estas telas pierden su propiedad después de varias limpiezas, ya que poseen una capa impermeabilizante la cual se va desgastando según las veces que sea limpiado el mueble.',
                    'Numeral I: Evitar la exposición constante a los rayos solares, ya que los mismos pueden ocasionar deterioro del color y la calidad de la misma.',
                    'Numeral II: Evitar la exposición constante a la humedad o líquido.',
                    'Numeral III: Evitar la exposición al fuego.',
                    'Numeral IV: No utilizar para la limpieza líquidos o sustancias corrosivas tales como límpido, varsol, jabones fuertes y demás que puedan ocasionar daños severos a la misma.',
                    'Numeral V: Limpiar con jabones suaves, agua y una tela que no destiña.',
                    'Numeral VI: Evitar sentarse con ropa que destiñe, ya que esto puede ocasionar alguna mancha.',
                    'Numeral VII: No colocar de manera prolongada muebles u objetos pesados.',
                    'Numeral VIII: No utilizar elementos cortopunzantes sobre la tela.',
                    'Numeral IX: No utilizar pinturas, lapiceros, marcadores ni correctores sobre la tela.',
                    'En cuanto al cuidado y conservación de accesorios decorativos le sugerimos seguir las siguientes instrucciones: Numeral I: Evitar exponerlos de manera constante a los rayos solares. Numeral II: Instalarlos en un sitio firme. Numeral III: Limpiarlos con una tela suave y agua. Numeral IV: Evitar rayones, manchas o caídas.',
                    'Señor cliente, si usted sigue las anteriores instrucciones el producto le durará por un largo periodo de tiempo y conservará su apariencia. Le recordamos que su responsabilidad es desde el día en que recibe a entera satisfacción el mismo.',
                    'Nota: Informamos a nuestros clientes que la empresa no se hace responsable por la suciedad o transparencias que puedan presentarse en los productos fabricados en los tonos 00-01-02, ya que son tonos más claros o crudos ofrecidos en nuestras cartas de telas, puesto que debido al proceso de fabricación y manipulación de las telas es difícil dar un manejo de conservación de la total pulcritud de la tela.',
                ],
            ],
            [
                'id'     => 'pedidos_nuevos',
                'titulo' => 'Anexo informativo sobre pedidos de productos nuevos elaborados',
                'parrafos' => [
                    'En el momento de concretar el negocio y definir el tiempo de fabricación, la empresa le dará 15 días más después de la fecha de entrega pactada para recibir su mueble y ser cancelado en su totalidad; de no ser así, se iniciará un cobro mensual de bodegaje.',
                    'Las telas que la empresa utiliza tienen un rango de costo de acuerdo con la gama; son telas importadas y dependemos de la disponibilidad del proveedor. En caso de agotarse, deberá elegir otra opción existente en el almacén.',
                    'Tener en cuenta que la tonalidad de la pintura puede variar dependiendo de la clase de madera en la que se fabrique el mueble, ya que nuestra técnica permite mostrar las vetas de la madera.',
                    'Una vez cancelado el 50% y definidas las telas, colores y características, no se podrán realizar cambios; de existir alguna solicitud adicional, los días hábiles de entrega serán contados a partir de esta nueva fecha.',
                    'Se recomienda verificar muy bien las medidas de escaleras, puertas y ascensores por donde su mueble va a ser ingresado al momento de la entrega, para que no se presente ninguna anomalía en el ingreso, pues De Casa Muebles y Decoración no se hace responsable.',
                ],
            ],
        ];
    }

    /** El check list del final del documento: cada una se responde sí o no. */
    public static function checklist(): array
    {
        return [
            ['id' => 'proceso_garantias', 'pregunta' => '¿Le quedó claro cómo es el proceso de garantías de Decasa Muebles y Decoración?'],
            ['id' => 'cobertura',         'pregunta' => '¿Fue informado sobre las especificaciones que cubre la garantía?'],
            ['id' => 'garantia_espuma',   'pregunta' => '¿Tuvo información sobre el tiempo de garantía de la espuma?'],
            ['id' => 'entregas',          'pregunta' => '¿Recibió información sobre los tiempos de entrega, condiciones y transporte?'],
        ];
    }

    /** Todo junto, como lo pinta la pantalla. */
    public static function contenido(): array
    {
        return [
            'version'   => self::VERSION,
            'titulo'    => self::TITULO,
            'secciones' => self::secciones(),
            'checklist' => self::checklist(),
        ];
    }
}
