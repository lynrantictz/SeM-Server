<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_to_user_id')->nullable()->after('approver_id');
            $table->index('assigned_to_user_id');
        });

        DB::table('order_statuses')->updateOrInsert(
            ['name' => 'Served'],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['assigned_to_user_id']);
            $table->dropColumn('assigned_to_user_id');
        });

        DB::table('order_statuses')->where('name', 'Served')->delete();
    }
};
