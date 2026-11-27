<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('plan', 8)->default('free');
            $table->timestamp('plan_until')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'id']);
            $table->dropColumn(['plan', 'plan_until', 'blocked_at', 'last_login_at']);
        });
    }
};
