<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_id_mapping', function (Blueprint $table) {
            $table->id();
            $table->string('external_worker_id');
            $table->foreignId('pieceworks_worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('source_system');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['external_worker_id', 'source_system']);
            $table->index('pieceworks_worker_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_id_mapping');
    }
};
