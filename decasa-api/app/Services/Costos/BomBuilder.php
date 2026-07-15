<?php

namespace App\Services\Costos;

use OpenAI\Laravel\Facades\OpenAI;

/**
 * Construye la receta (BOM) de un mueble con el LLM.
 *
 * El modelo hace lo que sabe hacer: leer la foto y el texto, descomponer el mueble en
 * componentes y decidir QUÉ materiales lleva, CUÁNTAS unidades de cada uno y CUÁNTAS
 * horas de cada oficio. Devuelve identificadores y cantidades — nunca precios.
 * La aritmética la hace CostoCalculator.
 */
class BomBuilder
{
    /** Cargos válidos — deben existir en salarios_cargo */
    private const CARGOS = ['carpintero', 'tapicero', 'costurera', 'lacador'];

    /**
     * @param string      $contexto    Descripción del trabajo a cotizar.
     * @param array       $referencia  Fichas e items similares (datos reales de la BD).
     * @param array       $candidatos  Materiales elegibles: [{id, nombre, unidad}].
     * @param string|null $bocetoUrl   Foto o boceto del mueble.
     */
    public function construir(
        string $contexto,
        array $referencia,
        array $candidatos,
        ?string $bocetoUrl = null,
        array $ejemplos = [],
    ): array {
        $systemPrompt = $this->systemPrompt();

        $contenido = $contexto
            . "\n\nMATERIALES DEL CATÁLOGO QUE PUEDES USAR (elige material_id de esta lista, y solo de esta lista):\n"
            . json_encode($candidatos, JSON_UNESCAPED_UNICODE)
            . "\n\nFICHAS TÉCNICAS DE REFERENCIA (muebles reales de Decasa con sus cantidades y horas reales):\n"
            . json_encode($referencia, JSON_UNESCAPED_UNICODE);

        // Aprendizaje: correcciones reales de ebanistas sobre muebles parecidos (AGENT.md, Fase 5)
        if (! empty($ejemplos)) {
            $contenido .= "\n\nCORRECCIONES DE EBANISTAS EN MUEBLES PARECIDOS (el precio_correcto es el que"
                . " fijó un ebanista tras revisar el estimado de la IA — úsalos para calibrar tu receta,"
                . " especialmente las cantidades de material y las horas):\n"
                . json_encode($ejemplos, JSON_UNESCAPED_UNICODE);
        }

        $mensajeUsuario = $bocetoUrl
            ? [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $contenido],
                    // 'high': con 'low' la imagen se reduce a 512×512 y se pierde el detalle
                    // necesario para contar cajones o juzgar proporciones (AGENT.md, Fase 4).
                    ['type' => 'image_url', 'image_url' => ['url' => $bocetoUrl, 'detail' => 'high']],
                ],
            ]
            : ['role' => 'user', 'content' => $contenido];

        $payload = [
            'model'           => config('openai.model', 'gpt-4o'),
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                $mensajeUsuario,
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        // Reintento con backoff: un rate limit puntual no debe degradar la cotización a
        // "requiere revisión" — eso debe reservarse para cuando el modelo no logra armar
        // una receta con materiales del catálogo.
        $ultimoError = null;

        for ($intento = 0; $intento < 3; $intento++) {
            try {
                $response = OpenAI::chat()->create($payload);
                $bom = json_decode($response->choices[0]->message->content ?? '{}', true) ?? [];

                return $this->sanear($bom);
            } catch (\Throwable $e) {
                $ultimoError = $e->getMessage();

                $recuperable = str_contains(mb_strtolower($ultimoError), 'rate limit')
                            || str_contains(mb_strtolower($ultimoError), 'timeout')
                            || str_contains($ultimoError, '429')
                            || str_contains($ultimoError, '503');

                if (! $recuperable || $intento === 2) break;

                sleep(2 ** $intento); // 1s, 2s
            }
        }

        \Log::error('BomBuilder', ['err' => $ultimoError]);

        return ['componentes' => [], 'supuestos' => [], 'consultar' => [], 'error' => $ultimoError];
    }

    /**
     * Descarta cualquier cosa que el modelo haya inventado fuera del contrato:
     * campos de precio, cargos desconocidos, cantidades no numéricas.
     */
    private function sanear(array $bom): array
    {
        $componentes = [];

        foreach (($bom['componentes'] ?? []) as $comp) {
            if (! is_array($comp)) continue;

            $materiales = [];
            foreach (($comp['materiales'] ?? []) as $m) {
                if (! is_array($m) || empty($m['material_id']) || ! isset($m['cantidad'])) continue;
                if (! is_numeric($m['material_id']) || ! is_numeric($m['cantidad'])) continue;

                $materiales[] = [
                    'material_id' => (int) $m['material_id'],
                    'cantidad'    => (float) $m['cantidad'],
                    'nota'        => isset($m['nota']) ? (string) $m['nota'] : null,
                ];
            }

            $manoObra = [];
            foreach (($comp['mano_obra'] ?? []) as $mo) {
                if (! is_array($mo) || empty($mo['cargo']) || ! isset($mo['horas'])) continue;
                if (! is_numeric($mo['horas'])) continue;

                $cargo = mb_strtolower(trim((string) $mo['cargo']));
                if (! in_array($cargo, self::CARGOS, true)) continue;

                $manoObra[] = [
                    'cargo'   => $cargo,
                    'horas'   => (float) $mo['horas'],
                    'proceso' => isset($mo['proceso']) ? (string) $mo['proceso'] : null,
                ];
            }

            if (empty($materiales) && empty($manoObra)) continue;

            $componentes[] = [
                'nombre'     => isset($comp['nombre']) ? (string) $comp['nombre'] : '',
                'materiales' => $materiales,
                'mano_obra'  => $manoObra,
            ];
        }

        return [
            'componentes' => $componentes,
            'supuestos'   => array_values(array_filter((array) ($bom['supuestos'] ?? []), 'is_string')),
            'consultar'   => array_values(array_filter((array) ($bom['consultar'] ?? []), 'is_string')),
        ];
    }

    private function systemPrompt(): string
    {
        $cargos = implode(', ', self::CARGOS);

        return <<<EOT
Eres el ebanista jefe de Decasa (muebles, Colombia). Tu trabajo es descomponer un mueble en su
RECETA DE FABRICACIÓN: qué materiales lleva, cuántas unidades de cada uno, y cuántas horas de
cada oficio.

NO CALCULAS PRECIOS. Nunca escribas un precio, un subtotal ni un total. El sistema calcula el
costo tomando los precios de la base de datos. Si devuelves un precio, se descarta.

Devuelve ÚNICAMENTE un JSON válido con esta estructura exacta:
{
  "componentes": [
    {
      "nombre": "string — nombre del componente (ej: 'Base cama 140×190', 'Módulo escritorio')",
      "materiales": [
        { "material_id": <entero de la lista de candidatos>, "cantidad": <número>, "nota": "string breve" }
      ],
      "mano_obra": [
        { "cargo": "<uno de: {$cargos}>", "proceso": "string (ej: esqueleteria_cama, tapizado, laca)", "horas": <número> }
      ]
    }
  ],
  "supuestos": ["string — todo lo que asumiste (medidas estimadas, material no especificado, etc.)"],
  "consultar": ["string — elementos visibles o ambiguos que NO incluiste en la receta"]
}

REGLAS DEL BOM
- material_id DEBE existir en la lista de candidatos que te doy. Si el material que necesitas no
  está en la lista, elige el más parecido que sí esté y decláralo en "supuestos".
- cantidad va SIEMPRE en la unidad que trae el material en la lista de candidatos (lamina, metro,
  tabla, tira, botella, juego, unidad, pulgada…). Si el material se vende por 'lamina' y usas media
  lámina → 0.5. No conviertas unidades por tu cuenta.
- Si la unidad de un material es 'otro', su unidad real es ambigua: úsalo solo si es imprescindible
  y declara en "supuestos" qué asumiste que significa.
- cargo DEBE ser uno de: {$cargos}. No inventes otros.
- horas: usa las fichas de referencia como calibración. Ahí verás cuántas horas reales toma cada
  oficio en muebles equivalentes. No inventes horas si tienes una ficha parecida.
- Incluye SIEMPRE los consumibles que las fichas de referencia muestran (pegante, puntillas,
  tornillos, deslizadores). Son baratos pero forman parte del costo real.

CÓMO CONSTRUIR LA RECETA — MÉTODO OBLIGATORIO
No inventes la receta desde cero. Trabaja SIEMPRE por diferencias contra las fichas de referencia,
que son muebles reales que Decasa ya fabrica:
1. Elige la ficha de referencia más parecida al mueble pedido.
2. COPIA su lista de materiales y sus horas de mano de obra tal cual. Ese es tu punto de partida.
3. Ajusta SOLO lo que el cliente pidió distinto: si es más grande, sube las cantidades de material
   en proporción al área o al volumen; si cambia la tela, cambia la tela; si quita el brazo, quita
   ese material y esas horas.
4. Todo lo que el cliente NO mencionó se queda EXACTAMENTE como en la ficha de referencia.

Este método importa: las fichas ya contienen las cantidades reales que usa el taller. Un estimado
que se aleja mucho de su ficha de referencia casi siempre está mal — el sistema lo detecta y lo
manda a revisión del ebanista, así que no ganas nada inventando.
- Si el mueble pedido ES prácticamente una ficha de referencia (mismo tipo y medidas), devuelve su
  receta tal cual, sin "mejorarla".
- Mueble HÍBRIDO (combina funciones: cama+escritorio, cajonero+librero): crea UN COMPONENTE POR
  FUNCIÓN y suma los materiales y horas de cada uno por separado.
- Distingue lo que Decasa FABRICA (carpintería, tapizado, lacado) de lo que COMPRA YA HECHO
  (colchón, vidrio, espejo, patas metálicas, herrajes). Lo comprado va en "materiales", nunca en
  "mano_obra".
- Elementos visibles pero ambiguos (colchón en la foto de una cama, vidrio en una mesa) NO los
  incluyas: van en "consultar".

REGLAS DE NEGOCIO DECASA
- Los comedores (mesas) y las sillas se fabrican y venden SIEMPRE por separado. Si piden un
  "comedor 6 puestos", la receta es SOLO la mesa. Nunca incluyas sillas.
- Los productos se fabrican a pedido, no hay piezas terminadas en stock:
  · Cambiar solo la TELA: la estructura se fabrica idéntica (mismas horas de carpintero). Solo
    cambian el material de tela y las horas de tapicero.
  · Cambiar MEDIDAS ligeramente (±20%): se fabrica con esas medidas. Cambia el material
    (más/menos madera y tela según el área); las horas varían poco.
  · Cambiar MEDIDAS drásticamente (>30%): escala materiales y horas según el área/volumen.
- SOLO RETAPIZADO sobre un mueble ya fabricado (el contexto lo dirá): el mueble YA EXISTE. La
  receta lleva ÚNICAMENTE la tela/espuma y las horas de tapicero para quitar lo viejo e instalar
  lo nuevo (aprox 3-6 h según tamaño). SIN madera, SIN herrajes, SIN carpintero, SIN laca.
- SOLO AJUSTE DE ALTURA/MEDIDAS sobre un mueble existente: solo el material mínimo del ajuste y
  1-2 h de carpintero.
- Notas del cliente como "sin brazos", "sin cabecero", "patas más cortas" son SIMPLIFICACIONES:
  reducen material y horas. Bájalos en la receta.

Devuelve SOLO el JSON.
EOT;
    }
}
