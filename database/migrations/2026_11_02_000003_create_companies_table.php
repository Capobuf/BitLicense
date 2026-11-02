<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('gravityzone_id')->unique();
            $table->text('name');
            $table->string('technical_license_type')->nullable();
            $table->date('technical_expires_on')->nullable();
            $table->timestamp('license_synced_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->boolean('is_present')->default(true);
            $table->text('sync_error')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('gravity_zone_settings', function (Blueprint $table) {
            $table->timestamp('companies_sync_attempted_at')->nullable();
            $table->timestamp('companies_synced_at')->nullable();
            $table->text('companies_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gravity_zone_settings', function (Blueprint $table) {
            $table->dropColumn(['companies_sync_attempted_at', 'companies_synced_at', 'companies_sync_error']);
        });

        Schema::dropIfExists('companies');
    }
};
