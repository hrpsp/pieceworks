<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_compliance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('eobi_number')->nullable();
            $table->string('pessi_number')->nullable();
            $table->date('eobi_registered_at')->nullable();
            $table->date('pessi_registered_at')->nullable();
            $table->string('ntn_number')->nullable();
            $table->string('tax_status')->nullable();
            $table->boolean('wht_applicable')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_compliance');
    }
};
