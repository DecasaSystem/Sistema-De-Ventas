<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) return;

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->string('endpoint', 500);
            $table->text('p256dh');
            $table->text('auth_token');
            $table->timestamps();
            $table->unique(['usuario_id', 'endpoint'], 'unique_user_endpoint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
