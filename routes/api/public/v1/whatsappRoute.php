<?php

use App\Http\Controllers\Api\V1\WhatsApp\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);
