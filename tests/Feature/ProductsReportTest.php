<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $vendor = Vendor::create(['name' => 'Vendor', 'email' => 'product-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Restaurant', 'vendor_id' => $vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->owner = User::create(['name' => 'Owner', 'email' => 'product-owner@example.test', 'password' => 'Example@2026', 'type' => 'owner', 'is_active' => true]);
    $this->owner->vendors()->attach($vendor->id, ['is_primary' => true, 'is_active' => true]);
    DB::table('categories')->insert(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'name' => 'Drinks']);
    $this->section = DB::table('sections')->insertGetId(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'user_id' => $this->owner->id, 'name' => 'Restaurant']);
    $this->subsection = DB::table('sub_sections')->insertGetId(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'section_id' => $this->section, 'user_id' => $this->owner->id, 'name' => 'Terrace']);
    $this->point = DB::table('service_points')->insertGetId(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'section_id' => $this->section, 'sub_section_id' => $this->subsection, 'type' => 'table', 'label' => 'T1', 'display_name' => 'Table 1']);
    $this->completed = DB::table('order_statuses')->where('name', 'Completed')->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => 'Completed']);
    $this->cancelled = DB::table('order_statuses')->where('name', 'Cancelled')->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => 'Cancelled']);
    $this->pending = DB::table('order_statuses')->where('name', 'Pending')->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => 'Pending']);
    $this->paid = DB::table('payment_statuses')->where('name', 'Completed')->value('id') ?? DB::table('payment_statuses')->insertGetId(['name' => 'Completed']);
    $this->unpaid = DB::table('payment_statuses')->where('name', 'Pending')->value('id') ?? DB::table('payment_statuses')->insertGetId(['name' => 'Pending']);
    $this->url = '/v1/businesses/'.$this->business->uuid.'/reports';
    $this->query = '?view=products&date_from=2026-10-26&date_to=2026-10-26';
    $this->actingAs($this->owner, 'api');
});
function productReportItem(string $name): int
{
    return DB::table('items')->insertGetId(['uuid' => Str::uuid(), 'category_id' => 1, 'name' => $name, 'description' => '', 'price' => 10000, 'currency' => 'TZS', 'discount' => 0, 'final_price' => 10000]);
}
function productReportSale($test, int $product, array $change = [], array $line = []): void
{
    $id = DB::table('orders')->insertGetId([...['uuid' => Str::uuid(), 'business_id' => $test->business->id, 'number' => 'ORDER-'.Str::random(10), 'service_point_id' => $test->point, 'channel' => 'dine_in', 'order_status_id' => $test->completed, 'payment_status_id' => $test->paid, 'created_at' => '2026-10-25 22:00:00'], ...$change]);
    DB::table('order_items')->insert([...['uuid' => Str::uuid(), 'order_id' => $id, 'item_id' => $product, 'quantity' => 2, 'unit_price' => 10000, 'discount' => 0, 'discount_amount' => 2000, 'final_price' => 9500, 'total_amount' => 19000], ...$line]);
}

it('groups product revenue by section and subsection with accurate totals across paginated results', function () {
    $coffee = productReportItem('Coffee');
    productReportSale($this, $coffee);
    productReportSale($this, $coffee, [], ['quantity' => 1, 'total_amount' => 9500]);
    productReportSale($this, $coffee, ['service_point_id' => null, 'channel' => 'online'], ['quantity' => 1, 'total_amount' => 4000]);
    productReportSale($this, $coffee, ['order_status_id' => $this->pending, 'payment_status_id' => $this->unpaid], ['quantity' => 1, 'total_amount' => 1000]);
    productReportSale($this, $coffee, ['order_status_id' => $this->cancelled], ['quantity' => 1, 'total_amount' => 9999]);
    $refunded = DB::table('payment_statuses')->where('name', 'Refunded')->value('id') ?? DB::table('payment_statuses')->insertGetId(['name' => 'Refunded']);
    productReportSale($this, $coffee, ['payment_status_id' => $refunded], ['quantity' => 1, 'total_amount' => 8888]);
    productReportSale($this, $coffee, ['created_at' => '2026-10-25 20:59:59']);
    for ($i = 0; $i < 15; $i++) {
        productReportSale($this, productReportItem('Dish '.str_pad($i, 2, '0', STR_PAD_LEFT)), [], ['quantity' => 1, 'total_amount' => 1000]);
    }
    $response = $this->getJson($this->url.$this->query.'&per_page=10&page=2')->assertOk()->assertJsonPath('data.rows.meta.total', 17)->assertJsonCount(7, 'data.rows.data')->assertJsonPath('data.timezone', 'Africa/Dar_es_Salaam');
    $restaurant = collect($response->json('data.groups.sections'))->firstWhere('section', 'Restaurant');
    expect((float) $restaurant['revenue'])->toBe(43500.0);
    $this->getJson($this->url.$this->query.'&search=coffee&section_id='.$this->section)->assertOk()->assertJsonPath('data.rows.meta.total', 1)->assertJsonPath('data.rows.data.0.category', 'Drinks')->assertJsonPath('data.rows.data.0.quantity', 3)->assertJsonPath('data.rows.data.0.orders', 2)->assertJsonPath('data.rows.data.0.discount_amount', 4000)->assertJsonPath('data.rows.data.0.revenue', 28500);
    $this->getJson($this->url.$this->query.'&search=coffee&sales_scope=all')->assertOk()->assertJsonPath('data.rows.meta.total', 2)->assertJsonPath('data.rows.data.0.revenue', 29500);
    $this->getJson($this->url.$this->query.'&channel=online')->assertOk()->assertJsonPath('data.rows.data.0.section', 'Online')->assertJsonPath('data.rows.data.0.revenue', 4000);
    $this->getJson($this->url.$this->query.'&sort=revenue&direction=asc')->assertOk()->assertJsonPath('data.rows.data.0.revenue', 1000);
    $this->getJson($this->url.$this->query.'&sort=category&direction=asc')->assertOk()->assertJsonPath('data.rows.data.0.category', 'Drinks');
    $this->getJson($this->url.$this->query.'&sort=discount&direction=desc')->assertOk()->assertJsonPath('data.rows.data.0.discount_amount', 4000);
    $this->getJson($this->url.$this->query.'&sort=invalid')->assertUnprocessable();
    $response = $this->get($this->url.'/products/export'.$this->query.'&per_page=10&page=2')->assertOk()->assertDownload('Paperstic-products-2026-10-26-to-2026-10-26.xlsx');
    $zip = new ZipArchive;
    $path = $response->baseResponse->getFile()->getPathname();
    expect($zip->open($path))->toBeTrue();
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);
    expect(substr_count($xml, '<row>'))->toBe(18);
    expect($xml)->toContain('Coffee', 'Drinks', 'Terrace', 'Online', 'Africa/Dar_es_Salaam', '<v>4000</v>', '<v>43500</v>')->not->toContain('<v>9999</v>', '<v>8888</v>');
});

it('denies product report and exports to staff without reporting access', function () {
    $waiter = User::create(['name' => 'Waiter', 'email' => 'product-waiter@example.test', 'password' => 'Example@2026', 'type' => 'business', 'is_active' => true]);
    $waiter->businesses()->attach($this->business->id, ['business_role' => 'waiter', 'title' => 'Waiter', 'is_active' => true]);
    $this->actingAs($waiter, 'api')->getJson($this->url.$this->query)->assertForbidden();
    $this->get($this->url.'/products/export'.$this->query)->assertForbidden();
    $waiter->businesses()->updateExistingPivot($this->business->id, ['business_role' => 'manager']);
    $this->getJson($this->url.$this->query)->assertOk();
});
