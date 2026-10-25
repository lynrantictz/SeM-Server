<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\BusinessInvoice;
use App\Models\Business\OrderPlatformFee;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Notifications\BusinessInvoiceNotification;
use App\Services\Business\BusinessBillingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00 UTC');
    config(['business_billing.issuer' => ['name' => 'Paperstic', 'email' => 'billing@paperstic.example']]);
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->vendor = Vendor::create(['name' => 'Billing vendor', 'email' => 'billing-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Billing restaurant', 'vendor_id' => $this->vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->business->paymentSetting()->create(['provider' => 'manual', 'currency' => 'TZS', 'commission_rate' => 3, 'commission_basis' => 'subtotal_excluding_tax', 'fee_bearer' => 'business', 'settlement_mode' => 'manual_hold']);
    $this->operator = User::create(['name' => 'Billing operator', 'email' => 'billing-ops@example.test', 'type' => 'paperstic', 'password' => 'Example@2026', 'is_active' => true]);
    foreach (['operations.businesses.view', 'operations.settlements.manage'] as $permission) {
        Permission::findOrCreate($permission, 'api');
        $this->operator->givePermissionTo($permission);
    }
    $this->owner = User::create(['name' => 'Owner', 'email' => 'billing-owner@example.test', 'type' => 'owner', 'password' => 'Example@2026', 'is_active' => true]);
    $this->owner->vendors()->attach($this->vendor->id, ['is_primary' => true, 'is_active' => true]);
    $this->url = '/v1/operations/businesses/'.$this->business->uuid.'/billing';
    $this->payload = ['automatic_invoicing' => false, 'enforce_overdue' => false, 'billing_email' => 'billing-owner@example.test', 'frequency' => 'weekly', 'interval_days' => 7, 'trial_days' => 0, 'fee_starts_at' => null, 'next_invoice_at' => null, 'grace_hours' => 72, 'payment_instructions' => 'Pay Paperstic using reference shown on this invoice.'];
    $this->service = app(BusinessBillingService::class);
    $this->actingAs($this->operator, 'api');
});
afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});
function invoicePdfOrder($test, string $status = 'Completed', string $payment = 'Completed'): Order
{
    $statusId = DB::table('order_statuses')->where('name', $status)->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => $status]);
    $paymentId = DB::table('payment_statuses')->where('name', $payment)->value('id') ?? DB::table('payment_statuses')->insertGetId(['name' => $payment]);

    return Order::create(['business_id' => $test->business->id, 'number' => 'SALE-'.Str::random(10), 'channel' => 'dine_in', 'total_items_amount' => 10000, 'tax_amount' => 1800, 'total_amount' => 11800, 'paid_amount' => $payment === 'Completed' ? 11800 : 0, 'due_amount' => $payment === 'Completed' ? 0 : 11800, 'order_status_id' => $statusId, 'payment_status_id' => $paymentId]);
}
function invoicePdfPolicy($test): BusinessBillingPolicy
{
    $test->patchJson($test->url, [...$test->payload, 'automatic_invoicing' => true, 'enforce_overdue' => true, 'next_invoice_at' => now()->addDays(7)->toIso8601String()])->assertOk();

    return BusinessBillingPolicy::where('business_id', $test->business->id)->firstOrFail();
}
function invoicePdfGenerate($test): BusinessInvoice
{
    $policy = invoicePdfPolicy($test);
    invoicePdfOrder($test);
    Carbon::setTestNow($policy->next_invoice_at);

    return $test->service->generate($policy)[0];
}
it('summarizes a seven day period with 100 orders and mixed rates without listing orders in the email or PDF', function () {
    $policy = invoicePdfPolicy($this);
    $numbers = [];
    for ($i = 0; $i < 50; $i++) {
        $numbers[] = invoicePdfOrder($this)->number;
    }
    $service = app(\App\Services\Business\BusinessCommissionService::class);
    $current = $service->current($this->business);
    $service->save($this->business, ['action' => 'publish', 'rate' => 5, 'apply_now' => true, 'reason' => 'New agreed rate', 'expected_policy_uuid' => $current->uuid], $this->operator->id);
    for ($i = 0; $i < 50; $i++) {
        $numbers[] = invoicePdfOrder($this)->number;
    }
    invoicePdfOrder($this, 'Cancelled');
    invoicePdfOrder($this, 'Processing');
    invoicePdfOrder($this, 'Completed', 'Pending');
    Carbon::setTestNow($policy->next_invoice_at);
    $boundary = invoicePdfOrder($this);
    $invoice = $this->service->generate($policy)[0];
    expect($invoice->order_count)->toBe(100);
    expect((float) $invoice->gross_sales)->toBe(1180000.0);
    expect((float) $invoice->order_tax_total)->toBe(180000.0);
    expect((float) $invoice->commission_base_total)->toBe(1000000.0);
    expect((float) $invoice->total)->toBe(40000.0);
    expect($invoice->rate_breakdown)->toHaveCount(2);
    expect($invoice->period_from->diffInDays($invoice->period_to))->toBe(7.0);
    expect($invoice->items()->count())->toBe(100);
    expect($this->service->generate($policy))->toBe([]);
    expect(OrderPlatformFee::where('order_id', $boundary->id)->value('status'))->toBe('accrued');
    $invoice->due_at = now()->addHours(72);
    $mail = (new BusinessInvoiceNotification($invoice))->toMail(new stdClass);
    $html = (string) $mail->render();
    expect($html)->toContain('100', 'TZS 1,180,000.00', 'TZS 40,000.00', 'PDF invoice is attached')->not->toContain($numbers[0], $numbers[99]);
    expect($mail->rawAttachments)->toHaveCount(1);
    $attachment = $mail->rawAttachments[0];
    expect($attachment['options']['mime'])->toBe('application/pdf');
    expect($attachment['data'])->toStartWith('%PDF-');
    $pdf = app(\App\Services\Business\BusinessInvoicePdfService::class);
    $pdfHtml = view('invoices.business-invoice', $pdf->viewData($invoice))->render();
    expect($pdfHtml)->toContain('Invoice', 'Commission summary', '40,000.00')->not->toContain($numbers[0], $numbers[99]);
    if ($output = getenv('PAPERSTIC_PDF_PREVIEW_PATH')) {
        file_put_contents($output, $attachment['data']);
    }
    $this->business->update(['name' => 'Changed business name']);
    DB::table('orders')->where('business_id', $this->business->id)->update(['total_amount' => 1, 'tax_amount' => 0]);
    $invoice = $invoice->fresh();
    expect((float) $invoice->gross_sales)->toBe(1180000.0);
    expect($invoice->recipient_snapshot['business'])->toBe('Billing restaurant');
    Carbon::setTestNow($policy->fresh()->next_invoice_at);
    $second = $this->service->generate($policy->fresh())[0];
    expect($second->order_count)->toBe(1);
    expect($second->items()->first()->order_number)->toBe($boundary->number);
});
it('includes free and no-commission eligible sales in mixed invoices but never sends an all-zero demand', function () {
    $policy = invoicePdfPolicy($this);
    $policy->update(['fee_starts_at' => now()->addHour()]);
    invoicePdfOrder($this);
    Carbon::setTestNow(now()->addHours(2));
    invoicePdfOrder($this);
    Carbon::setTestNow($policy->next_invoice_at);
    $invoice = $this->service->generate($policy)[0];
    expect($invoice->order_count)->toBe(2);
    expect((float) $invoice->gross_sales)->toBe(23600.0);
    expect((float) $invoice->total)->toBe(300.0);
    expect($invoice->rate_breakdown)->toHaveCount(2);
    $zero = \App\Models\Business\BusinessCommissionPolicy::where('business_id', $this->business->id)->where('state', 'published')->latest('id')->first();
    app(\App\Services\Business\BusinessCommissionService::class)->save($this->business, ['rate' => 0, 'action' => 'publish', 'apply_now' => true, 'reason' => 'No commission agreement', 'expected_policy_uuid' => $zero->uuid], $this->operator->id);
    invoicePdfOrder($this);
    Carbon::setTestNow($policy->fresh()->next_invoice_at);
    expect($this->service->generate($policy->fresh()))->toBe([]);
    expect(BusinessInvoice::count())->toBe(1);
});
it('protects PDF downloads by business scope and management permission', function () {
    $invoice = invoicePdfGenerate($this);
    $url = $this->url.'/invoices/'.$invoice->uuid.'/pdf';
    $this->get($url, ['Accept' => 'application/pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->owner, 'api')->get('/v1/businesses/'.$this->business->uuid.'/billing/invoices/'.$invoice->uuid.'/pdf', ['Accept' => 'application/pdf'])->assertOk();
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->save();
    $this->actingAs($this->operator, 'api')->getJson('/v1/operations/businesses/'.$other->uuid.'/billing/invoices/'.$invoice->uuid.'/pdf')->assertNotFound();
    $this->actingAs($this->owner, 'api');
    $this->owner->update(['type' => 'business']);
    $this->owner->businesses()->attach($this->business->id, ['business_role' => 'waiter', 'title' => 'Waiter', 'is_active' => true]);
    $this->getJson('/v1/businesses/'.$this->business->uuid.'/billing/invoices/'.$invoice->uuid.'/pdf')->assertForbidden();
});
it('does not invent missing gross sales on historical invoices or write during downloads', function () {
    $invoice = invoicePdfGenerate($this);
    $invoice->update(['order_count' => null, 'gross_sales' => null, 'order_tax_total' => null, 'commission_base_total' => null, 'rate_breakdown' => null, 'issuer_snapshot' => null, 'recipient_snapshot' => null]);
    $summary = $invoice->fresh()->summary();
    expect($summary['legacy'])->toBeTrue();
    expect($summary['order_count'])->toBe(1);
    expect($summary['gross_sales'])->toBeNull();
    expect((float) $summary['commission_base_total'])->toBe(10000.0);
    $before = DB::table('business_invoices')->where('id', $invoice->id)->first();
    expect(app(\App\Services\Business\BusinessInvoicePdfService::class)->render($invoice))->toStartWith('%PDF-');
    expect(DB::table('business_invoices')->where('id', $invoice->id)->first())->toEqual($before);
});
it('keeps invoices unsent without a deadline when PDF generation fails', function () {
    $invoice = invoicePdfGenerate($this);
    $this->mock(\App\Services\Business\BusinessInvoicePdfService::class, function ($mock) {
        $mock->shouldReceive('viewData')->andThrow(new RuntimeException('PDF generation failed'));
    });
    expect($this->service->dispatchInvoice($invoice))->toBeFalse();
    expect($invoice->fresh()->sent_at)->toBeNull();
    expect($invoice->fresh()->due_at)->toBeNull();
    expect($this->service->restricted($this->business))->toBeFalse();
});
it('keeps accrued catch-up fees in separate seven-day invoices instead of enlarging a period', function () {
    invoicePdfOrder($this);
    Carbon::setTestNow(now()->addDays(14));
    $policy = invoicePdfPolicy($this);
    invoicePdfOrder($this);
    Carbon::setTestNow($policy->next_invoice_at);
    $invoices = $this->service->generate($policy);
    expect($invoices)->toHaveCount(2);
    foreach ($invoices as $invoice) {
        expect($invoice->period_from->diffInDays($invoice->period_to))->toBe(7.0);
        expect($invoice->order_count)->toBe(1);
    }
});
