<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 60);
            // Só o hash SHA-256 fica no banco; o token aparece uma única vez, na criação.
            $table->string('token_hash', 64)->unique();
            $table->string('prefixo', 12);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // A API sincroniza por "alterados desde": percorre por updated_at dentro de cada empresa.
        Schema::table('dfe_documents', function (Blueprint $table) {
            $table->index(['company_id', 'updated_at', 'id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('webhook_url', 500)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_ultimo_status', 120)->nullable();
            $table->timestamp('webhook_ultimo_envio_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret', 'webhook_ultimo_status', 'webhook_ultimo_envio_at']);
        });

        Schema::table('dfe_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'updated_at', 'id']);
        });

        Schema::dropIfExists('api_tokens');
    }
};
