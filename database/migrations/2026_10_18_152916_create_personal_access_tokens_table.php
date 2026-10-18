<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sanctum tokens double as the device/session registry: each token
     * represents one signed-in device that the user can review and revoke.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            // SHA-256 of the plaintext token; the plaintext is shown to the client once.
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('device_id', 100)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
