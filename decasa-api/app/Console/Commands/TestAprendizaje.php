<?php

namespace App\Console\Commands;

use App\Models\EstimadoIa;
use App\Services\Costos\FewShotProvider;
use Illuminate\Console\Command;

/**
 * Prueba el bucle de aprendizaje del cotizador (AGENT.md, Fase 5) sin gastar una cotización
 * completa: registrar estimado → corrección del ebanista → recuperación por similitud.
 *
 *   php artisan cotizador:test-aprendizaje
 */
class TestAprendizaje extends Command
{
    protected $signature   = 'cotizador:test-aprendizaje';
    protected $description = 'Verifica el ciclo registrar → corregir → recuperar del cotizador';

    public function handle(FewShotProvider $fewShot): int
    {
        $marca = '[TEST-APRENDIZAJE]';
        EstimadoIa::where('input_texto', 'like', "%{$marca}%")->delete();

        $ok = true;

        // 1. La IA cotiza un mueble y estima un costo (aquí simulamos el estimado)
        $this->line('1. Registrando estimado de la IA…');
        $id = $fewShot->registrar(
            "{$marca} cama flotante con luz led 1.60",
            'CAMAS',
            ['componentes' => [['nombre' => 'base', 'materiales' => [], 'mano_obra' => []]]],
            700000,   // la IA subestimó
            false,
            ['ancho' => 160],
        );

        if (! $id) { $this->error('   No se registró el estimado (¿API de embeddings caída?).'); return self::FAILURE; }
        $this->info("   Estimado #{$id} registrado (precio_ia = 700.000).");

        // 2. El ebanista revisa y fija el costo real
        $this->line('2. Registrando corrección del ebanista (costo real = 1.050.000)…');
        $corregido = $fewShot->registrarCorreccion(
            "{$marca} cama flotante con luz led 1.60",
            'CAMAS',
            1050000,
        );

        $estimado = EstimadoIa::find($id);
        if ($corregido && $estimado->precio_humano == 1050000) {
            $this->info("   Corrección aplicada. error_pct = {$estimado->error_pct}% (IA subestimó).");
        } else {
            $this->error('   La corrección no se vinculó al estimado.');
            $ok = false;
        }

        // 3. Se cotiza un mueble PARECIDO → debe recuperar la corrección como ejemplo
        $this->line('3. Cotizando un mueble parecido → ¿recupera la corrección?');
        $ejemplos = $fewShot->ejemplos("{$marca} cama flotante iluminada 1.50", 'CAMAS');

        $recuperado = collect($ejemplos)->firstWhere('precio_correcto', 1050000);
        if ($recuperado) {
            $this->info('   ✅ Recuperó el caso corregido por similitud:');
            $this->line('      ' . json_encode($recuperado, JSON_UNESCAPED_UNICODE));
        } else {
            $this->error('   ❌ No recuperó la corrección. Ejemplos: ' . json_encode($ejemplos, JSON_UNESCAPED_UNICODE));
            $ok = false;
        }

        // 4. Un mueble MUY distinto NO debe traer este ejemplo (umbral de similitud)
        $this->line('4. Cotizando algo muy distinto → NO debe traer la cama…');
        $otros = $fewShot->ejemplos("{$marca} silla de barra metálica", 'SILLAS DE BARRA');
        $filtrado = ! collect($otros)->contains(fn($e) => str_contains($e['input'], 'cama flotante'));
        $filtrado
            ? $this->info('   ✅ El umbral de similitud descartó la cama para una silla.')
            : $this->warn('   ⚠️ La cama apareció para una silla (revisar umbral).');

        // Limpieza
        EstimadoIa::where('input_texto', 'like', "%{$marca}%")->delete();
        $this->line("\nDatos de prueba eliminados.");

        $this->line('');
        $ok ? $this->info('OK — el bucle de aprendizaje funciona (registrar → corregir → recuperar).')
            : $this->error('FALLO — revisar el ciclo de aprendizaje.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
