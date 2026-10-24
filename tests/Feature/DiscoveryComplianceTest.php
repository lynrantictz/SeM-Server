<?php

use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\ComplianceDocumentType;
use App\Models\Business\ComplianceDocumentTypeRule;
use App\Models\Business\OrderingChannel;
use App\Models\Business\Vendor;
use App\Models\Location\City;
use App\Models\Location\Country;
use App\Models\Location\District;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $this->city = City::create(['country_id' => $this->country->id, 'name' => 'Dar']);
    $this->district = District::create(['city_id' => $this->city->id, 'name' => 'Central']);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->vendor = Vendor::create(['country_id' => $this->country->id, 'name' => 'Vendor', 'email' => 'discovery@example.test']);
    $this->business = Business::create(['vendor_id' => $this->vendor->id, 'district_id' => $this->district->id, 'business_type_id' => 1, 'name' => 'Eligible restaurant', 'location' => 'Dar', 'is_active' => true, 'latitude' => -6.8, 'longitude' => 39.2, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    OrderingChannel::firstOrCreate(['slug' => 'dine_in'], ['name' => 'Dine in', 'is_active' => true]);
    $this->vendorType = ComplianceDocumentType::create(['key' => 'vendor_required', 'name' => 'Vendor registration', 'scope' => 'vendor', 'is_active' => true]);
    $this->businessType = ComplianceDocumentType::create(['key' => 'business_required', 'name' => 'Business licence', 'scope' => 'business', 'is_active' => true]);
    foreach ([$this->vendorType, $this->businessType] as $type) {
        ComplianceDocumentTypeRule::create(['compliance_document_type_id' => $type->id, 'required_for_payment_activation' => true, 'is_active' => true]);
    }
    $this->vendorDocument = discoveryApprovedDocument($this->vendor, $this->vendorType);
    $this->businessDocument = discoveryApprovedDocument($this->business, $this->businessType);
});

function discoveryApprovedDocument(Vendor|Business $owner, ComplianceDocumentType $type): ComplianceDocument
{
    return ComplianceDocument::create([($owner instanceof Vendor ? 'vendor_id' : 'business_id') => $owner->id, 'compliance_document_type_id' => $type->id, 'document_type' => $type->key, 'document_type_name' => $type->name, 'status' => 'approved', 'disk' => 'local', 'storage_path' => 'test/document.pdf', 'original_filename' => 'document.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'expires_at' => null]);
}

it('shows an active compliant business in maps, search, regions and menu discovery', function () {
    $this->getJson('/v1/discover/businesses')->assertOk()->assertJsonCount(1, 'data.businesses')->assertJsonPath('data.businesses.0.uuid', $this->business->uuid);
    $this->getJson('/v1/discover/businesses?q=Eligible')->assertOk()->assertJsonCount(1, 'data.businesses');
    $this->getJson('/v1/discover/regions?country_id='.$this->country->id)->assertOk()->assertJsonCount(1, 'data.regions');
    $this->getJson('/v1/discover/businesses/'.$this->business->uuid.'/menu')->assertOk();
    $this->postJson('/v1/discover/interactions', ['business_uuid' => $this->business->uuid, 'event_type' => 'tap'])->assertOk();
});

it('hides businesses with unapproved vendor or business documents on every discovery endpoint', function (string $scope, string $status) {
    $document = $scope === 'vendor' ? $this->vendorDocument : $this->businessDocument;
    if ($status === 'missing') {
        $document->delete();
    } else {
        $document->update(['status' => $status]);
    }
    $this->getJson('/v1/discover/businesses?q=Eligible')->assertOk()->assertJsonCount(0, 'data.businesses');
    $this->getJson('/v1/discover/regions')->assertOk()->assertJsonCount(0, 'data.regions');
    $this->getJson('/v1/discover/businesses/'.$this->business->uuid.'/menu')->assertNotFound();
    $this->postJson('/v1/discover/interactions', ['business_uuid' => $this->business->uuid, 'event_type' => 'tap'])->assertNotFound();
})->with([
    ['vendor', 'missing'], ['vendor', 'pending'], ['vendor', 'in_review'], ['vendor', 'needs_review'], ['vendor', 'needs_update'], ['vendor', 'rejected'], ['vendor', 'expired'],
    ['business', 'missing'], ['business', 'pending'], ['business', 'in_review'], ['business', 'needs_review'], ['business', 'needs_update'], ['business', 'rejected'], ['business', 'expired'],
]);

it('rechecks expiration without changing business active status', function (string $scope) {
    $document = $scope === 'vendor' ? $this->vendorDocument : $this->businessDocument;
    $document->update(['expires_at' => today()->addDays(2)]);
    $this->getJson('/v1/discover/businesses')->assertJsonCount(1, 'data.businesses');
    $this->travel(3)->days();
    $this->getJson('/v1/discover/businesses')->assertJsonCount(0, 'data.businesses');
    $this->getJson('/v1/discover/businesses/'.$this->business->uuid.'/menu')->assertNotFound();
    expect((bool) $this->business->fresh()->is_active)->toBeTrue();
})->with(['vendor', 'business']);

it('requires active status and both coordinates even when compliance is approved', function (array $changes) {
    $this->business->update($changes);
    $this->getJson('/v1/discover/businesses')->assertJsonCount(0, 'data.businesses');
    $this->getJson('/v1/discover/regions')->assertJsonCount(0, 'data.regions');
    $this->getJson('/v1/discover/businesses/'.$this->business->uuid.'/menu')->assertNotFound();
})->with([[['is_active' => false]], [['latitude' => null]], [['longitude' => null]]]);

it('uses the newest submission rather than an older approved document', function () {
    $new = discoveryApprovedDocument($this->business, $this->businessType);
    $new->update(['status' => 'pending']);
    $this->getJson('/v1/discover/businesses')->assertJsonCount(0, 'data.businesses');
    $new->update(['status' => 'approved']);
    $this->getJson('/v1/discover/businesses')->assertJsonCount(1, 'data.businesses');
});

it('does not let an optional document or a less-specific rule block discovery', function () {
    // Country-specific optional status overrides the global required rule.
    ComplianceDocumentTypeRule::create(['compliance_document_type_id' => $this->vendorType->id, 'country_id' => $this->country->id, 'required_for_payment_activation' => false, 'is_active' => true]);
    $this->vendorDocument->update(['status' => 'needs_update']);
    // Most specific business type rule overrides global required status too.
    ComplianceDocumentTypeRule::create(['compliance_document_type_id' => $this->businessType->id, 'country_id' => $this->country->id, 'business_type_id' => 1, 'required_for_payment_activation' => false, 'is_active' => true]);
    $this->businessDocument->delete();
    $this->getJson('/v1/discover/businesses')->assertOk()->assertJsonCount(1, 'data.businesses');
    $this->getJson('/v1/discover/regions')->assertOk()->assertJsonCount(1, 'data.regions');
});

it('does not apply another country or business type requirement', function () {
    $other = Country::create(['name' => 'Kenya', 'iso2' => 'KE', 'iso3' => 'KEN', 'currency' => 'KES', 'phone_code' => '254', 'flag' => 'flags/ke.jpg']);
    DB::table('business_types')->insert(['name' => 'Hotel']);
    $type = ComplianceDocumentType::create(['key' => 'other_requirement', 'name' => 'Other context only', 'scope' => 'business', 'is_active' => true]);
    ComplianceDocumentTypeRule::create(['compliance_document_type_id' => $type->id, 'country_id' => $other->id, 'required_for_payment_activation' => true, 'is_active' => true]);
    ComplianceDocumentTypeRule::create(['compliance_document_type_id' => $type->id, 'business_type_id' => 2, 'required_for_payment_activation' => true, 'is_active' => true]);
    $this->getJson('/v1/discover/businesses')->assertOk()->assertJsonCount(1, 'data.businesses');
});

it('filters compliance before limits and excludes hidden coordinates from regional averages', function () {
    $hidden = Business::create(['vendor_id' => $this->vendor->id, 'district_id' => $this->district->id, 'business_type_id' => 1, 'name' => 'Hidden restaurant', 'location' => 'Dar', 'is_active' => true, 'latitude' => -10, 'longitude' => 35, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8), 'created_at' => now()->addMinute()]);
    $this->getJson('/v1/discover/businesses?limit=1')->assertOk()->assertJsonCount(1, 'data.businesses')->assertJsonPath('data.businesses.0.uuid', $this->business->uuid);
    $regions = $this->getJson('/v1/discover/regions')->assertOk()->json('data.regions');
    expect((float) $regions[0]['latitude'])->toBe(-6.8);
    expect((float) $regions[0]['longitude'])->toBe(39.2);
    // Approval for another business cannot satisfy the hidden business's documents.
    $this->getJson('/v1/discover/businesses/'.$hidden->uuid.'/menu')->assertNotFound();
});
