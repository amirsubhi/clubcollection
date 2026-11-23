<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A short security notice to the account owner, in their language (User::preferredLocale(); send it with
 * Mail::to($user)):
 * - KIND_SIGN_IN_PAUSED: many wrong passwords, sign-in paused for a while;
 * - KIND_PASSWORD_CHANGED: the password was reset or changed.
 * If it was not them, they reset their password and tell their club admin.
 */
class AccountSecurityNotice extends Mailable
{
    use Queueable, SerializesModels;

    public const KIND_SIGN_IN_PAUSED = 'sign_in_paused';

    public const KIND_PASSWORD_CHANGED = 'password_changed';

    public function __construct(
        public User $user,
        public string $kind,
        public int $minutes = 0,
    ) {}

    public function envelope(): Envelope
    {
        // Built here, at send time: Laravel calls envelope() under the recipient's locale (not the constructor).
        $subject = $this->kind === self::KIND_SIGN_IN_PAUSED
            ? __('Sign-in attempts on your account – :portal', ['portal' => config('app.name')])
            : __('Your password was changed – :portal', ['portal' => config('app.name')]);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-security-notice', with: [
            'resetUrl' => route('password.request'),
            'signInUrl' => route('login'),
        ]);
    }
}
