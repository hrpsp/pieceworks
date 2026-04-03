<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-008 — Add TOR calculation columns to contractor_settlements.
 *
 * tor_amount          – PKR overhead charged on top of bata_owes
 *                       = bata_owes × (contractors.tor_rate_pct / 100)
 *
 * settlement_after_tor – total amount Bata must pay the contractor
 *                        = bata_owes + tor_amount
 *                        = bata_owes × (1 + tor_rate_pct / 100)
 *
 * The existing bata_owes column continues to represent gross worker earnings
 * before TOR; settlement_after_tor is what the invoice amount should be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractor_settlements', function (Blueprint $table) {
            $table->decimal('tor_rate_pct', 5, 2)->default(0.00)->after('bata_owes')
                  ->comment('Snapshot of tor_rate_pct at time of settlement (from contractors table)');
            $table->decimal('tor_amount', 12, 2)->default(0.00)->after('tor_rate_pct')
                  ->comment('PKR TOR overhead: bata_owes × (tor_rate_pct / 100)');
            $table->decimal('settlement_after_tor', 12, 2)->default(0.00)->after('tor_amount')
                  ->comment('Total payable to contractor: bata_owes + tor_amount');
        });
    }

    public function down(): void
    {
        Schema::table('contractor_settlements', function (Blueprint $table) {
            $table->dropColumn(['tor_rate_pct', 'tor_amount', 'settlement_after_tor']);
        });
    }
};
