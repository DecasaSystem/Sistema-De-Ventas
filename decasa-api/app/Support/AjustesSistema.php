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

    /** clave => valor de fábrica */
    private const DEFINICION = [
        self::CALIFICAR_TALLER           => true,
        self::DECLARACION_ESPECIALES     => true,
        self::DECLARACION_PERSONALIZADOS => false,
    ];

    public static function activo(string $clave): bool
    {
        $valor = Configuracion::valor($clave, array_keys(self::DEFINICION));

        return $valor === null ? self::DEFINICION[$clave] : $valor === '1';
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
        ];
    }

    public static function calificaTaller(): bool
    {
        return self::activo(self::CALIFICAR_TALLER);
    }
}
