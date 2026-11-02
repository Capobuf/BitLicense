<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('gravityzone_id')->unique();
            $table->text('name');
            $table->text('platform')->nullable();
            $table->boolean('is_managed')->nullable();
            $table->boolean('is_isolated')->nullable();
            $table->boolean('is_present')->default(true);
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('endpoint_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        foreach (['companies', 'gravity_zone_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('endpoints_sync_attempted_at')->nullable();
                $table->timestamp('endpoints_synced_at')->nullable();
                $table->text('endpoints_sync_error')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['companies', 'gravity_zone_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['endpoints_sync_attempted_at', 'endpoints_synced_at', 'endpoints_sync_error']);
            });
        }
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('endpoints');
    }
};
