<?php

namespace App\Support\Admin;

use App\Models\Club;
use App\Models\User;

/**
 * Sidebar for Inertia admin pages (AdminShell). Pages still on Blade are
 * marked `external` so the browser does a full page load to reach them.
 */
final class AdminNav
{
    /**
     * @return array<string, mixed>
     */
    public static function props(Club $club, User $user, int $awaiting = 0): array
    {
        $blade = fn (string $key, string $label, string $href, string $icon) => compact('key', 'label', 'href', 'icon') + ['external' => true];

        // Transfer slips waiting for an admin (one count query per page).
        $slips = $club->payments()->where('status', 'pending_verification')->count();

        $clubItems = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => route('admin.clubs.dashboard', $club), 'icon' => 'dashboard', 'external' => false],
            ['key' => 'fund-usage', 'label' => 'Fund Usage', 'href' => route('admin.clubs.fund-usage.index', $club), 'icon' => 'fund', 'external' => false, 'badge' => $awaiting ?: null],
            ['key' => 'verifications', 'label' => 'Verifications', 'href' => route('admin.clubs.verifications.index', $club), 'icon' => 'verify', 'external' => false, 'badge' => $slips ?: null],
            $blade('payments', 'Payments', route('admin.clubs.payments.index', $club), 'payments'),
            $blade('members', 'Members', route('admin.clubs.members.index', $club), 'members'),
            $blade('fee-rates', 'Fee Rates', route('admin.clubs.fee-rates.index', $club), 'rates'),
            $blade('billing', 'Billing settings', route('admin.clubs.billing.edit', $club), 'billing'),
            $blade('discounts', 'Discounts', route('admin.clubs.discounts.index', $club), 'discounts'),
            $blade('categories', 'Expense categories', route('admin.clubs.expense-categories.index', $club), 'categories'),
            $blade('ledger', 'Ledger', route('admin.clubs.ledger', $club), 'ledger'),
            $blade('audit', 'Audit Log', route('admin.clubs.audit-logs', $club), 'audit'),
        ];

        $portal = [$blade('home', 'Portal home', route('home'), 'home')];
        if ($user->isSuperAdmin()) {
            $portal[] = $blade('clubs', 'All clubs', route('admin.clubs.index'), 'clubs');
            $portal[] = $blade('statistics', 'Statistics', route('admin.statistics'), 'statistics');
        }
        $portal[] = $blade('security', 'Security & 2FA', route('profile.security'), 'security');

        return [
            'groups' => [
                ['title' => $club->name, 'items' => $clubItems],
                ['title' => 'Portal', 'items' => $portal],
            ],
            'homeHref' => route('admin.clubs.dashboard', $club),
            'brandSubtitle' => $club->name,
            'user' => [
                'name' => $user->name,
                'roleLabel' => $user->isSuperAdmin() ? 'Super admin' : 'Club admin',
            ],
            'memberHref' => route('member.dashboard'),
            'logoutUrl' => route('logout'),
        ];
    }
}
