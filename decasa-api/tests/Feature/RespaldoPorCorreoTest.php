<?php

namespace Tests\Feature;

use App\Console\Commands\RespaldarBaseDatos;
use Tests\TestCase;

/**
 * La copia de seguridad que llega al correo.
 *
 * Brevo, por donde salen los correos, rechaza los adjuntos .gz ("Unsupported
 * file format: gz") y la copia diaria dejó de llegar (octubre 2026). Va en
 * .zip, que está en su lista de permitidos, y el .sql de adentro tiene que ser
 * exactamente el que se volcó.
 *
 * El volcado en sí (SHOW FULL TABLES, SHOW CREATE TABLE) es de MySQL y no corre
 * en SQLite: aquí se prueba el empaque, que es lo que se rompió.
 */
class RespaldoPorCorreoTest extends TestCase
{
    public function test_el_respaldo_va_en_un_zip_que_trae_el_sql_entero(): void
    {
        $sql = "-- Copia de seguridad de Decasa\nINSERT INTO `ordenes` VALUES ('ñandú', 'ü');\n"
             . str_repeat("INSERT INTO `pagos` VALUES (1, 150000.00);\n", 5000);

        $enZip = (new \ReflectionMethod(RespaldarBaseDatos::class, 'enZip'))->getClosure(new RespaldarBaseDatos());
        [$bytes, $nombre] = $enZip($sql);

        $this->assertStringEndsWith('.zip', $nombre);
        $this->assertStringStartsWith("PK\x03\x04", $bytes, 'es un zip de verdad, no un .gz renombrado');
        $this->assertLessThan(strlen($sql) / 5, strlen($bytes), 'va comprimido');

        $tmp = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame(substr($nombre, 0, -4) . '.sql', $zip->getNameIndex(0));
        $this->assertSame($sql, $zip->getFromIndex(0), 'el .sql de adentro es idéntico al volcado');
        $zip->close();
        @unlink($tmp);
    }
}
