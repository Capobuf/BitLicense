<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gravity_zone_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('api_url', 2048);
            $table->text('api_key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gravity_zone_settings');
    }
};
