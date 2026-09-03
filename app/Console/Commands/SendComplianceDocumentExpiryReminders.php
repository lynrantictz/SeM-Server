<?php

namespace App\Console\Commands;

use App\Models\Business\ComplianceDocument;
use App\Models\Business\ComplianceDocumentReminder;
use App\Notifications\ComplianceDocumentExpiryReminder;
use Illuminate\Console\Command;

class SendComplianceDocumentExpiryReminders extends Command
{
    protected $signature = 'compliance-documents:send-expiry-reminders';

    protected $description = 'Notify relevant vendor users when compliance documents are expiring or expired.';

    public function handle(): int
    {
        $today = today();
        $documents = ComplianceDocument::query()
            ->with(['documentType', 'business.vendor.users', 'vendor.users'])
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $today->copy()->addDays(90))
            ->get();

        foreach ($documents as $document) {
            $daysUntilExpiry = $today->diffInDays($document->expires_at, false);
            $reminderKey = $daysUntilExpiry < 0
                ? 'expired'
                : $this->reminderKey($document, $daysUntilExpiry);

            if (!$reminderKey) {
                continue;
            }

            if ($reminderKey === 'expired' && $document->status !== 'rejected') {
                $document->update(['status' => 'expired']);
            }

            $vendor = $document->vendor ?? $document->business?->vendor;
            if (!$vendor) {
                continue;
            }

            $recipients = $vendor->users
                ->filter(fn ($user) => $user->pivot->is_primary
                    || ($user->pivot->is_active && $user->pivot->role === 'manager'));

            foreach ($recipients as $recipient) {
                $alreadySent = ComplianceDocumentReminder::query()
                    ->where('compliance_document_id', $document->id)
                    ->where('user_id', $recipient->id)
                    ->where('reminder_key', $reminderKey)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $recipient->notify(new ComplianceDocumentExpiryReminder($document, $reminderKey));
                ComplianceDocumentReminder::query()->create([
                    'compliance_document_id' => $document->id,
                    'user_id' => $recipient->id,
                    'reminder_key' => $reminderKey,
                    'sent_at' => now(),
                ]);
            }
        }

        $this->info("Checked {$documents->count()} expiring compliance documents.");

        return self::SUCCESS;
    }

    private function reminderKey(ComplianceDocument $document, int $daysUntilExpiry): ?string
    {
        $days = $document->documentType?->reminder_days ?? [30, 7, 1];

        return in_array($daysUntilExpiry, $days, true)
            ? "expires_in_{$daysUntilExpiry}_days"
            : null;
    }
}
