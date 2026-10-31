<?php

namespace App\Notifications;

use App\Models\BusinessInquiry;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessInquiryReceived extends Notification
{
    public function __construct(private readonly BusinessInquiry $inquiry) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Paperstic business enquiry')
            ->greeting('A new business enquiry was submitted')
            ->line('Name: '.$this->inquiry->name)
            ->line('Business: '.$this->inquiry->business_name)
            ->line('Email: '.$this->inquiry->email)
            ->line('Message:')
            ->line($this->inquiry->message);
    }
}
