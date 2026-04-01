<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_sync_log', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type');
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_clean')->default(0);
            $table->unsignedInteger('records_held')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['sync_type', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_sync_log');
    }
};
