<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) { $table->decimal('discount_percentage', 5, 2)->default(0)->after('discount'); });
        Schema::table('item_prices', function (Blueprint $table) { $table->decimal('discount_percentage', 5, 2)->default(0)->after('discount'); });
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('discount_percentage', 5, 2)->default(0)->after('discount');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) { $table->dropColumn(['discount_percentage', 'discount_amount']); });
        Schema::table('item_prices', function (Blueprint $table) { $table->dropColumn('discount_percentage'); });
        Schema::table('items', function (Blueprint $table) { $table->dropColumn('discount_percentage'); });
    }
};
