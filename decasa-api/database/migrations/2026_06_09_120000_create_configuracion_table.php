<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion', function (Blueprint $table) {
            $table->string('clave', 100)->primary();
            $table->text('valor');
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
        });

        DB::table('configuracion')->insert([
            ['clave' => 'empresa',      'valor' => 'DeCasa'],
            ['clave' => 'descripcion',  'valor' => 'Especialistas en fabricacion del hogar y mobiliario. Ofrecemos productos de alta calidad para hacer de tu casa un hogar.'],
            ['clave' => 'horario',      'valor' => 'Lunes a viernes de 8am a 5pm'],
            ['clave' => 'ubicacion',    'valor' => 'Disponible en Armenia, Quindio. Tiendas: Av. Bolivar #16 N26, Km 2 via El Eden, Km 1 via Jardines'],
            ['clave' => 'contacto',     'valor' => 'Escribenos para mas informacion o cotizaciones sin compromiso'],
            ['clave' => 'instagram',    'valor' => '@muebles_decasa'],
            ['clave' => 'servicios',    'valor' => '["Decoracion de casas", "Fabricacion de muebles", "Comedores", "Mesas", "Sillas", "Muebles personalizados bajo diseño del cliente"]'],
            ['clave' => 'catalogo_bases_comedores',  'valor' => 'https://drive.google.com/uc?export=download&id=17lq-M_ULY-YF09YIeQ5tU-l7KlORd-w3'],
            ['clave' => 'catalogo_cajoneros_bifes',  'valor' => 'https://drive.google.com/uc?export=download&id=11yuPE0YTu-HFevhirY2Qt3n1CP5_zfT7'],
            ['clave' => 'catalogo_camas',            'valor' => 'https://drive.google.com/uc?export=download&id=1C82qnu7M79qj1KJArk_3pk1o79Zjp1Qy'],
            ['clave' => 'catalogo_colchones',        'valor' => 'https://drive.google.com/uc?export=download&id=1AtMS33rdTEc2NGIGy3jDHLV2W9qBrYmS'],
            ['clave' => 'catalogo_escritorios',      'valor' => 'https://drive.google.com/uc?export=download&id=1TDgaEagtNyb1ZQI_7Lov4dQJNWrDyJTX'],
            ['clave' => 'catalogo_mesas_auxiliares', 'valor' => 'https://drive.google.com/uc?export=download&id=1zjvULRwuEK5MQTKqUzfWL7BdCVXaYpXH'],
            ['clave' => 'catalogo_mesas_centro',     'valor' => 'https://drive.google.com/uc?export=download&id=1r1Z6yIdyfcWJlzolsOwy_d9zLTWe7CI8'],
            ['clave' => 'catalogo_mesas_noche',      'valor' => 'https://drive.google.com/uc?export=download&id=1BB5HgAanL3rZobWoH6vqNM3D_8JoZkR2'],
            ['clave' => 'catalogo_mesas_tv',         'valor' => 'https://drive.google.com/uc?export=download&id=1BrtzrVdFd-KDxBITkvfUk1WVqt3wpkCk'],
            ['clave' => 'catalogo_sillas_auxiliares','valor' => 'https://drive.google.com/uc?export=download&id=1qK1K8sdvySt3NS3mnFO_S7hM_HR5s_l2'],
            ['clave' => 'catalogo_sillas_barra',     'valor' => 'https://drive.google.com/uc?export=download&id=1B1_V9bcXtwowEPpA2uo9bLPahcjUOM5n'],
            ['clave' => 'catalogo_sofas_camas',      'valor' => 'https://drive.google.com/uc?export=download&id=1KkUYihY2xUazKgyUWy-gnBocT-PfctdE'],
            ['clave' => 'catalogo_sofas_modulares',  'valor' => 'https://drive.google.com/uc?export=download&id=1p-zOPgYhytU9AhyRjAfy0oRNokXnY4pI'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
