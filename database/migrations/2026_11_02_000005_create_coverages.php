<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('workstation_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('price_cents');
            $table->string('invoice_reference')->nullable();
            $table->string('quote_reference')->nullable();
            $table->timestamps();
            $table->index(['workstation_id', 'starts_on', 'ends_on']);
        });

        Schema::create('coverage_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coverage_id')->constrained()->restrictOnDelete();
            $table->foreignId('workstation_id')->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->index(['coverage_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_assignments');
        Schema::dropIfExists('coverages');
    }
};
