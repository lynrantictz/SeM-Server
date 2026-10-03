<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_payout_accounts', function (Blueprint $table): void {
            $table->text('wallet_id')->nullable()->after('gateway');
            $table->text('phone_number')->nullable()->after('account_number');
            $table->text('verification_document_path')->nullable()->after('rejection_reason');
            $table->string('verification_document_filename')->nullable()->after('verification_document_path');
            $table->foreignId('verification_document_uploaded_by')->nullable()->after('verification_document_filename')->constrained('users')->nullOnDelete();
            $table->timestamp('verification_document_uploaded_at')->nullable()->after('verification_document_uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('business_payout_accounts', function (Blueprint $table): void {
            $table->dropForeign(['verification_document_uploaded_by']);
            $table->dropColumn([
                'wallet_id', 'phone_number', 'verification_document_path',
                'verification_document_filename', 'verification_document_uploaded_by',
                'verification_document_uploaded_at',
            ]);
        });
    }
};
