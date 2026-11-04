<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->unsignedSmallInteger('gravityzone_license_status')->nullable();
            $table->timestamp('license_checked_at')->nullable();
            $table->text('license_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn(['gravityzone_license_status', 'license_checked_at', 'license_sync_error']);
        });
    }
};
