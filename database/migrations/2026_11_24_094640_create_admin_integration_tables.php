<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->text('totp_secret');
            $table->unsignedBigInteger('last_totp_step')->nullable();
            $table->boolean('is_superadmin')->default(false);
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->string('sender')->nullable();
            $table->text('api_key')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained()->restrictOnDelete();
            $table->foreignId('integration_setting_id')->constrained()->restrictOnDelete();
            $table->json('changed_fields');
            $table->timestamp('created_at');
        });

        DB::table('integration_settings')->insert(['provider' => 'alphasms', 'sender' => 'Site-Code']);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_audit_events');
        Schema::dropIfExists('integration_settings');
        Schema::dropIfExists('admin_users');
    }
};
