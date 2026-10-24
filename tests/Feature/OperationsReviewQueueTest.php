<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Services\Operations\ReviewQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    foreach (['operations.kyc.view', 'operations.kyc.review', 'operations.kyc.approve', 'operations.kyc.return'] as $permission) {
        Permission::findOrCreate($permission, 'api');
    }
    $this->reviewer = User::create(['name' => 'Reviewer', 'email' => 'reviewer@example.test', 'password' => 'Example@2026', 'type' => 'paperstic', 'is_active' => true, 'email_verified_at' => now()]);
    $this->reviewer->givePermissionTo(['operations.kyc.view', 'operations.kyc.review', 'operations.kyc.approve', 'operations.kyc.return']);
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $this->vendor = Vendor::create(['name' => 'New vendor', 'country_id' => $country->id, 'email' => 'new@example.test']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->business = Business::create(['vendor_id' => $this->vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'name' => 'New business', 'location' => 'Dar', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->actingAs($this->reviewer, 'api');
});
function reviewDocumentFor($entity): ComplianceDocument
{
    return ComplianceDocument::create([($entity instanceof Vendor ? 'vendor_id' : 'business_id') => $entity->id, 'document_type' => 'registration', 'document_type_name' => 'Registration', 'disk' => 'local', 'storage_path' => 'test/registration.pdf', 'original_filename' => 'registration.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => 'pending']);
}
function profileReviewUrl($entity): string
{
    return '/v1/operations/reviews/'.($entity instanceof Vendor ? 'vendor' : 'business').'/'.$entity->uuid;
}
it('shows existing vendors and businesses in separate paginated queues without changing activation', function () {
    $this->getJson('/v1/operations/reviews?scope=vendor&per_page=15')->assertOk()->assertJsonPath('data.reviews.total', 1)->assertJsonPath('data.reviews.data.0.name', 'New vendor')->assertJsonPath('data.counts.businesses', 1);
    $this->getJson('/v1/operations/reviews?scope=business&search=NEW&per_page=10')->assertOk()->assertJsonPath('data.reviews.data.0.vendor', 'New vendor');
    $this->getJson('/v1/operations/reviews?scope=vendor&search=absent')->assertOk()->assertJsonPath('data.reviews.total', 0);
    $this->getJson('/v1/operations/reviews?scope=vendor&per_page=11')->assertUnprocessable();
    expect((bool) $this->business->fresh()->is_active)->toBeFalse();
});
it('tracks profile and document approvals separately and rejects stale decisions', function () {
    $document = reviewDocumentFor($this->vendor);
    $url = profileReviewUrl($this->vendor);
    $revision = $this->getJson($url)->assertOk()->json('data.entity.revision');
    $this->postJson($url.'/decision', ['decision' => 'approved', 'revision' => $revision])->assertOk();
    $this->postJson($url.'/decision', ['decision' => 'approved', 'revision' => $revision])->assertConflict();
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertJsonPath('data.reviews.total', 1)->assertJsonPath('data.reviews.data.0.status', 'approved');
    $detail = $this->getJson($url)->json('data');
    $this->postJson('/v1/operations/review-documents/'.$document->uuid.'/decision', ['decision' => 'approved', 'revision' => $detail['documents'][0]['revision']])->assertOk();
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertJsonPath('data.reviews.total', 0);
    $this->getJson($url)->assertJsonCount(2, 'data.history');
});
it('keeps returned profiles out of pending work until corrected', function () {
    $url = profileReviewUrl($this->vendor);
    $revision = $this->getJson($url)->json('data.entity.revision');
    $this->postJson($url.'/decision', ['decision' => 'changes_requested', 'reason' => 'Please correct the address', 'revision' => $revision])->assertOk();
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertJsonPath('data.reviews.total', 0);
    $this->getJson('/v1/operations/reviews?scope=vendor&status=changes_requested')->assertJsonPath('data.reviews.total', 1);
    $this->vendor->update(['address' => 'Corrected address']);
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertJsonPath('data.reviews.total', 1)->assertJsonPath('data.reviews.data.0.status', 'pending');
});
it('resubmitted vendor documents return to the review queue and stale document decisions fail', function () {
    $document = reviewDocumentFor($this->vendor);
    $url = profileReviewUrl($this->vendor);
    $detail = $this->getJson($url)->json('data');
    $this->postJson($url.'/decision', ['decision' => 'approved', 'revision' => $detail['entity']['revision']])->assertOk();
    $docUrl = '/v1/operations/review-documents/'.$document->uuid.'/decision';
    $this->postJson($docUrl, ['decision' => 'returned', 'reason' => 'Please upload a clear scan', 'revision' => $detail['documents'][0]['revision']])->assertOk();
    $this->postJson($docUrl, ['decision' => 'approved', 'revision' => $detail['documents'][0]['revision']])->assertConflict();
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertJsonPath('data.reviews.total', 0);
    $document->refresh()->update(['status' => 'pending', 'reviewed_at' => null, 'reviewed_by' => null]);
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertJsonPath('data.reviews.total', 1)->assertJsonPath('data.reviews.data.0.pending_documents', 1);
});
it('previews vendor-owned private files and rejects unavailable files', function () {
    Storage::fake('local');
    $document = reviewDocumentFor($this->vendor);
    $this->get('/v1/operations/review-documents/'.$document->uuid.'/file')->assertNotFound();
    Storage::disk('local')->put($document->storage_path, '%PDF-1.4 test');
    $this->get('/v1/operations/review-documents/'.$document->uuid.'/file')->assertOk();
});
it('does not allow approval of expired files or decisions without reasons', function () {
    $document = reviewDocumentFor($this->business);
    $document->update(['expires_at' => today()->subDay()]);
    $detail = $this->getJson(profileReviewUrl($this->business))->json('data');
    $this->postJson('/v1/operations/review-documents/'.$document->uuid.'/decision', ['decision' => 'approved', 'revision' => $detail['documents'][0]['revision']])->assertUnprocessable();
    $this->postJson(profileReviewUrl($this->business).'/decision', ['decision' => 'changes_requested', 'revision' => $detail['entity']['revision']])->assertUnprocessable();
});
it('stores deduplicated notifications and keeps read state separate from pending work', function () {
    // Explicitly exercise delivery too; insertOrIgnore protects retries of one event.
    $queue = app(ReviewQueue::class);
    $queue->notify('vendor', $this->vendor, 'New vendor awaiting review', 'manual-event');
    $queue->notify('vendor', $this->vendor, 'New vendor awaiting review', 'manual-event');
    expect(DB::table('notifications')->where('notifiable_id', $this->reviewer->id)->count())->toBe(3);
    $response = $this->getJson('/v1/operations/review-notifications')->assertOk()->assertJsonPath('data.unread', 3);
    $this->postJson('/v1/operations/review-notifications/read', ['id' => $response->json('data.notifications.data.0.id')])->assertOk();
    $this->getJson('/v1/operations/review-notifications')->assertJsonPath('data.unread', 2);
    $this->postJson('/v1/operations/review-notifications/read', ['all' => true])->assertOk();
    $this->getJson('/v1/operations/review-notifications')->assertJsonPath('data.unread', 0)->assertJsonPath('data.counts.vendors', 1);
});
it('isolates notification reads per user and restricts permissions and portal access', function () {
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'Example@2026', 'type' => 'paperstic', 'is_active' => true]);
    $other->givePermissionTo('operations.kyc.view');
    app(ReviewQueue::class)->notify('vendor', $this->vendor, 'New vendor', 'other-user');
    $notification = DB::table('notifications')->where('notifiable_id', $other->id)->first();
    $this->postJson('/v1/operations/review-notifications/read', ['id' => $notification->id])->assertOk();
    expect(DB::table('notifications')->where('id', $notification->id)->value('read_at'))->toBeNull();
    $this->actingAs($other, 'api')->postJson(profileReviewUrl($this->vendor).'/decision', ['decision' => 'approved', 'revision' => $this->getJson(profileReviewUrl($this->vendor))->json('data.entity.revision')])->assertForbidden();
    $other->update(['type' => 'owner']);
    $this->actingAs($other->fresh(), 'api')->getJson('/v1/operations/reviews?scope=vendor')->assertForbidden();
    $this->getJson('/v1/operations/review-notifications')->assertForbidden();
});

it('creates only one initial summary alert for historical work', function () {
    DB::table('notifications')->delete();
    $this->getJson('/v1/operations/review-notifications')->assertOk()->assertJsonPath('data.unread', 1)->assertJsonPath('data.notifications.data.0.data.scope', 'all');
    $this->postJson('/v1/operations/review-notifications/read', ['all' => true])->assertOk();
    $this->getJson('/v1/operations/review-notifications')->assertJsonPath('data.unread', 0)->assertJsonCount(1, 'data.notifications.data');
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertJsonPath('data.reviews.total', 1);
});
it('invalidates an information decision when new documents arrive', function () {
    $url = profileReviewUrl($this->vendor);
    $revision = $this->getJson($url)->json('data.entity.revision');
    reviewDocumentFor($this->vendor);
    $this->postJson($url.'/decision', ['decision' => 'approved', 'revision' => $revision])->assertConflict();
});
it('uses the same country-specific required document rules as the Business portal', function () {
    $required = \App\Models\Business\ComplianceDocumentType::create(['key' => 'required_registration', 'name' => 'Required registration', 'scope' => 'vendor', 'is_active' => true]);
    $optional = \App\Models\Business\ComplianceDocumentType::create(['key' => 'optional_registration', 'name' => 'Optional registration', 'scope' => 'vendor', 'is_active' => true]);
    foreach ([$required, $optional] as $type) {
        DB::table('compliance_document_type_rules')->insert(['compliance_document_type_id' => $type->id, 'country_id' => $this->vendor->country_id, 'required_for_payment_activation' => $type->id === $required->id, 'is_active' => true, 'uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->getJson('/v1/operations/reviews?scope=vendor')->assertOk()->assertJsonPath('data.reviews.data.0.missing_requirements', 1);
    $this->getJson(profileReviewUrl($this->vendor))->assertOk()->assertJsonCount(2, 'data.requirements');
});
it('preserves business VAT document behavior without automatically activating a business', function () {
    $document = reviewDocumentFor($this->business);
    $document->update(['document_type' => 'vat_registration_certificate']);
    $revision = $this->getJson(profileReviewUrl($this->business))->json('data.documents.0.revision');
    $this->postJson('/v1/operations/review-documents/'.$document->uuid.'/decision', ['decision' => 'approved', 'revision' => $revision])->assertOk();
    expect((bool) $this->business->fresh()->tax_allowed)->toBeTrue();
    expect((bool) $this->business->fresh()->is_active)->toBeFalse();
    $this->getJson('/v1/operations/reviews?scope=business')->assertJsonPath('data.reviews.total', 1);
});
