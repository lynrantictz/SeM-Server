<?php

use App\Http\Controllers\Api\V1\Business\BusinessOrderController;
use App\Models\Auth\User;
use App\Models\Business\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(fn () => \Carbon\Carbon::setTestNow());

function staffWaiterAssignmentFixture(string $actorRole = 'owner'): array
{
    $now = now();
    $actorId = DB::table('users')->insertGetId([
        'name' => 'Assignment actor', 'email' => Str::uuid().'@paperstic.test', 'password' => bcrypt('password'),
        'type' => in_array($actorRole, ['owner', 'vendor_manager', 'vendor_viewer'], true) ? 'owner' : 'business',
        'is_active' => true, 'email_verified_at' => $now, 'uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $vendorId = DB::table('vendors')->insertGetId([
        'country_id' => 1, 'name' => 'Waiter assignment vendor', 'email' => Str::uuid().'@paperstic.test',
        'uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $businessId = DB::table('businesses')->insertGetId([
        'vendor_id' => $vendorId, 'district_id' => 1, 'business_type_id' => 1, 'name' => 'Waiter assignment business',
        'location' => 'Dar es Salaam', 'order_prefix' => 'WA'.Str::upper(Str::random(6)), 'code_prefix' => 'WA'.Str::upper(Str::random(6)),
        'uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $actor = User::query()->findOrFail($actorId);

    if (in_array($actorRole, ['owner', 'vendor_manager', 'vendor_viewer'], true)) {
        DB::table('vendor_user')->insert([
            'vendor_id' => $vendorId, 'user_id' => $actorId,
            'is_primary' => $actorRole === 'owner',
            'role' => $actorRole === 'owner' ? 'owner' : ($actorRole === 'vendor_manager' ? 'manager' : 'viewer'),
            'access_scope' => $actorRole === 'vendor_viewer' ? 'selected_businesses' : 'all_businesses',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($actorRole === 'vendor_viewer') {
            DB::table('business_user')->insert([
                'business_id' => $businessId, 'user_id' => $actorId, 'title' => 'Viewer assignment '.$actorId,
                'business_role' => 'vendor_viewer', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    } else {
        DB::table('business_user')->insert([
            'business_id' => $businessId, 'user_id' => $actorId, 'title' => 'Staff assignment '.$actorId,
            'business_role' => $actorRole, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $waiterId = DB::table('users')->insertGetId([
        'name' => 'Active waiter', 'email' => Str::uuid().'@paperstic.test', 'password' => bcrypt('password'),
        'type' => 'business', 'is_active' => true, 'email_verified_at' => $now, 'uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('business_user')->insert([
        'business_id' => $businessId, 'user_id' => $waiterId, 'title' => 'Waiter '.$waiterId,
        'business_role' => 'waiter', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $pendingStatus = DB::table('order_statuses')->where('name', 'Pending')->value('id')
        ?? DB::table('order_statuses')->insertGetId(['name' => 'Pending', 'created_at' => $now, 'updated_at' => $now]);
    $orderId = DB::table('orders')->insertGetId([
        'business_id' => $businessId, 'order_status_id' => $pendingStatus,
        'number' => 'ORD-WAITER-'.Str::upper(Str::random(8)), 'uuid' => (string) Str::uuid(),
        'channel' => 'dine_in', 'total_items_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    return [$actor, Business::query()->findOrFail($businessId), $waiterId, DB::table('orders')->find($orderId)];
}

it('allows the agreed business roles to assign and reassign waiters with an audit record', function (string $role) {
    [$actor, $business, $waiterId, $order] = staffWaiterAssignmentFixture($role);
    $controller = new BusinessOrderController;
    expect((new ReflectionMethod($controller, 'canReassignWaiter'))->invoke($controller, $role))->toBeTrue();

    $this->actingAs($actor, 'api')
        ->patchJson('/v1/businesses/'.$business->uuid.'/orders/'.$order->uuid.'/assignee', ['waiter_user_id' => $waiterId])
        ->assertOk()
        ->assertJsonPath('data.order.assigned_to.id', $waiterId)
        ->assertJsonPath('data.order.assignment_history.0.previous_waiter', null)
        ->assertJsonPath('data.order.assignment_history.0.waiter', 'Active waiter')
        ->assertJsonPath('data.order.assignment_history.0.changed_by', 'Assignment actor');

    $replacementId = DB::table('users')->insertGetId([
        'name' => 'Replacement waiter', 'email' => Str::uuid().'@paperstic.test', 'password' => bcrypt('password'),
        'type' => 'business', 'is_active' => true, 'email_verified_at' => now(), 'uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('business_user')->insert([
        'business_id' => $business->id, 'user_id' => $replacementId, 'title' => 'Replacement waiter '.$replacementId,
        'business_role' => 'waiter', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->patchJson('/v1/businesses/'.$business->uuid.'/orders/'.$order->uuid.'/assignee', ['waiter_user_id' => $replacementId])
        ->assertOk()
        ->assertJsonPath('data.order.assigned_to.id', $replacementId)
        ->assertJsonPath('data.order.assignment_history.1.previous_waiter', 'Active waiter')
        ->assertJsonPath('data.order.assignment_history.1.waiter', 'Replacement waiter')
        ->assertJsonPath('data.order.assignment_history.1.changed_by', 'Assignment actor');

    expect(DB::table('orders')->where('id', $order->id)->value('assigned_to_user_id'))->toBe($replacementId)
        ->and(DB::table('order_assignment_histories')->where('order_id', $order->id)->count())->toBe(2);
})->with(['owner', 'business_manager', 'manager', 'vendor_manager', 'vendor_viewer', 'counter', 'counter-clerk']);

it('denies waiter reassignment to waiter, kitchen, and chef roles', function (string $role) {
    [$actor, $business, $waiterId, $order] = staffWaiterAssignmentFixture($role);

    $this->actingAs($actor, 'api')
        ->patchJson('/v1/businesses/'.$business->uuid.'/orders/'.$order->uuid.'/assignee', ['waiter_user_id' => $waiterId])
        ->assertForbidden();

    expect(DB::table('orders')->where('id', $order->id)->value('assigned_to_user_id'))->toBeNull()
        ->and(DB::table('order_assignment_histories')->where('order_id', $order->id)->count())->toBe(0);
})->with(['waiter', 'kitchen', 'chef']);

it('rejects an inactive or cross-business user as the assignee', function () {
    [$actor, $business, $waiterId, $order] = staffWaiterAssignmentFixture();
    $now = now();
    $otherBusinessId = DB::table('businesses')->insertGetId([
        'vendor_id' => $business->vendor_id, 'district_id' => $business->district_id, 'business_type_id' => $business->business_type_id,
        'name' => 'Another business', 'location' => 'Dar es Salaam', 'order_prefix' => 'OT'.Str::upper(Str::random(6)),
        'code_prefix' => 'OT'.Str::upper(Str::random(6)), 'uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $otherWaiterId = DB::table('users')->insertGetId([
        'name' => 'Other business waiter', 'email' => Str::uuid().'@paperstic.test', 'password' => bcrypt('password'),
        'type' => 'business', 'is_active' => true, 'email_verified_at' => $now, 'uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('business_user')->insert([
        'business_id' => $otherBusinessId, 'user_id' => $otherWaiterId, 'title' => 'Other business waiter '.$otherWaiterId,
        'business_role' => 'waiter', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $this->actingAs($actor, 'api')
        ->patchJson('/v1/businesses/'.$business->uuid.'/orders/'.$order->uuid.'/assignee', ['waiter_user_id' => $otherWaiterId])
        ->assertNotFound();

    expect(DB::table('orders')->where('id', $order->id)->value('assigned_to_user_id'))->toBeNull()
        ->and(DB::table('order_assignment_histories')->where('order_id', $order->id)->count())->toBe(0);

    DB::table('business_user')->where('business_id', $business->id)->where('user_id', $waiterId)->update(['is_active' => false]);
    $this->patchJson('/v1/businesses/'.$business->uuid.'/orders/'.$order->uuid.'/assignee', ['waiter_user_id' => $waiterId])->assertNotFound();
});

it('limits waiter paid completed cancelled and active queues to orders they approved or currently own', function (string $tab, string $status, string $paymentStatus) {
    [$waiter, $business, $otherWaiterId] = staffWaiterAssignmentFixture('waiter');
    $now = now();
    $statusId = DB::table('order_statuses')->where('name', $status)->value('id')
        ?? DB::table('order_statuses')->insertGetId(['name' => $status, 'created_at' => $now, 'updated_at' => $now]);
    $paymentStatusId = DB::table('payment_statuses')->where('name', $paymentStatus)->value('id')
        ?? DB::table('payment_statuses')->insertGetId(['name' => $paymentStatus, 'created_at' => $now, 'updated_at' => $now]);
    $makeOrder = function (?int $approverId, ?int $assigneeId) use ($business, $statusId, $paymentStatusId, $now): string {
        $uuid = (string) Str::uuid();
        DB::table('orders')->insert([
            'business_id' => $business->id, 'order_status_id' => $statusId, 'payment_status_id' => $paymentStatusId,
            'approver_id' => $approverId, 'assigned_to_user_id' => $assigneeId,
            'number' => 'ORD-QUEUE-'.Str::upper(Str::random(8)), 'uuid' => $uuid, 'channel' => 'takeaway',
            'total_items_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $uuid;
    };

    $approvedByWaiter = $makeOrder($waiter->id, $otherWaiterId);
    $assignedToWaiter = $makeOrder($otherWaiterId, $waiter->id);
    $belongsToOtherWaiter = $makeOrder($otherWaiterId, $otherWaiterId);

    $this->actingAs($waiter, 'api')
        ->getJson('/v1/businesses/'.$business->uuid.'/orders?tab='.$tab)
        ->assertOk()
        ->assertJsonPath('data.orders.meta.total', 2)
        ->assertJsonFragment(['uuid' => $approvedByWaiter])
        ->assertJsonFragment(['uuid' => $assignedToWaiter])
        ->assertJsonMissing(['uuid' => $belongsToOtherWaiter]);
})->with([
    ['preparing', 'Processing', 'Pending'],
    ['ready', 'Ready', 'Pending'],
    ['awaiting_payment', 'Served', 'Pending'],
    ['paid', 'Served', 'Completed'],
    ['completed', 'Completed', 'Completed'],
    ['cancelled', 'Cancelled', 'Pending'],
]);

it('keeps a scheduled online order awaiting payment after counter or waiter approval and denies direct preparation', function (string $role) {
    [$staff, $business, , $order] = staffWaiterAssignmentFixture($role);
    $now = now();
    $scheduledFor = $now->copy()->addHours(5);
    $preparationStartsAt = $scheduledFor->copy()->subMinutes(30);
    DB::table('orders')->where('id', $order->id)->update([
        'channel' => 'online', 'scheduled_for' => $scheduledFor, 'preparation_starts_at' => $preparationStartsAt,
        'schedule_timezone' => 'Africa/Dar_es_Salaam',
    ]);

    $url = '/v1/businesses/'.$business->uuid.'/orders/'.$order->uuid.'/actions';
    $this->actingAs($staff, 'api')->postJson($url, ['action' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'Awaiting payment');

    $this->postJson($url, ['action' => 'ready'])->assertForbidden();
    expect(DB::table('orders')->where('id', $order->id)->value('order_status_id'))
        ->toBe(DB::table('order_statuses')->where('name', 'Awaiting payment')->value('id'))
        ->and(DB::table('orders')->where('id', $order->id)->value('scheduled_for'))->not->toBeNull()
        ->and(DB::table('orders')->where('id', $order->id)->value('preparation_starts_at'))->not->toBeNull();
})->with(['counter', 'waiter']);

it('promotes only paid scheduled orders whose preparation time has arrived', function () {
    \Carbon\Carbon::setTestNow('2026-10-27 15:00:00 UTC');
    $businessId = 1;
    $scheduledId = DB::table('order_statuses')->where('name', 'Scheduled')->value('id')
        ?? DB::table('order_statuses')->insertGetId(['name' => 'Scheduled', 'created_at' => now(), 'updated_at' => now()]);
    $processingId = DB::table('order_statuses')->where('name', 'Processing')->value('id')
        ?? DB::table('order_statuses')->insertGetId(['name' => 'Processing', 'created_at' => now(), 'updated_at' => now()]);
    $completedPaymentId = DB::table('payment_statuses')->where('name', 'Completed')->value('id')
        ?? DB::table('payment_statuses')->insertGetId(['name' => 'Completed', 'created_at' => now(), 'updated_at' => now()]);
    $pendingPaymentId = DB::table('payment_statuses')->where('name', 'Pending')->value('id')
        ?? DB::table('payment_statuses')->insertGetId(['name' => 'Pending', 'created_at' => now(), 'updated_at' => now()]);
    $makeScheduled = function (int $paymentStatusId, ?string $preparationStartsAt) use ($businessId, $scheduledId): int {
        return DB::table('orders')->insertGetId([
            'business_id' => $businessId, 'order_status_id' => $scheduledId, 'payment_status_id' => $paymentStatusId,
            'number' => 'ORD-SCHED-'.Str::upper(Str::random(8)), 'uuid' => (string) Str::uuid(), 'channel' => 'online',
            'scheduled_for' => '2026-10-27 18:00:00', 'preparation_starts_at' => $preparationStartsAt,
            'schedule_timezone' => 'Africa/Dar_es_Salaam', 'total_items_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $dueAndPaid = $makeScheduled($completedPaymentId, '2026-10-27 14:30:00');
    $notYetDue = $makeScheduled($completedPaymentId, '2026-10-27 15:30:00');
    $unpaid = $makeScheduled($pendingPaymentId, '2026-10-27 14:00:00');

    $this->artisan('orders:promote-scheduled')->assertExitCode(0);

    expect(DB::table('orders')->where('id', $dueAndPaid)->value('order_status_id'))->toBe($processingId)
        ->and(DB::table('orders')->where('id', $notYetDue)->value('order_status_id'))->toBe($scheduledId)
        ->and(DB::table('orders')->where('id', $unpaid)->value('order_status_id'))->toBe($scheduledId)
        ->and(DB::table('order_status_histories')->where('order_id', $dueAndPaid)->value('note'))->toBe('Scheduled preparation window started.')
        ->and(DB::table('order_status_histories')->where('order_id', $notYetDue)->count())->toBe(0)
        ->and(DB::table('order_status_histories')->where('order_id', $unpaid)->count())->toBe(0);

    \Carbon\Carbon::setTestNow();
});
