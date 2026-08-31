<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use Illuminate\Http\Request;

class NotificationController extends BaseController
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(20);

        return $this->sendResponse([
            'notifications' => $notifications->items(),
            'total_count' => $notifications->total(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ], 'Notifications retrieved successfully.');
    }

    public function markRead(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return $this->sendResponse([], 'Notification marked as read.');
    }
}
