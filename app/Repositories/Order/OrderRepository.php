<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderStatus;
use App\Models\Order\OrderStatusHistory;
use App\Models\Section\Code;
use App\Models\Section\ServicePoint;
use App\Models\Business\Business;
use App\Models\Business\OrderingChannel;
use App\Models\Customer\Customer;
use App\Repositories\BaseRepository;
use App\Repositories\Customer\CustomerRepository;
use App\Services\TaxCalculatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrderRepository extends BaseRepository
{
    const MODEL = Order::class;

    use TaxCalculatorService;

    /**
     * Inputs for storing order
     */
    public function inputManipulator(Code $code, $inputs, ?Customer $verifiedCustomer = null): array
    {
        $business = $code->codable->business;
        $country = $business->district?->city?->country?->iso2;
        $customer = $verifiedCustomer ?? (new CustomerRepository())->getCustomerByPhone($inputs['phone'], $country);
        $servicePoint = $code->codable instanceof ServicePoint ? $code->codable : null;
        return [
            'business_id' => $business->id,
            'user_id' => null, //TODO:: update later when a registered user is making order
            'customer_id' => $customer->id,
            'order_status_id' => config('constants.order_status.PENDING'),
            'payment_status_id' => config('constants.payment_status.PENDING'),
            'service_point_id' => $servicePoint?->id,
            'service_point_label' => $servicePoint?->display_name,
        ];
    }

    /**
     * Store new order
     */
    public function store(Code $code, $inputs, string $channel = 'dine_in', ?Customer $verifiedCustomer = null): object
    {
        return DB::transaction(function () use ($code, $inputs, $channel, $verifiedCustomer) {
            // Lock the business row for update to prevent race conditions
            $business = $code->codable->business()->lockForUpdate()->first();
            // Increment the order number
            $business->current_order_number += 1;
            // Generate the order_number
            $orderNumber = $business->order_prefix . "-" . str_pad($business->current_order_number, 6, '0', STR_PAD_LEFT);
            // Extra safety: check if this order number already exists
            while ($code->orders()->where('number', $orderNumber)->exists()) {
                $business->current_order_number += 1;
                $orderNumber = $business->order_prefix . "-" . str_pad($business->current_order_number, 6, '0', STR_PAD_LEFT);
            }
            $business->save();
            //create order
            $order = $code->orders()->create(array_merge(
                $this->inputManipulator($code, $inputs, $verifiedCustomer),
                [
                    'number' => $orderNumber,
                    'channel' => $channel,
                    'phone_verified_at' => $verifiedCustomer ? now() : null,
                ]
            ));
            // create order items
            $business->load('promotions');
            (new OrderItemRepository())->store($order, $inputs['items'], $channel);
            //calculate total amount
            $order_total_amount = $order->items()->sum('total_amount');
            $taxCalculator = $this->calculateTax($business->district->city->country, $order_total_amount);
            /**
             * update tax details and amount
             * tax_id
             * tax_amount
             * total_amount
             */
            $order->update($taxCalculator);

            return $order;
        });
    }

    /** Store an authenticated staff order without requiring a public QR code. */
    public function storeForStaff(Business $business, array $inputs, int $userId): Order
    {
        return DB::transaction(function () use ($business, $inputs, $userId) {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            $channel = $inputs['channel'];
            abort_unless($business->orderingChannels()->where('ordering_channels.slug', $channel)->where('ordering_channels.is_active', true)->wherePivot('is_enabled', true)->exists(), 422, 'The selected ordering channel is not enabled for this business.');

            $servicePoint = null;
            if (!empty($inputs['service_point_id'])) {
                $servicePoint = ServicePoint::query()->whereKey($inputs['service_point_id'])->where('business_id', $business->id)->where('is_active', true)->firstOrFail();
                abort_unless($servicePoint->orderingChannels()->where('slug', $channel)->exists(), 422, 'This service point is not available for the selected ordering channel.');
            }

            $customer = null;
            if (!empty($inputs['phone'])) {
                $customer = (new CustomerRepository())->getCustomerByPhone($inputs['phone'], $inputs['country_iso2'] ?? $business->district?->city?->country?->iso2);
            }

            $business->current_order_number += 1;
            $number = $business->order_prefix . '-' . str_pad($business->current_order_number, 6, '0', STR_PAD_LEFT);
            while (Order::query()->where('number', $number)->exists()) {
                $business->current_order_number += 1;
                $number = $business->order_prefix . '-' . str_pad($business->current_order_number, 6, '0', STR_PAD_LEFT);
            }
            $business->save();

            $processingStatus = OrderStatus::query()->where('name', 'Processing')->firstOrFail();

            $order = Order::query()->create([
                'business_id' => $business->id,
                'user_id' => $userId,
                'customer_id' => $customer?->id,
                'order_status_id' => $processingStatus->id,
                'payment_status_id' => config('constants.payment_status.PENDING'),
                'approver_id' => $userId,
                'assigned_to_user_id' => $userId,
                'approved_at' => now(),
                'service_point_id' => $servicePoint?->id,
                'service_point_label' => $servicePoint?->display_name,
                'number' => $number,
                'channel' => $channel,
                'comment' => $inputs['comment'] ?? null,
            ]);
            $business->load('promotions');
            (new OrderItemRepository())->store($order, $inputs['items'], $channel);
            $order->update($this->totalsFor($business, $order->items()->sum('total_amount')));
            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status_id' => null,
                'to_status_id' => $processingStatus->id,
                'changed_by_user_id' => $userId,
                'note' => 'Created and auto-approved by staff.',
            ]);

            return $order;
        });
    }

    public function totalsFor(Business $business, float|int $itemsTotal): array
    {
        return $this->calculateTax($business->district->city->country, $itemsTotal);
    }

    public function verifyPhone(Order $order)
    {
        return DB::transaction(function () use ($order) {
            $order->update([
                'phone_verified_at' => now()
            ]);
            return $order;
        });
    }

    /**
     * Resend Phone Verification Code
     * Order $order
     */
    public function resendPhoneVerificationCode(Order $order)
    {
        return DB::transaction(function () use ($order) {
            $phone = $order->customerVerification->phone;
            $randomCode = random_int(1000, 9999);
            $verificationInputs = [
                'verification_code' => Hash::make($randomCode),
                'expires_at' => now()->addMinutes(10),
            ];
            $order->customerVerification()->update($verificationInputs);

            // The code is delivered by the configured messaging integration.

            \Log::info("Resent verification code {$randomCode} to phone {$phone} for order {$order->number}");
            return $order;
        });
    }

    /**
     * Change Phone Number
     */
    public function changePhone(Order $order, $input)
    {
        return DB::transaction(function () use ($order, $input) {
            $country = $order->business?->district?->city?->country?->iso2;
            $customer = (new CustomerRepository())->getCustomerByPhone($input['phone'], $country);

            $order->forceFill(['customer_id' => $customer->id])->save();
            (new OrderCustomerVerificationRepository())->storeOrUpdatePhone($order, $input['phone'], $country);

            return $order->refresh();
        });
    }

    public function rating(Order $order, $inputs)
    {
        return DB::transaction(function () use ($order, $inputs) {
            return $order->update($inputs);
        });
    }
}
