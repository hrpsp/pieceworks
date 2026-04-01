<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('holiday_date');
            $table->enum('province', ['federal', 'punjab', 'sindh', 'kpk', 'balochistan', 'all'])
                  ->default('all');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['holiday_date', 'province']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
