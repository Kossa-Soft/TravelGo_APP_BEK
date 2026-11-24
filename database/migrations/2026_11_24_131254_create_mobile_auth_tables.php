<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->uuid('public_id')->nullable()->unique();
            $table->string('phone', 16)->nullable()->unique();
            $table->string('language', 2)->default('uk');
        });

        DB::table('users')->whereNull('public_id')->select('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['public_id' => (string) Str::uuid()]);
            }
        });

        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('purpose', 12);
            $table->string('phone', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 2);
            $table->string('pending_password_hash')->nullable();
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('resend_at');
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('delivery_id');
            $table->timestamp('sms_claimed_at')->nullable();
            $table->string('reset_token_hash', 64)->nullable()->unique();
            $table->timestamp('reset_expires_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('mobile_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('access_token_hash', 64)->unique();
            $table->string('refresh_token_hash', 64)->unique();
            $table->timestamp('access_expires_at');
            $table->timestamp('refresh_expires_at')->index();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('name')->orWhereNull('email')->exists()) {
            throw new LogicException('Back up or migrate phone accounts before rolling back mobile authentication.');
        }

        Schema::dropIfExists('mobile_sessions');
        Schema::dropIfExists('phone_verifications');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropUnique(['public_id']);
            $table->dropColumn(['phone', 'public_id', 'language']);
            $table->string('name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
