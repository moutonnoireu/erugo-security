<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted with APP_KEY (model cast)
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
            // Last accepted 30-second time step, so a code cannot be reused
            $table->unsignedBigInteger('totp_last_timestep')->nullable();
            // Encrypted JSON list of SHA-256 hashes of unused recovery codes
            $table->text('two_factor_recovery_codes')->nullable();
        });

        Schema::create('user_passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            // base64url credential id, used to look the key up at sign-in
            $table->string('credential_id', 1024)->unique();
            // Serialized WebAuthn PublicKeyCredentialSource (public key, counter...)
            $table->text('credential_source');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_passkeys');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_enabled_at', 'totp_last_timestep', 'two_factor_recovery_codes']);
        });
    }
};
