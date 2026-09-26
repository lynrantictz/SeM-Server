<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_checkout_verifications', function (Blueprint $table) {
            $table->unsignedSmallInteger('whatsapp_send_version')->default(0)->after('code_sent_at');
            $table->string('whatsapp_status', 24)->nullable()->after('whatsapp_send_version');
            $table->string('whatsapp_message_id')->nullable()->unique()->after('whatsapp_status');
            $table->timestamp('whatsapp_sent_at')->nullable()->after('whatsapp_message_id');
            $table->timestamp('whatsapp_last_attempt_at')->nullable()->after('whatsapp_sent_at');
            $table->string('whatsapp_failure_reason', 500)->nullable()->after('whatsapp_last_attempt_at');
            $table->index(['whatsapp_status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_checkout_verifications', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_status', 'expires_at']);
            $table->dropUnique(['whatsapp_message_id']);
            $table->dropColumn([
                'whatsapp_send_version',
                'whatsapp_status',
                'whatsapp_message_id',
                'whatsapp_sent_at',
                'whatsapp_last_attempt_at',
                'whatsapp_failure_reason',
            ]);
        });
    }
};
