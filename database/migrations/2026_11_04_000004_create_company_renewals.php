<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->date('starts_on');
            $table->date('expires_on');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'starts_on', 'expires_on'], 'company_renewals_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_renewals');
    }
};
