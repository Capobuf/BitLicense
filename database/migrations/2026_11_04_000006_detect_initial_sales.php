<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->string('detection_month', 7)->nullable();
            $table->boolean('is_regrouped')->default(false);
            $table->unique(['company_id', 'detection_month']);
            $table->date('expires_on')->nullable()->change();
        });
        Schema::table('computer_periods', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->change();
            $table->boolean('is_detected')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('computer_periods', function (Blueprint $table) {
            $table->dropColumn('is_detected');
        });
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'detection_month']);
            $table->dropColumn(['detection_month', 'is_regrouped']);
        });
        // Unknown expiries remain nullable to preserve imported sales on rollback.
    }
};
