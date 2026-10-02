<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->text('last_login_user_agent')->nullable()->after('last_login_ip');
            $table->string('last_login_portal', 40)->nullable()->after('last_login_user_agent');
            $table->unsignedInteger('login_count')->default(0)->after('last_login_portal');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_login_at']);
            $table->dropColumn(['last_login_at', 'last_login_ip', 'last_login_user_agent', 'last_login_portal', 'login_count']);
        });
    }
};
