<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->date('occurred_on')->nullable();
            $table->string('billing_status', 16)->nullable();
            $table->string('invoice_reference')->nullable();
            $table->string('quote_reference')->nullable();
            $table->string('product')->nullable();
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->json('affected_periods')->nullable();
            $table->json('history')->nullable();
            $table->index(['company_id', 'occurred_on']);
        });
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->dropUnique('company_renewals_period_unique');
        });
        Schema::create('computer_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_renewal_id')->constrained()->restrictOnDelete();
            $table->foreignId('computer_id')->constrained()->restrictOnDelete();
            $table->foreignId('origin_computer_id')->constrained('computers')->restrictOnDelete();
            $table->foreignId('previous_period_id')->nullable()->constrained('computer_periods')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('expires_on');
            $table->timestamps();
            $table->unique(['company_renewal_id', 'computer_id']);
            $table->index(['computer_id', 'starts_on', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->unique(['company_id', 'kind', 'starts_on', 'expires_on'], 'company_renewals_period_unique');
        });
        Schema::dropIfExists('computer_periods');
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'occurred_on']);
            $table->dropColumn(['occurred_on', 'billing_status', 'invoice_reference', 'quote_reference', 'product', 'price_cents', 'affected_periods', 'history']);
        });
    }
};
