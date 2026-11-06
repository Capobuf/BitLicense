<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable();
            $table->unique(['company_id', 'kind', 'number']);
        });

        $numbers = [];
        DB::table('company_renewals')->orderBy('id')->chunkById(500, function ($operations) use (&$numbers): void {
            foreach ($operations as $operation) {
                $number = $numbers[$operation->company_id][$operation->kind] = ($numbers[$operation->company_id][$operation->kind] ?? 0) + 1;
                DB::table('company_renewals')->where('id', $operation->id)->update(['number' => $number]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_renewals', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'kind', 'number']);
            $table->dropColumn('number');
        });
    }
};
