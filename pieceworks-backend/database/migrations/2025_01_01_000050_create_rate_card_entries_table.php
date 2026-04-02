<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_card_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_id')
                  ->constrained('rate_cards')
                  ->cascadeOnDelete();
            $table->string('task')->comment('e.g. Stitching, Lasting, Sole Attaching');
            $table->enum('complexity_tier', ['simple', 'standard', 'complex', 'premium']);
            $table->enum('worker_grade', ['junior', 'senior']);
            $table->decimal('rate_pkr', 10, 2)->comment('Rate in Pakistani Rupees per piece');
            $table->timestamps();

            // A task+tier+grade combination must be unique within a rate card
            $table->unique(['rate_card_id', 'task', 'complexity_tier', 'worker_grade'], 'unique_rate_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_card_entries');
    }
};
