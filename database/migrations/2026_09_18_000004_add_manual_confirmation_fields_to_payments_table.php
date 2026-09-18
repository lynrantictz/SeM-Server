<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('confirmation_source', 32)->nullable()->after('status');
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable()->after('confirmation_source');
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by_user_id');
            $table->index('confirmed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['confirmed_by_user_id']);
            $table->dropColumn(['confirmation_source', 'confirmed_by_user_id', 'confirmed_at']);
        });
    }
};
