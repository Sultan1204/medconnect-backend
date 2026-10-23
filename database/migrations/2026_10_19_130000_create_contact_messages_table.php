<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Messages from the public contact form. Name, email and text are personal data: encrypted.
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('email');
            $table->string('subject', 150);
            $table->text('message');
            $table->foreignUuid('handled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['handled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
