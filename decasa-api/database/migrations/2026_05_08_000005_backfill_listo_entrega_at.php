<?php

use App\Models\Orden;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Orden::where('estado', 'listo_entrega')
            ->whereNull('listo_entrega_at')
            ->update(['listo_entrega_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Orden::where('estado', 'listo_entrega')
            ->whereNotNull('listo_entrega_at')
            ->update(['listo_entrega_at' => null]);
    }
};
