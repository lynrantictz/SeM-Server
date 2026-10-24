<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_vendor_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_primary')->default(false);
            $t->string('role')->default('manager');
            $t->string('access_scope')->default('all_businesses');
            $t->boolean('is_active')->default(true);
            $t->string('stage')->default('draft');
            $t->text('notes')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'vendor_id']);
        });
        Schema::create('agent_business_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->string('title')->nullable();
            $t->string('business_role')->default('manager');
            $t->unsignedBigInteger('business_staff_role_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('deactivated_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'business_id']);
        });
        Schema::create('agent_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('onboarding_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->string('action');
            $t->json('details')->nullable();
            $t->timestamps();
        });
        Schema::table('vendor_invitations', fn (Blueprint $t) => $t->boolean('assign_owner')->default(false));
    }

    public function down(): void
    {
        Schema::table('vendor_invitations', fn (Blueprint $t) => $t->dropColumn('assign_owner'));
        foreach (['onboarding_audits', 'agent_invitations', 'agent_business_assignments', 'agent_vendor_assignments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
