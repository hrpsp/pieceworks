<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_sku', function (Blueprint $table) {
            $table->id();
            $table->string('style_code', 50)->unique()->comment('Unique style/SKU identifier, e.g. NK-AIR-001');
            $table->string('style_name');
            $table->enum('complexity_tier', ['simple', 'standard', 'complex', 'premium'])->default('standard');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_sku');
    }
};
