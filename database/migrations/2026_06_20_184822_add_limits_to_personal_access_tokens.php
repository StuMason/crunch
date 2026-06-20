<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedInteger('monthly_limit')->nullable();      // null = unlimited
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(120);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['monthly_limit', 'rate_limit_per_minute']);
        });
    }
};
