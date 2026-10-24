<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Services\Business\ComplianceDocumentRequirements;
use App\Services\Operations\ReviewQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OperationsReviewController extends BaseController
{
    public function __construct(private readonly ReviewQueue $queue, private readonly ComplianceDocumentRequirements $requirements) {}

    private function authorizeOperations(Request $request): void
    {
        abort_unless($request->user()?->type === 'paperstic' && $request->user()->is_active && $request->user()->can('operations.kyc.view'), 403);
    }

    public function summary(Request $request)
    {
        $this->authorizeOperations($request);

        return $this->sendResponse($this->queue->counts(), 'Pending review counts.');
    }

    public function index(Request $request)
    {
        $this->authorizeOperations($request);
        $filters = $request->validate(['scope' => ['required', 'in:vendor,business'], 'status' => ['nullable', 'in:pending,approved,changes_requested,all'], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100']]);
        $scope = $filters['scope'];
        $table = $scope === 'vendor' ? 'vendors' : 'businesses';
        $query = $this->queue->query($scope, $filters['status'] ?? 'pending');
        if ($scope === 'business') {
            $query->with(['vendor:id,uuid,name', 'district.city']);
        }
        $query->when(trim($filters['search'] ?? '') !== '', function ($q) use ($filters, $table, $scope) {
            $term = '%'.mb_strtolower(trim($filters['search'])).'%';
            $q->where(function ($search) use ($term, $table, $scope) {
                $search->whereRaw('LOWER('.$table.'.name) LIKE ?', [$term])->orWhereRaw('LOWER('.$table.'.tin) LIKE ?', [$term]);
                if ($scope === 'business') {
                    $search->orWhereHas('vendor', fn ($v) => $v->whereRaw('LOWER(name) LIKE ?', [$term]));
                }
            });
        });
        $pendingSince = "CASE WHEN review.status IS NULL OR review.status = 'pending' THEN COALESCE(review.created_at, ".$table.'.created_at) ELSE COALESCE((SELECT MIN(updated_at) FROM compliance_documents WHERE '.$scope.'_id = '.$table.".id AND status IN ('pending', 'in_review', 'needs_review')), ".$table.'.created_at) END';
        $result = $query->with('complianceDocuments')->orderByRaw($pendingSince.' ASC')->orderBy($table.'.id')
            ->paginate($filters['per_page'] ?? 10);
        $result->through(fn ($entity) => [
            'uuid' => $entity->uuid, 'name' => $entity->name, 'vendor' => $scope === 'business' ? $entity->vendor?->name : null,
            'status' => $entity->profile_status ?? 'pending', 'pending_documents' => (int) $entity->pending_documents_count,
            'missing_requirements' => $this->checklist($scope, $entity)->where('required', true)->where('state', 'missing')->count(),
            'submitted_at' => $entity->last_submission ? \Illuminate\Support\Carbon::parse($entity->last_submission)->toIso8601String() : $entity->created_at?->toIso8601String(),
            'reason' => $entity->review_reason, 'url' => '/kyc/'.$scope.'/'.$entity->uuid,
        ]);

        return $this->sendResponse(['reviews' => $result, 'counts' => $this->queue->counts()], 'Review queue retrieved.');
    }

    private function profile(string $scope, $entity): ?object
    {
        return DB::table('operations_review_profiles')->where('scope', $scope)->where('entity_id', $entity->id)->first();
    }

    private function revision(string $scope, $entity): string
    {
        $vendor = $scope === 'vendor' ? $entity : $entity->vendor;
        $owners = $vendor?->users()->wherePivot('is_primary', true)->orderBy('users.id')->get(['users.id', 'users.name', 'users.email', 'users.phone', 'users.updated_at'])->map->getAttributes()->all();

        return hash('sha256', json_encode([$entity->getAttributes(), $vendor?->getAttributes(), $owners, $this->profile($scope, $entity), $entity->complianceDocuments()->orderBy('id')->get()->map->getAttributes()->all()]));
    }

    private function checklist(string $scope, $entity)
    {
        $documents = $entity->complianceDocuments->sortByDesc('id')->groupBy('document_type')->map->first();

        return $this->requirements->available($scope, $entity)->map(function ($type) use ($documents) {
            $document = $documents->get($type['key']);

            return ['key' => $type['key'], 'label' => $type['name'], 'required' => (bool) $type['required_for_payment_activation'],
                'state' => $document ? $this->documentStatus($document) : 'missing'];
        })->values();
    }

    private function documentStatus(ComplianceDocument $document): string
    {
        return $document->status === 'approved' && $document->expires_at?->isPast() ? 'expired' : $document->status;
    }

    private function documentRevision(ComplianceDocument $document): string
    {
        return hash('sha256', json_encode([$document->getAttributes(), DB::table('operations_review_history')->where('document_id', $document->id)->max('id')]));
    }

    private function documentData(ComplianceDocument $document): array
    {
        return ['uuid' => $document->uuid, 'label' => $document->document_type_name ?? $document->document_type, 'number' => $document->document_number,
            'filename' => $document->original_filename, 'status' => $this->documentStatus($document), 'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(), 'submitted_at' => $document->updated_at?->toIso8601String(),
            'reviewed_by' => $document->reviewer?->name, 'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reason' => $document->rejection_reason, 'revision' => $this->documentRevision($document)];
    }

    public function show(Request $request, string $scope, string $uuid)
    {
        $this->authorizeOperations($request);
        $entity = $this->queue->entity($scope, $uuid);
        $entity->load('complianceDocuments.reviewer');
        $profile = $this->profile($scope, $entity);
        $history = DB::table('operations_review_history as history')->leftJoin('users', 'users.id', '=', 'history.user_id')
            ->where('history.scope', $scope)->where('history.entity_id', $entity->id)->orderByDesc('history.id')
            ->select('history.*', 'users.name as reviewer')->limit(100)->get();

        return $this->sendResponse(['entity' => ['uuid' => $entity->uuid, 'name' => $entity->name, 'tin' => $entity->tin,
            'email' => $scope === 'vendor' ? $entity->email : $entity->vendor?->email, 'phone' => $scope === 'vendor' ? $entity->phone : $entity->vendor?->phone,
            'address' => $scope === 'vendor' ? $entity->address : $entity->location,
            'vendor' => $scope === 'business' ? $entity->vendor?->name : null,
            'country' => $scope === 'vendor' ? $entity->country?->name : $entity->district?->city?->country?->name,
            'business_type' => $scope === 'business' ? $entity->type?->name : null,
            'city' => $scope === 'business' ? $entity->district?->city?->name : null,
            'district' => $scope === 'business' ? $entity->district?->name : null,
            'owner' => ($scope === 'vendor' ? $entity : $entity->vendor)?->users()->wherePivot('is_primary', true)->first()?->only(['name', 'email', 'phone']),
            'profile_url' => '/'.($scope === 'vendor' ? 'vendors' : 'businesses').'/'.$entity->uuid,
            'businesses' => $scope === 'vendor' ? $entity->businesses()->get(['uuid', 'name'])->map(fn ($b) => ['uuid' => $b->uuid, 'name' => $b->name]) : [],
            'status' => $profile?->status ?? 'pending', 'reason' => $profile?->reason, 'reviewed_at' => $profile?->reviewed_at ? \Illuminate\Support\Carbon::parse($profile->reviewed_at)->toIso8601String() : null,
            'reviewer' => $profile?->reviewed_by ? DB::table('users')->where('id', $profile->reviewed_by)->value('name') : null,
            'revision' => $this->revision($scope, $entity)],
            'documents' => $entity->complianceDocuments->sortByDesc('id')->map(fn ($d) => $this->documentData($d))->values(),
            'requirements' => $this->checklist($scope, $entity), 'history' => $history->map(function ($entry) {
                $entry->created_at = \Illuminate\Support\Carbon::parse($entry->created_at)->toIso8601String();

                return $entry;
            }),
            'activation' => $entity instanceof Business ? app(\App\Services\Business\BusinessActivationService::class)->status($entity) : null,
        ], 'Review details retrieved.');
    }

    public function decide(Request $request, string $scope, string $uuid)
    {
        $this->authorizeOperations($request);
        $data = $request->validate(['decision' => ['required', 'in:approved,changes_requested'], 'reason' => ['required_if:decision,changes_requested', 'nullable', 'string', 'min:5', 'max:2000'], 'revision' => ['required', 'string', 'size:64']]);
        abort_unless($request->user()->can('operations.kyc.review') && $request->user()->can($data['decision'] === 'approved' ? 'operations.kyc.approve' : 'operations.kyc.return'), 403);
        DB::transaction(function () use ($request, $scope, $uuid, $data) {
            $entity = $this->queue->entity($scope, $uuid);
            $entity = $entity->newQuery()->whereKey($entity->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($this->revision($scope, $entity), $data['revision']), 409, 'This submission changed. Refresh before making a decision.');
            $previous = $this->profile($scope, $entity);
            DB::table('operations_review_profiles')->updateOrInsert(['scope' => $scope, 'entity_id' => $entity->id], [
                'status' => $data['decision'], 'version' => ($previous?->version ?? 0) + 1, 'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(), 'reason' => $data['decision'] === 'changes_requested' ? trim($data['reason']) : null,
                'created_at' => $previous?->created_at ?? now(), 'updated_at' => now(),
            ]);
            $this->history($scope, $entity->id, null, $data['decision'], $request, $data['reason'] ?? null);
        });

        return $this->sendResponse([], 'Information review saved.');
    }

    private function history(string $scope, int $entityId, ?int $documentId, string $decision, Request $request, ?string $reason): void
    {
        DB::table('operations_review_history')->insert(['scope' => $scope, 'entity_id' => $entityId, 'document_id' => $documentId,
            'decision' => $decision, 'user_id' => $request->user()->id, 'reason' => $decision === 'approved' ? null : trim($reason ?? ''), 'created_at' => now()]);
    }

    public function file(Request $request, string $uuid)
    {
        $this->authorizeOperations($request);
        $document = ComplianceDocument::where('uuid', $uuid)->firstOrFail();
        abort_unless(Storage::disk($document->disk)->exists($document->storage_path), 404, 'The document file is unavailable.');

        return Storage::disk($document->disk)->response($document->storage_path, $document->original_filename, ['Content-Disposition' => 'inline']);
    }

    public function decideDocument(Request $request, string $uuid)
    {
        $this->authorizeOperations($request);
        $data = $request->validate(['decision' => ['required', 'in:approved,returned'], 'reason' => ['required_if:decision,returned', 'nullable', 'string', 'min:5', 'max:2000'], 'revision' => ['required', 'string', 'size:64']]);
        abort_unless($request->user()->can('operations.kyc.review') && $request->user()->can($data['decision'] === 'approved' ? 'operations.kyc.approve' : 'operations.kyc.return'), 403);
        DB::transaction(function () use ($request, $uuid, $data) {
            $document = ComplianceDocument::where('uuid', $uuid)->firstOrFail();
            $scope = $document->business_id ? 'business' : 'vendor';
            $entity = $document->business_id ? $document->business : $document->vendor;
            abort_unless($entity, 404);
            $entity->newQuery()->whereKey($entity->id)->lockForUpdate()->firstOrFail();
            $document = ComplianceDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($this->documentRevision($document), $data['revision']), 409, 'This document changed. Refresh before making a decision.');
            abort_unless(in_array($this->documentStatus($document), ['pending', 'in_review', 'needs_review', 'approved'], true), 409, 'This document is not awaiting a review decision.');
            if ($data['decision'] === 'approved') {
                abort_if($document->expires_at?->isPast(), 422, 'An expired document cannot be approved.');
            }
            $document->update(['status' => $data['decision'] === 'approved' ? 'approved' : 'needs_update', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'rejection_reason' => $data['decision'] === 'returned' ? trim($data['reason']) : null]);
            if ($document->business && $document->document_type === 'vat_registration_certificate') {
                $document->business->update(['tax_allowed' => $data['decision'] === 'approved', 'tax_status' => $data['decision'] === 'approved' ? 'vat_registered' : 'under_review']);
            }
            $this->history($scope, $entity->id, $document->id, $data['decision'], $request, $data['reason'] ?? null);
        });

        return $this->sendResponse([], 'Document review saved.');
    }

    private function notificationsQuery(Request $request)
    {
        return DB::table('notifications')->where('type', ReviewQueue::NOTIFICATION_TYPE)->where('notifiable_type', $request->user()->getMorphClass())->where('notifiable_id', $request->user()->id);
    }

    public function notifications(Request $request)
    {
        $this->authorizeOperations($request);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $counts = $this->queue->counts();
        $query = $this->notificationsQuery($request);
        // Surface historical work once, without manufacturing every past event.
        if (array_sum($counts) > 0 && ! (clone $query)->exists()) {
            DB::table('notifications')->insertOrIgnore([
                'id' => \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'paperstic:initial-review-summary:'.$request->user()->id)->toString(),
                'type' => ReviewQueue::NOTIFICATION_TYPE,
                'notifiable_type' => $request->user()->getMorphClass(), 'notifiable_id' => $request->user()->id,
                'data' => json_encode(['scope' => 'all', 'uuid' => null, 'name' => 'Pending reviews', 'event' => 'Existing vendor and business submissions need attention', 'url' => '/kyc']),
                'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $unread = (clone $query)->whereNull('read_at')->count();
        $items = $query->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('created_at')->orderBy('id')->paginate(15);
        $items->through(fn ($n) => ['id' => $n->id, 'data' => json_decode($n->data), 'read_at' => $n->read_at ? \Illuminate\Support\Carbon::parse($n->read_at)->toIso8601String() : null, 'created_at' => \Illuminate\Support\Carbon::parse($n->created_at)->toIso8601String()]);

        return $this->sendResponse(['notifications' => $items, 'unread' => $unread, 'counts' => $counts], 'Review alerts retrieved.');
    }

    public function readNotifications(Request $request)
    {
        $this->authorizeOperations($request);
        $data = $request->validate(['id' => ['nullable', 'uuid'], 'all' => ['nullable', 'boolean']]);
        abort_unless(($data['all'] ?? false) || ! empty($data['id']), 422, 'Choose a notification or mark all as read.');
        $query = $this->notificationsQuery($request)->whereNull('read_at');
        if (! ($data['all'] ?? false)) {
            $query->where('id', $data['id']);
        }
        $query->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->sendResponse([], 'Alerts marked as read.');
    }
}
