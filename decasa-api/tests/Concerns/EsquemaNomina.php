<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El esquema de Nómina montado a mano para SQLite (el historial de
 * migraciones no corre ahí), con las columnas que leen la liquidación, el
 * costo del empleador y las prestaciones.
 */
trait EsquemaNomina
{
    protected function montarEsquemaNomina(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->string('arquetipo')->nullable();
        });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('cedula')->nullable();
            $t->string('rol')->nullable(); $t->unsignedBigInteger('rol_id')->nullable();
            $t->boolean('activo')->default(true); $t->boolean('no_usa_programa')->default(false);
            $t->boolean('acceso_nomina')->default(false);
            $t->unsignedBigInteger('nomina_sueldo_id')->nullable(); $t->date('nomina_desde')->nullable();
            $t->unsignedBigInteger('nomina_bonificacion_id')->nullable();
            $t->boolean('nomina_auxilio')->default(true); $t->boolean('nomina_seguridad_social')->default(true);
            $t->string('periodicidad')->default('quincenal');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('nomina_sueldos', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->decimal('valor', 12, 2); $t->string('unidad')->default('dia');
            $t->decimal('horas_dia', 5, 2)->default(8); $t->decimal('valor_auxilio_mes', 12, 2)->default(0);
            $t->decimal('valor_seguridad_social_mes', 12, 2)->default(0); $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::create('nomina_bonificaciones', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('periodo')->nullable(); $t->decimal('tope', 12, 2)->nullable();
            $t->boolean('tope_activo')->default(false); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_bonificacion_metas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('nomina_bonificacion_id'); $t->decimal('desde', 12, 2);
            $t->decimal('hasta', 12, 2)->nullable(); $t->decimal('monto', 12, 2); $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::create('nomina_pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('periodicidad');
            $t->date('fecha_inicio'); $t->date('fecha_fin'); $t->string('sueldo_nombre')->nullable();
            foreach (['valor_dia', 'valor_hora', 'horas_dia', 'dias', 'subtotal', 'descuento_faltas', 'valor_auxilio_dia',
                      'descuento_incapacidad', 'auxilio_transporte', 'valor_seguridad_social_dia',
                      'descuento_seguridad_social', 'total_ajustes', 'produccion_total', 'bonificacion', 'total'] as $c) {
                $t->decimal($c, 12, 2)->default(0);
            }
            $t->string('bonificacion_nombre')->nullable(); $t->string('bonificacion_detalle')->nullable();
            $t->decimal('costo_empleador', 12, 2)->nullable(); $t->json('costo_empleador_detalle')->nullable();
            $t->text('observaciones')->nullable(); $t->timestamp('pagado_at')->nullable(); $t->timestamps();
            $t->unique(['usuario_id', 'fecha_inicio']);
        });
        foreach (['nomina_ausencias', 'nomina_ajustes', 'nomina_producciones'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('nomina_pago_id')->nullable();
                $t->date('fecha'); $t->string('tipo')->nullable(); $t->decimal('horas', 5, 2)->default(0);
                $t->string('motivo')->nullable(); $t->string('nombre')->nullable(); $t->decimal('monto', 12, 2)->default(0);
                $t->string('concepto')->nullable(); $t->decimal('valor_unitario', 12, 2)->default(0);
                $t->decimal('cantidad', 12, 2)->default(0); $t->decimal('total', 12, 2)->default(0);
                $t->timestamps();
            });
        }
        Schema::create('nomina_prestamos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->string('motivo')->nullable(); $t->decimal('monto', 12, 2);
            $t->integer('cuotas')->default(1); $t->decimal('valor_cuota', 12, 2)->default(0); $t->date('fecha')->nullable();
            $t->unsignedBigInteger('creado_por')->nullable(); $t->boolean('activo')->default(true); $t->timestamps();
        });
        Schema::create('nomina_prestamo_cuotas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('prestamo_id'); $t->unsignedBigInteger('nomina_pago_id')->nullable();
            $t->decimal('monto', 12, 2); $t->date('fecha')->nullable(); $t->timestamps();
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor'); $t->timestamp('updated_at')->nullable();
        });
    }
}
