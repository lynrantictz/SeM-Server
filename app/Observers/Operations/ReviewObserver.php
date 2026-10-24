<?php

namespace App\Observers\Operations;

use App\Models\Business\Business;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\Vendor;
use App\Services\Operations\ReviewQueue;

class ReviewObserver
{
    public function created(Vendor|Business|ComplianceDocument $model): void
    {
        $queue = app(ReviewQueue::class);
        if ($model instanceof ComplianceDocument) {
            $queue->documentSubmitted($model);
        } else {
            $scope = $model instanceof Vendor ? 'vendor' : 'business';
            $queue->notify($scope, $model, 'New '.$scope.' awaiting review', 'created:'.$scope.':'.$model->id);
        }
    }

    public function updated(Vendor|Business|ComplianceDocument $model): void
    {
        if ($model instanceof ComplianceDocument) {
            if ($model->wasChanged('status') && $model->status === 'pending') {
                app(ReviewQueue::class)->documentSubmitted($model, true);
            }
        } elseif ($model->wasChanged(['name', 'tin', 'email', 'phone', 'address', 'location', 'country_id', 'district_id', 'business_type_id'])) {
            app(ReviewQueue::class)->reopen($model instanceof Vendor ? 'vendor' : 'business', $model);
        }
    }
}
