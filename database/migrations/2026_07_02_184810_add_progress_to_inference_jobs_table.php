<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a long-running job is up to — `{stage, done?, total?}`, written by the worker at
     * stage boundaries (a pack runs for minutes; pollers deserve more than "processing").
     * Null once completed.
     */
    public function up(): void
    {
        Schema::table('inference_jobs', function (Blueprint $table) {
            $table->json('progress')->nullable()->after('result');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inference_jobs', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
