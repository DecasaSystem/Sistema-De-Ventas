<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $telas = [
        ['referencia' => 'ADARA 10 HUMO',        'metros_disponibles' => 4,  'color' => 'GRIS',         'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'ADARA ALMENDRA',        'metros_disponibles' => 15, 'color' => 'TAUPE',        'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'ADARA BEIGE',           'metros_disponibles' => 10, 'color' => 'BEIGE',        'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'AISLAND 03',            'metros_disponibles' => 25, 'color' => 'BEIGE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'ALPES GRIS',            'metros_disponibles' => 6,  'color' => 'GRIS',         'textura' => 'SUAVE',       'proveedor' => 'Visual'],
        ['referencia' => 'ANAHI 01 MARFIL',       'metros_disponibles' => 15, 'color' => 'BEIGE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'ANAHI 10 GRIS',         'metros_disponibles' => 20, 'color' => 'GRIS',         'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'ANAHI 11 GRIS HIELO',  'metros_disponibles' => 5,  'color' => 'PLATA',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'ATLAS AZUL',            'metros_disponibles' => 6,  'color' => 'AZUL',         'textura' => 'TERCIOPELO',  'proveedor' => 'Higt Deco'],
        ['referencia' => 'ATLAS LEAD',            'metros_disponibles' => 2,  'color' => 'GRIS',         'textura' => 'SUAVE',       'proveedor' => 'Higt Deco'],
        ['referencia' => 'ATLAS TABACO',          'metros_disponibles' => 6,  'color' => 'CAFE',         'textura' => 'SUAVE',       'proveedor' => 'Higt Deco'],
        ['referencia' => 'BALI 1310',             'metros_disponibles' => 8,  'color' => 'GRIS CLARO',   'textura' => 'BURDA',       'proveedor' => 'Primatela'],
        ['referencia' => 'BALI 1620',             'metros_disponibles' => 1,  'color' => 'BEIGE',        'textura' => 'BURDA',       'proveedor' => 'Primatela'],
        ['referencia' => 'BALI 7020',             'metros_disponibles' => 20, 'color' => 'AZUL',         'textura' => 'BURDA',       'proveedor' => 'Primatela'],
        ['referencia' => 'BISCAYA ARENA',         'metros_disponibles' => 3,  'color' => 'ARENA',        'textura' => 'BURDA',       'proveedor' => 'Visual'],
        ['referencia' => 'BISCAYA GRIS',          'metros_disponibles' => 5,  'color' => 'GRIS',         'textura' => 'BURDA',       'proveedor' => 'Visual'],
        ['referencia' => 'BISTRO BEIGE',          'metros_disponibles' => 4,  'color' => 'BEIGE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'BOHEMIA AVELLANA',      'metros_disponibles' => 3,  'color' => 'BEIGE',        'textura' => 'BURDA',       'proveedor' => 'Higt Deco'],
        ['referencia' => 'BONY 01 MARFIL',        'metros_disponibles' => 30, 'color' => 'MARFIL',       'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'BONY 02 SAND',          'metros_disponibles' => 5,  'color' => 'BEIGE',        'textura' => 'PELUDA',      'proveedor' => 'Arthometextil'],
        ['referencia' => 'BONY 03 BEIGE',         'metros_disponibles' => 20, 'color' => 'CAFE',         'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'BRUM 42 MOSTAZA',       'metros_disponibles' => 25, 'color' => 'MOSTAZA',      'textura' => 'TIPO CUERO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'BURDA MOSTAZA',         'metros_disponibles' => 2,  'color' => 'MOSTAZA',      'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'CAPRI AZUL',            'metros_disponibles' => 16, 'color' => 'AZUL',         'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'CAPRI GRIS',            'metros_disponibles' => 10, 'color' => 'GRIS',         'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'CAPRI PLATA',           'metros_disponibles' => 15, 'color' => 'PLATA',        'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'CAPRIMAX 02 SAND',      'metros_disponibles' => 15, 'color' => 'BEIGE',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'CASTELA BEIGE',         'metros_disponibles' => 3,  'color' => 'BEIGE',        'textura' => 'PELUDA',      'proveedor' => 'Visual'],
        ['referencia' => 'CELINA 00 IVORY',       'metros_disponibles' => 15, 'color' => 'BEIGE',        'textura' => 'LINO',        'proveedor' => 'Arthometextil'],
        ['referencia' => 'CICILY 02 SAND',        'metros_disponibles' => 50, 'color' => 'BEIGE',        'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'CONNOR 11 HIELO',       'metros_disponibles' => 2,  'color' => 'PLATA',        'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'CROIX 01 IVORY',        'metros_disponibles' => 5,  'color' => 'PLATA',        'textura' => 'GRAVADO',     'proveedor' => 'Visual'],
        ['referencia' => 'CROIX TAUPE',           'metros_disponibles' => 4,  'color' => 'TAUPE',        'textura' => 'GRAVADO',     'proveedor' => 'Visual'],
        ['referencia' => 'DIAMANTE AZUL',         'metros_disponibles' => 10, 'color' => 'AZUL OSCURA',  'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'DILAN PLOMO',           'metros_disponibles' => 5,  'color' => 'GRIS OSCURO',  'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'DORIAN 02',             'metros_disponibles' => 6,  'color' => 'BEIGE',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'DORIAN 01 BLANCO',      'metros_disponibles' => 3,  'color' => 'BLANCO',       'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'DOTY HOME 1630',        'metros_disponibles' => 43, 'color' => 'CHASPIADO',    'textura' => 'BUCLE',       'proveedor' => 'Primatela'],
        ['referencia' => 'FALCON 05 OATMEAL',     'metros_disponibles' => 3,  'color' => 'MOCCA',        'textura' => 'PELUDO',      'proveedor' => 'Arthometextil'],
        ['referencia' => 'FALCON GRIS',           'metros_disponibles' => 6,  'color' => 'GRIS',         'textura' => 'PELUDA',      'proveedor' => 'Arthometextil'],
        ['referencia' => 'GANGES TAUPE',          'metros_disponibles' => 3,  'color' => 'TAUPE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Higt Deco'],
        ['referencia' => 'HAMMER GRIS CLARO',     'metros_disponibles' => 15, 'color' => 'PLATA',        'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'HAMMER GRIS OSCURO',    'metros_disponibles' => 5,  'color' => 'GRIS',         'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'HAMMER TAUPE',          'metros_disponibles' => 9,  'color' => 'TAUPE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'HASTON 1300',           'metros_disponibles' => 6,  'color' => 'CAFE MOCCA',   'textura' => 'SUAVE',       'proveedor' => 'Primatela'],
        ['referencia' => 'ITACA 11 HIELO',        'metros_disponibles' => 2,  'color' => 'GRIS',         'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'ITACA VERDE',           'metros_disponibles' => 20, 'color' => 'VERDE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'JESPER 02 SAND',        'metros_disponibles' => 5,  'color' => 'BEIGE',        'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'JESPER 11 HIELO',       'metros_disponibles' => 5,  'color' => 'PLATA',        'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'LAYLA 01 CRUDO',        'metros_disponibles' => 2,  'color' => 'BEIGE',        'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'LAYLA 02 PERLA',        'metros_disponibles' => 28, 'color' => 'BEIGE',        'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'LAYLA 10 GRIS',         'metros_disponibles' => 2,  'color' => 'GRIS',         'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'LAYLA MOCA',            'metros_disponibles' => 5,  'color' => 'TAUPE',        'textura' => 'BUCLE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'LEATHER 1310',          'metros_disponibles' => 40, 'color' => 'TAUPE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Primatela'],
        ['referencia' => 'LINO HOME 0040',        'metros_disponibles' => 10, 'color' => 'PLATA',        'textura' => 'LINO',        'proveedor' => 'Primatela'],
        ['referencia' => 'MARQUEZ 02 SAND',       'metros_disponibles' => 3,  'color' => 'BEIGE',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'MARQUEZ 11 HIELO',      'metros_disponibles' => 15, 'color' => 'GRIS CLARO',   'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'MARQUEZ 12 GRAFITO',    'metros_disponibles' => 3,  'color' => 'GRIS OSCURO',  'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'MARSELLA 0020',         'metros_disponibles' => 3,  'color' => 'PLATA',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'MARSELLA 0030',         'metros_disponibles' => 3,  'color' => 'GRIS',         'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'MARSELLA 0070',         'metros_disponibles' => 3,  'color' => 'GRIS OSCURO',  'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'MATRIX GRIS',           'metros_disponibles' => 5,  'color' => 'GRIS',         'textura' => 'SUAVE',       'proveedor' => 'Visual'],
        ['referencia' => 'MOSHY 0020',            'metros_disponibles' => 47, 'color' => 'PLATA',        'textura' => 'BUCLE',       'proveedor' => 'Primatela'],
        ['referencia' => 'MOSHY 1310',            'metros_disponibles' => 40, 'color' => 'TAUPE',        'textura' => 'BUCLE',       'proveedor' => 'Primatela'],
        ['referencia' => 'NASSAU GRIS',           'metros_disponibles' => 5,  'color' => 'GRIS',         'textura' => 'GRAVADO',     'proveedor' => 'Visual'],
        ['referencia' => 'NASSAU TAUPE',          'metros_disponibles' => 4,  'color' => 'TAUPE',        'textura' => 'GRAVADO',     'proveedor' => 'Visual'],
        ['referencia' => 'NATURA AERO MARFIL',    'metros_disponibles' => 3,  'color' => 'MARFIL',       'textura' => 'SUAVE',       'proveedor' => 'Visual'],
        ['referencia' => 'NATURA AQUA BEIGE',     'metros_disponibles' => 8,  'color' => 'BEIGE',        'textura' => 'SUAVE',       'proveedor' => 'Visual'],
        ['referencia' => 'NATURA AQUA BLANCO',    'metros_disponibles' => 6,  'color' => 'BLANCO',       'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'NATURA AQUA TAUPE',     'metros_disponibles' => 2,  'color' => 'TAUPE',        'textura' => 'SUAVE',       'proveedor' => 'Visual'],
        ['referencia' => 'NEBULOSA 0020',         'metros_disponibles' => 2,  'color' => 'GRIS',         'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'NEVADA BEIGE',          'metros_disponibles' => 3,  'color' => 'BEIGE',        'textura' => 'SUAVE',       'proveedor' => 'Higt Deco'],
        ['referencia' => 'NEVADA TAUPE',          'metros_disponibles' => 5,  'color' => 'TAUPE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Higt Deco'],
        ['referencia' => 'NICKY 14 HIELO',        'metros_disponibles' => 15, 'color' => 'GRIS PLATA',   'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'NIHLO 12 GRAFITO',      'metros_disponibles' => 2,  'color' => 'GRIS OSCURO',  'textura' => 'ACANALADA',   'proveedor' => 'Arthometextil'],
        ['referencia' => 'ODIN 10 GRIS',          'metros_disponibles' => 5,  'color' => 'GRIS',         'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'ODIN 17 NUVO',          'metros_disponibles' => 12, 'color' => 'AGUAMARINA',   'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'ODIN VERDE LIMON',      'metros_disponibles' => 10, 'color' => 'VERDE LIMON',  'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'PADUA 9100',            'metros_disponibles' => 5,  'color' => 'VERDE',        'textura' => 'TIPO CUERO',  'proveedor' => 'Primatela'],
        ['referencia' => 'PELUDA LADRILLO',       'metros_disponibles' => 10, 'color' => 'LADRILLO',     'textura' => 'PELUDA',      'proveedor' => null],
        ['referencia' => 'PERSEO 11 HIELO',       'metros_disponibles' => 3,  'color' => 'PLATA',        'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'PERSEO NAVY',           'metros_disponibles' => 3,  'color' => 'AZUL OSCURO',  'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'PERSEO VERDE',          'metros_disponibles' => 1,  'color' => 'VERDE',        'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'PORLAND ARENA',         'metros_disponibles' => 5,  'color' => 'ARENA',        'textura' => 'SUAVE',       'proveedor' => 'Visual'],
        ['referencia' => 'PORLAND BEIGE',         'metros_disponibles' => 12, 'color' => 'BEIGE',        'textura' => 'SUAVE',       'proveedor' => 'Visual'],
        ['referencia' => 'RENATA 1310',           'metros_disponibles' => 42, 'color' => 'TAUPE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Primatela'],
        ['referencia' => 'RINY 02 SAND',          'metros_disponibles' => 3,  'color' => 'MARFIL',       'textura' => 'BURDA',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'ROLLY TAUPE',           'metros_disponibles' => 5,  'color' => 'TAUPE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Visual'],
        ['referencia' => 'RUNA 15 MINERAL',       'metros_disponibles' => 4,  'color' => 'TAUPE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'SANTORINI 9300',        'metros_disponibles' => 3,  'color' => 'VERDE',        'textura' => 'SUAVE',       'proveedor' => 'Primatela'],
        ['referencia' => 'TELA EXTERIOR GRIS',    'metros_disponibles' => 6,  'color' => 'GRIS CLARO',   'textura' => '',            'proveedor' => 'Arthometextil'],
        ['referencia' => 'TELA EXTERIOR BEIGE',   'metros_disponibles' => 3,  'color' => 'BEIGE',        'textura' => 'PESADA',      'proveedor' => 'Arthometextil'],
        ['referencia' => 'TELA PELUDA',           'metros_disponibles' => 3,  'color' => 'TAUPE',        'textura' => 'PELUDA',      'proveedor' => null],
        ['referencia' => 'TELA TIPO CUERO OCRE',  'metros_disponibles' => 15, 'color' => 'OCRE',         'textura' => 'TIPO CUERO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'TEXAS MUSGO',           'metros_disponibles' => 3,  'color' => 'VERDE',        'textura' => 'DESVANECIDO', 'proveedor' => 'Arthometextil'],
        ['referencia' => 'TEYLOR 11 GRIS HIELO', 'metros_disponibles' => 5,  'color' => 'PLATA',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'TEYLOR 22 AZUL',        'metros_disponibles' => 8,  'color' => 'AZUL',         'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'TEYLOR 87 SKY',         'metros_disponibles' => 10, 'color' => 'AZUL',         'textura' => 'LINO',        'proveedor' => 'Arthometextil'],
        ['referencia' => 'TIPO CUERO GRIS',       'metros_disponibles' => 3,  'color' => 'GRIS',         'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'UYUNI 68 VERDE',        'metros_disponibles' => 3,  'color' => 'VERDE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'UYUNI 75 OCEAN',        'metros_disponibles' => 3,  'color' => 'AZUL',         'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'VERDI 01 BEIGE',        'metros_disponibles' => 3,  'color' => 'BEIGE',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'VERDI 09 NEGRO',        'metros_disponibles' => 2,  'color' => 'NEGRO',        'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'VICTORIA 56 INDIGO',    'metros_disponibles' => 3,  'color' => 'AZUL',         'textura' => 'SUAVE',       'proveedor' => 'Arthometextil'],
        ['referencia' => 'VIENA BEIGE',           'metros_disponibles' => 1,  'color' => 'BEIGE',        'textura' => 'BUCLE',       'proveedor' => 'Visual'],
        ['referencia' => 'VIENA TAUPE',           'metros_disponibles' => 3,  'color' => 'TAUPE',        'textura' => 'PELUDA',      'proveedor' => 'Visual'],
        ['referencia' => 'ZAID 02 SAND',          'metros_disponibles' => 4,  'color' => 'BEIGE',        'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'ZAREN GUABA',           'metros_disponibles' => 15, 'color' => 'PALO DE ROSA', 'textura' => 'TERCIOPELO',  'proveedor' => 'Arthometextil'],
        ['referencia' => 'ZARINA MARFIL',         'metros_disponibles' => 10, 'color' => 'MARFIL',       'textura' => 'TERCIOPELO',  'proveedor' => 'Higt Deco'],
    ];

    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->telas as $t) {
            $marca = $t['proveedor'] ?? 'Sin proveedor';
            $rows[] = [
                'marca'              => $marca,
                'tipo'               => $t['referencia'],   // la referencia actúa como tipo
                'color'              => $t['color'],
                'referencia'         => $t['referencia'],
                'textura'            => $t['textura'] ?: null,
                'activo'             => true,
                'metros_disponibles' => $t['metros_disponibles'],
                'metros_reservados'  => 0,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        // insertOrIgnore respeta el unique (marca, tipo, color)
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('catalogo_telas')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        $refs = array_column($this->telas, 'referencia');
        DB::table('catalogo_telas')->whereIn('referencia', $refs)->delete();
    }
};
