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
            ['clave' => 'catalogo_bases_comedores',  'valor' => 'https://drive.google.com/uc?export=download&id=1y-jP31eh0c_1pAt26ie672mOaVJy3KGM'],
            ['clave' => 'catalogo_cajoneros_bifes',  'valor' => 'https://drive.google.com/uc?export=download&id=1gWU48EKvn21qLGVEbfbG9ztdUoP5WAeu'],
            ['clave' => 'catalogo_camas',            'valor' => 'https://drive.google.com/uc?export=download&id=1HQQNkfFUyiyFrPJJhTrizdlqkQctgS_q'],
            ['clave' => 'catalogo_mesas_auxiliares', 'valor' => 'https://drive.google.com/uc?export=download&id=1Y0MNc2eE9z095f46CfVs5j7fpnVW4UWl'],
            ['clave' => 'catalogo_mesas_centro',     'valor' => 'https://drive.google.com/uc?export=download&id=1DO7aAWXlfnaa7SL9f_0sd0uRYnPHIEUU'],
            ['clave' => 'catalogo_mesas_noche',      'valor' => 'https://drive.google.com/uc?export=download&id=16Zghf2D0GSv2Cq3X0D21rV3vEgqClM83'],
            ['clave' => 'catalogo_mesas_tv',         'valor' => 'https://drive.google.com/uc?export=download&id=1S7rPC3CQzLvUXP2w6NWBdkwSqGsC6'],
            ['clave' => 'catalogo_sillas_auxiliares','valor' => 'https://drive.google.com/uc?export=download&id=1PeYDmSVxSiuReB_mnm3bsUC6YgOpvaNa'],
            ['clave' => 'catalogo_sillas_barra',     'valor' => 'https://drive.google.com/uc?export=download&id=1zCLqZnRJXc7lsZBMsJrHcJ1duLSheDnG'],
            ['clave' => 'catalogo_sofas',            'valor' => 'https://drive.google.com/uc?export=download&id=1Y9VXHrmzuAw_oIru-B8ywxgWPP9K-SCd'],
            ['clave' => 'catalogo_sofas_camas',      'valor' => 'https://drive.google.com/uc?export=download&id=1SLEOyhsSOGBFGZUPhoyH-B8ywxgWPP9K-SCd'],
            ['clave' => 'catalogo_sofas_modulares',  'valor' => 'https://drive.google.com/uc?export=download&id=1Y9VXHrmzuAw_oIru-B8ywxgWPP9K-SCd'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
