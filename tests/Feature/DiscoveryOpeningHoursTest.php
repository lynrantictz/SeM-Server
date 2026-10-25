<?php

use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\Location\City;
use App\Models\Location\Country;
use App\Models\Location\District;
use App\Services\Discovery\OpeningHours;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = City::create(['country_id' => $country->id, 'name' => 'Dar']);
    $district = District::create(['city_id' => $city->id, 'name' => 'Central']);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $vendor = Vendor::create(['country_id' => $country->id, 'name' => 'Vendor', 'email' => 'hours@example.test']);
    $this->business = Business::create(['vendor_id' => $vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'name' => 'Hours restaurant', 'location' => 'Dar', 'is_active' => true, 'latitude' => -6.8, 'longitude' => 39.2, 'timezone' => 'Africa/Dar_es_Salaam', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
});
afterEach(fn () => Carbon::setTestNow());

function discoveryHoursFor(Business $business, int $day, ?string $opens, ?string $closes, bool $closed = false): void
{
    $business->openingHours()->create(['day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes, 'is_closed' => $closed]);
}
function discoveryHoursStatus(Business $business, string $instant): array
{
    return app(OpeningHours::class)->status($business->fresh(), Carbon::parse($instant));
}
it('reports unknown hours instead of inventing an open status', function () {
    $status = discoveryHoursStatus($this->business, '2026-10-26 12:00 UTC');
    expect($status['open_now'])->toBeNull();
    expect($status['opening_hours'])->toBe('Hours not provided');
    expect($status['closes_at'])->toBeNull();
});
it('calculates opening and closing boundaries in the business timezone', function (string $at, bool $open, string $label) {
    discoveryHoursFor($this->business, 1, '09:00', '22:00');
    $status = discoveryHoursStatus($this->business, $at);
    expect($status['open_now'])->toBe($open);
    expect($status['opening_hours'])->toBe($label);
    if ($open) expect($status['closes_at'])->toBe('2026-10-26T22:00:00+03:00');
})->with([
    ['2026-10-26 05:59:59 UTC', false, 'Opens at 9:00 am'],
    ['2026-10-26 06:00:00 UTC', true, 'Closes at 10:00 pm'],
    ['2026-10-26 18:59:59 UTC', true, 'Closes at 10:00 pm'],
    ['2026-10-26 19:00:00 UTC', false, 'Opens Monday at 9:00 am'],
]);
it('carries overnight hours into a closed following day without opening early on the starting day', function () {
    discoveryHoursFor($this->business, 1, '20:00', '02:00');
    discoveryHoursFor($this->business, 2, null, null, true);
    expect(discoveryHoursStatus($this->business, '2026-10-26 01:00 Africa/Dar_es_Salaam')['open_now'])->toBeFalse();
    $evening = discoveryHoursStatus($this->business, '2026-10-26 21:00 Africa/Dar_es_Salaam');
    expect($evening['opening_hours'])->toBe('Closes at 2:00 am tomorrow');
    $overnight = discoveryHoursStatus($this->business, '2026-10-27 01:00 Africa/Dar_es_Salaam');
    expect($overnight['open_now'])->toBeTrue();
    expect($overnight['closes_at'])->toBe('2026-10-27T02:00:00+03:00');
    expect(discoveryHoursStatus($this->business, '2026-10-27 02:00 Africa/Dar_es_Salaam')['open_now'])->toBeFalse();
});
it('reports the next opening after a break and merges overlapping shifts', function () {
    discoveryHoursFor($this->business, 1, '09:00', '12:00');
    discoveryHoursFor($this->business, 1, '14:00', '22:00');
    discoveryHoursFor($this->business, 1, '21:00', '23:00');
    expect(discoveryHoursStatus($this->business, '2026-10-26 13:00 Africa/Dar_es_Salaam')['opening_hours'])->toBe('Opens at 2:00 pm');
    expect(discoveryHoursStatus($this->business, '2026-10-26 20:00 Africa/Dar_es_Salaam')['opening_hours'])->toBe('Closes at 11:00 pm');
});
it('supports 24-hour weekly schedules and explicitly closed weeks', function () {
    foreach (range(0, 6) as $day) discoveryHoursFor($this->business, $day, '00:00', '00:00');
    $status = discoveryHoursStatus($this->business, '2026-10-26 12:00 UTC');
    expect($status['open_now'])->toBeTrue();
    expect($status['opening_hours'])->toBe('Open 24 hours');
    expect($status['closes_at'])->toBeNull();
    $this->business->openingHours()->update(['is_closed' => true]);
    $closed = discoveryHoursStatus($this->business, '2026-10-26 12:00 UTC');
    expect($closed['open_now'])->toBeFalse();
    expect($closed['opening_hours'])->toBe('No opening time scheduled');
});
it('uses the selected timezone definition when determining the local weekday', function () {
    discoveryHoursFor($this->business, 2, '09:00', '12:00');
    $business = $this->business->fresh();
    $business->setRelation('timezoneDefinition', new \App\Models\Business\Timezone(['identifier' => 'Asia/Tokyo']));
    $status = app(OpeningHours::class)->status($business, Carbon::parse('2026-10-26 23:00 UTC'));
    expect($status['open_now'])->toBeFalse();
    expect($status['opening_hours'])->toBe('Opens at 9:00 am');
    expect($status['timezone'])->toBe('Asia/Tokyo');
});
it('returns real status and closing time from the discovery API and rechecks after closing', function () {
    discoveryHoursFor($this->business, 1, '09:00', '22:00');
    discoveryHoursFor($this->business, 2, '09:00', '22:00');
    Carbon::setTestNow('2026-10-26 12:00:00 UTC');
    $this->getJson('/v1/discover/businesses')->assertOk()->assertJsonPath('data.businesses.0.country', 'Tanzania')->assertJsonPath('data.businesses.0.country_iso2', 'TZ')->assertJsonPath('data.businesses.0.open_now', true)->assertJsonPath('data.businesses.0.opening_hours', 'Closes at 10:00 pm')->assertJsonPath('data.businesses.0.closes_at', '2026-10-26T22:00:00+03:00');
    Carbon::setTestNow('2026-10-26 19:00:00 UTC');
    $this->getJson('/v1/discover/businesses')->assertOk()->assertJsonPath('data.businesses.0.open_now', false)->assertJsonPath('data.businesses.0.opening_hours', 'Opens tomorrow at 9:00 am');
});
