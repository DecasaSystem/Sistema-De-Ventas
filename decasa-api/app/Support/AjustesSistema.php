<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Interruptores del sistema que se manejan desde Gestión → Ajustes.
 *
 * Cada uno es una fila de `configuracion` con '1' o '0'. Sin fila vale lo de
 * fábrica, que es como el sistema funcionaba antes de existir el interruptor:
 * así una base sin tocar no cambia de comportamiento por subir el código.
 *
 * Para agregar uno: una entrada en DEFINICION y quien lo respete en el
 * backend (no basta con esconderlo en la pantalla).
 */
class AjustesSistema
{
    /**
     * Pedir tiempo, estrellas y opinión de cada trabajador al terminar un
     * paso de Producción (dueño, 2026-10-16: "que se pueda apagar lo de las
     * puntuaciones"). Apagado, al terminar el paso solo se dice quién lo hizo.
     * No toca plata: las bonificaciones de Nómina se cargan a mano
     * (`nomina_producciones`) y no leen las calificaciones.
     */
    public const CALIFICAR_TALLER = 'produccion_calificar_trabajadores';

    /**
     * Pedirle al cliente la declaración de diseños y medidas especiales
     * (DeclaracionEspeciales) en lo que no está en el catálogo y se fabrica
     * desde cero. Dueño, 2026-10-21: "de eso sí estoy seguro". Prendido.
     */
    public const DECLARACION_ESPECIALES = 'declaracion_disenos_especiales';

    /**
     * La misma declaración en lo personalizado (un producto del catálogo al
     * que se le cambian detalles). El dueño no estaba seguro: "que sea
     * activable". Apagado hasta que lo prendan.
     */
    public const DECLARACION_PERSONALIZADOS = 'declaracion_personalizados';

    /**
     * Con cuántos días de anticipación avisa AlertarRetrasoProduccion que una
     * pieza del taller se vence ("Entrega próxima"). Antes eran 3, 2 y 1 día
     * fijos; el dueño (2026-11-03): "solo notifique cuando la entrega sea en 1
     * día porque se llena mucho… en 3 días o en 2 no las veo tan necesarias;
     * que se pueda personalizar". Se guarda como '1,2' (vacío = ninguno). El
     * aviso del mismo día ("Entrega hoy") no depende de esto.
     */
    public const DIAS_AVISO_ENTREGA = 'avisar_entrega_dias_antes';

    /**
     * Vender la medida con la tela por definir ("1.40 · tela por definir") y
     * frenar el despacho hasta que alguien la escoja con "Definir tela".
     * Dueño, 2026-11-06: en Decasa casi siempre la tela se anota en las notas,
     * y hay productos importados (sillas Nube: cuero o tela) donde no hay
     * tela que escoger; "no quitarlo, pero poderlo desactivar" (otra empresa
     * sí lo usaría). Apagado: la medida se vende sola, sin aviso ni botón, y
     * se despacha como cualquier producto. Prendido es como funcionaba antes.
     */
    public const PEDIR_TELA = 'ventas_tela_por_definir';

    /** Los días que se pueden escoger en la pantalla. */
    public const DIAS_AVISO_POSIBLES = [1, 2, 3];

    private const DIAS_AVISO_FABRICA = [1];

    /** clave => valor de fábrica */
    private const DEFINICION = [
        self::CALIFICAR_TALLER           => true,
        self::DECLARACION_ESPECIALES     => true,
        self::DECLARACION_PERSONALIZADOS => false,
        self::PEDIR_TELA                 => true,
    ];

    public static function activo(string $clave): bool
    {
        $valor = Configuracion::valor($clave, self::clavesJuntas());

        return $valor === null ? self::DEFINICION[$clave] : $valor === '1';
    }

    /** @return int[] ordenados de menor a mayor */
    public static function diasAvisoEntrega(): array
    {
        // Las pruebas del taller montan el esquema a mano y a veces sin `configuracion`.
        if (! Esquema::tabla('configuracion')) return self::DIAS_AVISO_FABRICA;

        $valor = Configuracion::valor(self::DIAS_AVISO_ENTREGA, self::clavesJuntas());
        if ($valor === null) return self::DIAS_AVISO_FABRICA;

        return self::limpiarDias(explode(',', $valor));
    }

    public static function definirDiasAvisoEntrega(array $dias): void
    {
        DB::table('configuracion')->updateOrInsert(
            ['clave' => self::DIAS_AVISO_ENTREGA],
            ['valor' => implode(',', self::limpiarDias($dias))],
        );
        Configuracion::olvidar();
    }

    /** Solo los días permitidos, sin repetir; un valor raro en la tabla no rompe la tarea. */
    private static function limpiarDias(array $dias): array
    {
        $dias = array_values(array_unique(array_filter(
            array_map(fn ($d) => (int) trim((string) $d), $dias),
            fn ($d) => in_array($d, self::DIAS_AVISO_POSIBLES, true),
        )));
        sort($dias);

        return $dias;
    }

    private static function clavesJuntas(): array
    {
        return [...array_keys(self::DEFINICION), self::DIAS_AVISO_ENTREGA];
    }

    public static function definir(string $clave, bool $activo): void
    {
        DB::table('configuracion')->updateOrInsert(
            ['clave' => $clave],
            ['valor' => $activo ? '1' : '0'],
        );
        Configuracion::olvidar();
    }

    /** Todos, como los lee la pantalla. */
    public static function todos(): array
    {
        return [
            'calificar_trabajadores_taller' => self::activo(self::CALIFICAR_TALLER),
            'declaracion_disenos_especiales' => self::activo(self::DECLARACION_ESPECIALES),
            'declaracion_personalizados'     => self::activo(self::DECLARACION_PERSONALIZADOS),
            'avisar_entrega_dias_antes'      => self::diasAvisoEntrega(),
            'tela_por_definir'               => self::pideTela(),
        ];
    }

    /**
     * ¿Se exige definir la tela de lo que se vendió solo por la medida?
     * Las pruebas de entregas montan el esquema a mano y a veces sin
     * `configuracion`: ahí vale lo de fábrica (prendido).
     */
    public static function pideTela(): bool
    {
        if (! Esquema::tabla('configuracion')) return true;

        return self::activo(self::PEDIR_TELA);
    }

    public static function calificaTaller(): bool
    {
        return self::activo(self::CALIFICAR_TALLER);
    }
}
