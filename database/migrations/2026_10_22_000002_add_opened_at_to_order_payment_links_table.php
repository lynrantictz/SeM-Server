<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payment_links', function (Blueprint $table): void {
            $table->timestamp('opened_at')->nullable()->after('expires_at');
            $table->index(['order_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_payment_links', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'opened_at']);
            $table->dropColumn('opened_at');
        });
    }
};
