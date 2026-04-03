<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-002: Add TOR (Terms of Reference) rate percentage to contractors.
 *
 * tor_rate_pct — the overhead / service-charge percentage the factory pays
 * the contractor on top of worker wages. Defaults to 0 (no markup), capped
 * at 100 to prevent data entry errors.
 *
 * Example: if worker wages total PKR 100,000 and tor_rate_pct = 15.00,
 * the contractor receives PKR 115,000.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->decimal('tor_rate_pct', 5, 2)
                ->default(0.00)
                ->after('portal_access')
                ->comment('Contractor service-charge percentage on top of worker wages (0–100)');

            $table->string('bank_name', 100)
                ->nullable()
                ->after('bank_account')
                ->comment('Bank name for payment disbursement');

            $table->string('whatsapp', 20)
                ->nullable()
                ->after('phone')
                ->comment('WhatsApp contact number for notifications');
        });
    }

    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropColumn(['tor_rate_pct', 'bank_name', 'whatsapp']);
        });
    }
};
