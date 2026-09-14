<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
        DB::statement('ALTER TABLE orders ALTER COLUMN order_status_id DROP DEFAULT');
        DB::statement('ALTER TABLE orders ALTER COLUMN order_status_id TYPE VARCHAR(255) USING order_status_id::VARCHAR');
        DB::statement("ALTER TABLE orders ALTER COLUMN order_status_id SET DEFAULT '1'");
    }
};
