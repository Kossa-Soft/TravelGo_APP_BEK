<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table->timestamp('sms_accepted_at')->nullable();
        });

        DB::table('phone_verifications')->whereNotNull('sms_claimed_at')
            ->update(['sms_accepted_at' => DB::raw('sms_claimed_at')]);

        Schema::table('integration_settings', function (Blueprint $table): void {
            $table->decimal('balance_amount', 16, 4)->nullable();
            $table->char('balance_currency', 3)->nullable();
            $table->timestamp('balance_checked_at')->nullable();
            $table->string('last_sms_error', 32)->nullable();
            $table->timestamp('last_sms_error_at')->nullable();
            $table->timestamp('last_sms_accepted_at')->nullable();
            $table->unsignedBigInteger('last_sms_request_id')->nullable();
            $table->string('last_sms_delivery_status', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table->dropColumn('sms_accepted_at');
        });

        Schema::table('integration_settings', function (Blueprint $table): void {
            $table->dropColumn(['balance_amount', 'balance_currency', 'balance_checked_at', 'last_sms_error',
                'last_sms_error_at', 'last_sms_accepted_at', 'last_sms_request_id', 'last_sms_delivery_status']);
        });
    }
};
