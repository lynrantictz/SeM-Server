<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_document_reminders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('compliance_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_key', 40);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['compliance_document_id', 'user_id', 'reminder_key'],
                'compliance_document_reminders_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_document_reminders');
    }
};
