<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn_cnic', 20)->nullable()->comment('NTN (7-digit) or CNIC (13-digit)');
            $table->string('contact_person')->nullable();
            $table->string('phone', 20)->nullable();
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->enum('payment_cycle', ['weekly', 'biweekly', 'monthly'])->default('monthly');
            $table->string('bank_account', 30)->nullable();
            $table->boolean('portal_access')->default(false);
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
