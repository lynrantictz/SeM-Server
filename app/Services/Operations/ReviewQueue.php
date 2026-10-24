<?php

namespace App\Services\Operations;

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ReviewQueue
{
    public const PENDING_DOCUMENTS = ['pending', 'in_review', 'needs_review'];

    public const NOTIFICATION_TYPE = 'operations.review';

    public function entity(string $scope, string $uuid): Vendor|Business
    {
        abort_unless(in_array($scope, ['vendor', 'business'], true), 404);

        return ($scope === 'vendor' ? Vendor::query() : Business::query())->where('uuid', $uuid)->firstOrFail();
    }

    public function query(string $scope, string $status = 'pending'): Builder
    {
        $table = $scope === 'vendor' ? 'vendors' : 'businesses';
        $query = ($scope === 'vendor' ? Vendor::query() : Business::query())
            ->leftJoin('operations_review_profiles as review', function ($join) use ($table, $scope) {
                $join->on('review.entity_id', '=', $table.'.id')->where('review.scope', $scope);
            })->select($table.'.*', 'review.status as profile_status', 'review.version as review_version', 'review.reason as review_reason')
            ->withCount(['complianceDocuments as pending_documents_count' => fn ($q) => $q->whereIn('status', self::PENDING_DOCUMENTS)])
            ->withMax(['complianceDocuments as last_submission' => fn ($q) => $q->whereIn('status', self::PENDING_DOCUMENTS)], 'updated_at');
        if ($status === 'pending') {
            $query->where(fn ($q) => $q->whereNull('review.status')->orWhere('review.status', 'pending')->orWhereHas('complianceDocuments', fn ($d) => $d->whereIn('status', self::PENDING_DOCUMENTS)));
        } elseif ($status !== 'all') {
            $query->where('review.status', $status);
            // Waiting for the applicant is distinct from actionable work.
            if ($status === 'changes_requested') {
                $query->whereDoesntHave('complianceDocuments', fn ($d) => $d->whereIn('status', self::PENDING_DOCUMENTS));
            }
        }

        return $query;
    }

    public function counts(): array
    {
        return ['vendors' => $this->query('vendor')->count(), 'businesses' => $this->query('business')->count()];
    }

    public function notify(string $scope, Vendor|Business $entity, string $event, string $eventKey): void
    {
        $id = $entity->id;
        DB::afterCommit(function () use ($scope, $id, $event, $eventKey) {
            $entity = ($scope === 'vendor' ? Vendor::query() : Business::query())->find($id);
            if (! $entity) {
                return;
            }
            $users = User::with(['roles.permissions', 'permissions'])->where('type', 'paperstic')->where('is_active', true)->get();
            foreach ($users as $user) {
                if (! $user->can('operations.kyc.view')) {
                    continue;
                }
                DB::table('notifications')->insertOrIgnore([
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'paperstic:review:'.$eventKey.':'.$user->id)->toString(),
                    'type' => self::NOTIFICATION_TYPE,
                    'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
                    'data' => json_encode(['scope' => $scope, 'uuid' => $entity->uuid, 'name' => $entity->name, 'event' => $event, 'url' => '/kyc/'.$scope.'/'.$entity->uuid]),
                    'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function reopen(string $scope, Vendor|Business $entity): void
    {
        $changed = DB::table('operations_review_profiles')->where('scope', $scope)->where('entity_id', $entity->id)
            ->whereIn('status', ['changes_requested', 'approved'])->update(['status' => 'pending', 'version' => DB::raw('version + 1'), 'reviewed_by' => null, 'reviewed_at' => null, 'reason' => null, 'updated_at' => now()]);
        if ($changed) {
            $this->notify($scope, $entity, 'Information resubmitted', 'profile:'.$scope.':'.$entity->id.':'.DB::table('operations_review_profiles')->where('scope', $scope)->where('entity_id', $entity->id)->value('version'));
        }
    }

    public function documentSubmitted(ComplianceDocument $document, bool $resubmitted = false): void
    {
        $scope = $document->business_id ? 'business' : 'vendor';
        $entity = $document->business_id ? $document->business : $document->vendor;
        if (! $entity) {
            return;
        }
        // New document work does not change an already approved profile decision.
        DB::table('operations_review_profiles')->where('scope', $scope)->where('entity_id', $entity->id)
            ->where('status', 'changes_requested')->update(['status' => 'pending', 'version' => DB::raw('version + 1'), 'reason' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => now()]);
        $this->notify($scope, $entity, $resubmitted ? 'Document resubmitted' : 'Document submitted', 'document:'.$document->id.':'.(! $resubmitted ? 'created' : 'resubmitted:'.DB::table('operations_review_history')->where('document_id', $document->id)->max('id').':'.$document->updated_at?->format('U.u')));
    }
}
