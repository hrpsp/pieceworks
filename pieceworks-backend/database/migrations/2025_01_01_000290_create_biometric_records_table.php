<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->string('device_id');
            $table->timestamp('punch_time');
            $table->enum('punch_type', ['in', 'out']);
            $table->boolean('synced_from_timbridge')->default(false);
            $table->timestamps();

            $table->index(['worker_id', 'punch_time']);
            $table->index(['device_id', 'punch_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_records');
    }
};
