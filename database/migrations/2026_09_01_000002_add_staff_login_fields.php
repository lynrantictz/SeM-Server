<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedBigInteger('next_staff_number')->default(1)->after('code_prefix');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->unique('code');
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropUnique(['email']);
            $table->dropColumn('must_change_password');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('next_staff_number');
        });
    }
};
