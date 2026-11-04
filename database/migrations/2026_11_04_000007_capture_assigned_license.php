<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computer_periods', function (Blueprint $table) {
            $table->string('product')->nullable();
        });
        // Preserve recorded history; today's assigned license cannot reconstruct the past.
        DB::table('computer_periods')->update(['product' => DB::raw('(SELECT product FROM company_renewals WHERE company_renewals.id = computer_periods.company_renewal_id)')]);
    }

    public function down(): void
    {
        Schema::table('computer_periods', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
