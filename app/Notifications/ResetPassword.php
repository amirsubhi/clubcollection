<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's reset-link email with one change: the opening "Hello!" greets by the time of day in Malay
 * ("Selamat pagi!", MailText::plainGreeting). Built while the account's language is active (User::preferredLocale).
 */
class ResetPassword extends LaravelResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        return parent::buildMailMessage($url)->greeting(MailText::plainGreeting());
    }
}
