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
        Schema::create('api_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->nullable()->index(); // personal_access_tokens.id (null = legacy master key)
            $table->string('endpoint')->index();                // e.g. /v1/embeddings
            $table->unsignedSmallInteger('status');             // HTTP status
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_usages');
    }
};
