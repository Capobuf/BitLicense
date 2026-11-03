<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('gravityzone_id')->nullable()->unique();
            $table->foreignId('gravityzone_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->text('technical_name')->nullable();
            $table->string('name')->nullable();
            $table->text('platform')->nullable();
            $table->boolean('is_managed')->nullable();
            $table->boolean('is_isolated')->nullable();
            $table->boolean('is_present')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('product')->nullable();
            $table->date('activated_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->text('commercial_description')->nullable();
            $table->string('invoice_reference')->nullable();
            $table->string('quote_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('history')->nullable();
            $table->unsignedInteger('commercial_version')->default(0);
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
        Schema::dropIfExists('computers');
    }
};
