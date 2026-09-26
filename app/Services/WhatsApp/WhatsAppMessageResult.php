<?php

namespace App\Services\WhatsApp;

class WhatsAppMessageResult
{
    public function __construct(
        public readonly string $messageId,
    ) {}
}
