<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::table('orders')->whereNotNull('order_status_id')->pluck('order_status_id') as $value) {
                if (!ctype_digit((string) $value)) throw new RuntimeException('Non-numeric order status ID.');
            }
            Schema::table('orders', fn (Blueprint $table) => $table->bigInteger('order_status_id')->default(1)->change());
            return;
        }
        $hasInvalidStatusId = DB::table('orders')
            ->whereRaw("order_status_id IS NOT NULL AND order_status_id !~ '^[0-9]+$'")
            ->exists();

        if ($hasInvalidStatusId) {
            throw new RuntimeException('Cannot convert orders.order_status_id to an integer: non-numeric values exist.');
        }

        DB::statement('ALTER TABLE orders ALTER COLUMN order_status_id DROP DEFAULT');
        DB::statement('ALTER TABLE orders ALTER COLUMN order_status_id TYPE BIGINT USING order_status_id::BIGINT');
        DB::statement('ALTER TABLE orders ALTER COLUMN order_status_id SET DEFAULT 1');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('orders', fn (Blueprint $table) => $table->string('order_status_id')->default('1')->change());
            return;
        }
        DB::statement('ALTER TABLE orders ALTER COLUMN order_status_id DROP DEFAULT');
        DB::statement('ALTER TABLE orders ALTER COLUMN order_status_id TYPE VARCHAR(255) USING order_status_id::VARCHAR');
        DB::statement("ALTER TABLE orders ALTER COLUMN order_status_id SET DEFAULT '1'");
    }
};
