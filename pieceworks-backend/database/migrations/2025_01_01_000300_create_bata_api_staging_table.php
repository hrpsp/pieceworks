<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bata_api_staging', function (Blueprint $table) {
            $table->id();
            $table->string('external_worker_id');
            $table->foreignId('pieceworks_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->foreignId('line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('style_code');
            $table->string('operation');
            $table->unsignedInteger('pairs_completed')->default(0);
            $table->unsignedInteger('pairs_rejected')->default(0);
            $table->date('work_date');
            $table->enum('shift', ['morning', 'afternoon', 'night']);
            $table->json('raw_payload');
            $table->enum('source_tag', ['bata_api'])->default('bata_api');
            $table->enum('validation_status', ['clean', 'warning', 'error', 'held', 'mapped'])->default('held');
            $table->json('validation_errors')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamps();

            $table->index(['work_date', 'validation_status']);
            $table->index(['external_worker_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bata_api_staging');
    }
};
