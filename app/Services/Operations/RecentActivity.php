<?php

namespace App\Services\Operations;

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessOnboardingPayment;
use App\Models\Business\BusinessPaymentMethod;
use App\Models\Business\BusinessPayout;
use App\Models\Business\BusinessPayoutAccount;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\Vendor;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;

class RecentActivity
{
    public function forUser(User $user): Collection
    {
        if (! $user->can('operations.audit.view')) {
            return collect();
        }
        $audits = Audit::query()->with(['user', 'auditable'])->latest('created_at')->orderByDesc('id')->limit(30)
            ->get(['id', 'event', 'auditable_type', 'auditable_id', 'user_id', 'user_type', 'new_values', 'old_values', 'created_at']);
        $audits->loadMorph('auditable', [
            ComplianceDocument::class => ['vendor:id,uuid,name', 'business:id,uuid,name'],
            Business::class => ['vendor:id,uuid,name'],
            BusinessPaymentMethod::class => ['business:id,uuid,name'],
            BusinessPayoutAccount::class => ['business:id,uuid,name'],
            BusinessOnboardingPayment::class => ['business:id,uuid,name'],
            BusinessPayout::class => ['business:id,uuid,name'],
            Order::class => ['business:id,uuid,name'],
            Payment::class => ['order.business:id,uuid,name'],
        ]);

        return $audits->map(fn (Audit $audit) => $this->describe($audit, $user))->values();
    }

    private function describe(Audit $audit, User $user): array
    {
        $record = $audit->auditable;
        $new = $audit->new_values ?? [];
        $old = $audit->old_values ?? [];
        $resource = class_basename((string) $audit->auditable_type);
        $label = Str::headline($resource);
        $subject = $record?->getAttribute('name') ?? $new['name'] ?? $old['name'] ?? $label;
        $title = $label.' '.match ($audit->event) {
            'created' => 'created', 'updated' => 'updated', 'deleted' => 'removed', 'restored' => 'restored', default => Str::lower(Str::headline($audit->event))
        };
        $context = $label;
        $kind = 'activity';
        $url = null;
        $state = null;
        $exists = $record && $audit->event !== 'deleted';

        if ($audit->auditable_type === ComplianceDocument::class) {
            $kind = 'document';
            $documentLabel = $record?->document_type_name ?? $new['document_type_name'] ?? $old['document_type_name'] ?? 'Compliance document';
            $state = $new['status'] ?? $record?->status;
            $action = match (true) {
                $audit->event === 'deleted' => 'removed',
                $audit->event === 'created' => 'submitted',
                isset($new['status']) && $new['status'] === 'approved' => 'approved',
                isset($new['status']) && $new['status'] === 'needs_update' => 'returned for corrections',
                isset($new['status']) && $new['status'] === 'pending' && ($old['status'] ?? null) !== 'pending' => 'resubmitted',
                default => 'updated',
            };
            $title = $documentLabel.' '.$action;
            $owner = $record?->business_id ? $record->business : $record?->vendor;
            $scope = $record?->business_id ? 'business' : 'vendor';
            $subject = $owner?->name ?? $documentLabel;
            $context = $owner ? ucfirst($scope).' compliance' : 'Compliance document';
            if ($exists && $owner && $user->can('operations.kyc.view')) {
                $url = '/kyc/'.$scope.'/'.$owner->uuid;
            }
        } elseif ($record instanceof Vendor || $audit->auditable_type === Vendor::class) {
            $kind = 'vendor';
            $title = $audit->event === 'created' ? 'Vendor registered' : $title;
            $context = 'Vendor profile';
            if ($exists) {
                $url = $user->can('operations.kyc.view') ? '/kyc/vendor/'.$record->uuid : ($user->can('operations.businesses.view') ? '/vendors/'.$record->uuid : null);
            }
        } elseif ($record instanceof Business || $audit->auditable_type === Business::class) {
            $kind = 'business';
            $title = $audit->event === 'created' ? 'Business registered' : $title;
            $context = $record?->vendor?->name ? 'Vendor: '.$record->vendor->name : 'Business profile';
            if ($exists) {
                $url = $user->can('operations.kyc.view') ? '/kyc/business/'.$record->uuid : ($user->can('operations.businesses.view') ? '/businesses/'.$record->uuid : null);
            }
        } elseif ($record instanceof User) {
            $kind = 'user';
            $context = $record->type === 'agent' ? 'Onboarding agent' : 'User account';
            if ($exists && $record->type === 'agent' && $user->can('operations.agents.view')) {
                $url = '/users/onboarding-agents/'.$record->uuid;
            }
        } elseif ($record instanceof Order || $record instanceof Payment || $record instanceof BusinessPaymentMethod || $record instanceof BusinessPayoutAccount || $record instanceof BusinessOnboardingPayment || $record instanceof BusinessPayout) {
            $kind = $record instanceof Order ? 'order' : 'payment';
            $business = $record instanceof Payment ? $record->order?->business : $record->business;
            $subject = $record instanceof Order ? 'Order '.$record->number : ($business?->name ?? $label);
            $context = $record instanceof Order ? ($business?->name ?? 'Business order') : $label;
            if ($exists && $business && $user->can('operations.businesses.view')) {
                $url = '/businesses/'.$business->uuid;
            }
        }

        return [
            'id' => $audit->id, 'event' => $audit->event, 'resource' => $resource, 'resource_id' => $audit->auditable_id,
            'user_id' => $audit->user_id, 'title' => $title, 'subject' => $subject, 'context' => $context,
            'actor' => $audit->user?->name ?? ($audit->user_id ? 'Former user' : 'System'),
            'kind' => $kind, 'status' => $state, 'url' => $url,
            'created_at' => $audit->created_at?->toIso8601String(),
        ];
    }
}
