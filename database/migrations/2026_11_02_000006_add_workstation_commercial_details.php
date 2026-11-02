<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('annual_price_cents');
            $table->timestamps();
        });

        Schema::table('workstations', function (Blueprint $table) {
            $table->foreignId('company_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('commercial_description')->nullable();
            $table->date('installed_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_product_id');
            $table->dropColumn(['commercial_description', 'installed_on']);
        });

        Schema::dropIfExists('company_products');
    }
};
