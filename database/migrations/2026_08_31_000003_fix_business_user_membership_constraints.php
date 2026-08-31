<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_user', function (Blueprint $table) {
            $table->dropUnique('business_users_title_unique');
            $table->unique(['business_id', 'user_id'], 'business_user_business_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('business_user', function (Blueprint $table) {
            $table->dropUnique('business_user_business_user_unique');
            $table->unique('title');
        });
    }
};
