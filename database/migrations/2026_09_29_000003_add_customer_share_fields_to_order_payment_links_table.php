<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payment_links', function (Blueprint $table) {
            $table->string('recipient_phone_e164', 15)->nullable()->after('created_by_user_id');
            $table->string('delivery_initiator', 20)->nullable()->after('recipient_phone_e164');
            $table->index(['order_id', 'delivery_initiator']);
        });
    }

    public function down(): void
    {
        Schema::table('order_payment_links', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'delivery_initiator']);
            $table->dropColumn(['recipient_phone_e164', 'delivery_initiator']);
        });
    }
};
