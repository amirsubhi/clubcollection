<?php

namespace App\Support\Admin;

use App\Models\Club;
use App\Models\User;
use App\Support\Portal\PortalSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The admin sidebar: one list of items, in one order, for both the Inertia
 * pages (AdminShell via props()) and the classic Blade pages
 * (layouts/app.blade.php via forRequest()), so the two can never drift.
 * Items on pages still rendered by Blade are marked `external` so the
 * Inertia shell does a full page load to reach them.
 */
final class AdminNav
{
    /** Session key: the club the admin last worked in (Blade pages without a club in the URL use it). */
    public const SESSION_CLUB = 'admin.nav_club_id';

    /**
     * Route-name patterns → nav item key (first match wins).
     *
     * @var array<string, list<string>>
     */
    private const ACTIVE = [
        'dashboard' => ['admin.clubs.dashboard', 'admin.clubs.dashboard.*'],
        'fund-usage' => ['admin.clubs.fund-usage.*', 'admin.fund-usage.*', 'admin.clubs.expenses.*', 'admin.expenses.*'],
        'verifications' => ['admin.clubs.verifications.*'],
        'payments' => ['admin.clubs.payments.*', 'admin.payments.*'],
        'members' => ['admin.clubs.members.*', 'admin.members.*'],
        'fee-rates' => ['admin.clubs.fee-rates.*', 'admin.fee-rates.*'],
        'job-levels' => ['admin.clubs.job-levels.*', 'admin.job-levels.*'],
        'billing' => ['admin.clubs.billing.*'],
        'profile' => ['admin.clubs.profile.*'],
        'discounts' => ['admin.clubs.discounts.*', 'admin.discounts.*'],
        'categories' => ['admin.clubs.expense-categories.*', 'admin.expense-categories.*'],
        'ledger' => ['admin.clubs.ledger', 'admin.clubs.ledger.*'],
        'audit' => ['admin.clubs.audit-logs'],
        'home' => ['home'],
        'clubs' => ['admin.clubs.index', 'admin.clubs.create', 'admin.clubs.store', 'admin.clubs.show', 'admin.clubs.edit', 'admin.clubs.update'],
        'admins' => ['admin.admins.*'],
        'statistics' => ['admin.statistics'],
        'audit-all' => ['admin.audit-logs.*'],
        'updates' => ['admin.updates.index', 'admin.updates.check'],
        'portal-settings' => ['admin.portal-settings.*'],
        'privacy-notice' => ['admin.privacy-notice.*'],
        'security' => ['profile.*'],
    ];

    /** Super-admin pages about every club: the sidebar shows no club group there. */
    private const CROSS_CLUB = ['admin.clubs.index', 'admin.clubs.create', 'admin.clubs.store', 'admin.admins.*', 'admin.statistics', 'admin.audit-logs.*', 'admin.portal-settings.*', 'admin.privacy-notice.*', 'admin.updates.*'];

    /**
     * Sidebar props for an Inertia admin page (AdminShell).
     *
     * @return array<string, mixed>
     */
    public static function props(Club $club, User $user, int $awaiting = 0): array
    {
        return [
            'groups' => self::groups($club, $user, $awaiting),
            'homeHref' => route('admin.clubs.dashboard', $club),
            // The club name already heads the nav group and the page; a third copy under the brand is noise.
            'brandSubtitle' => null,
            ...self::brand(),
            'user' => self::userProps($user),
            'memberHref' => route('member.dashboard'),
            'logoutUrl' => route('logout'),
        ];
    }

    /**
     * The sidebar brand: the portal name (PortalSettings, else APP_NAME) and the portal logo, if one is set.
     *
     * @return array{brandName: string, brandLogoUrl: ?string}
     */
    public static function brand(): array
    {
        return [
            'brandName' => PortalSettings::name(),
            'brandLogoUrl' => PortalSettings::logoUrl(),
        ];
    }

    /**
     * The nav groups: the club's pages (when there is a club), then the portal pages.
     *
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    public static function groups(?Club $club, User $user, int $awaiting = 0): array
    {
        $blade = fn (string $key, string $label, string $href, string $icon) => compact('key', 'label', 'href', 'icon') + ['external' => true];
        $groups = [];

        if ($club !== null) {
            // Transfer slips waiting for an admin (one count query per page).
            // Slips, not fees: a "Bayar semua" slip for three fees counts once (as on the dashboard and Verifications page).
            $slips = \App\Http\Controllers\Admin\TransferVerificationController::waitingSlipCount($club);

            $groups[] = ['title' => $club->name, 'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => route('admin.clubs.dashboard', $club), 'icon' => 'dashboard', 'external' => false],
                ['key' => 'fund-usage', 'label' => 'Fund Usage', 'href' => route('admin.clubs.fund-usage.index', $club), 'icon' => 'fund', 'external' => false, 'badge' => $awaiting ?: null],
                ['key' => 'verifications', 'label' => 'Verifications', 'href' => route('admin.clubs.verifications.index', $club), 'icon' => 'verify', 'external' => false, 'badge' => $slips ?: null],
                $blade('payments', 'Payments', route('admin.clubs.payments.index', $club), 'payments'),
                $blade('members', 'Members', route('admin.clubs.members.index', $club), 'members'),
                $blade('fee-rates', 'Fee Rates', route('admin.clubs.fee-rates.index', $club), 'rates'),
                $blade('job-levels', 'Job levels', route('admin.clubs.job-levels.index', $club), 'levels'),
                $blade('profile', 'Club profile', route('admin.clubs.profile.edit', $club), 'profile'),
                ['key' => 'billing', 'label' => 'Billing settings', 'href' => route('admin.clubs.billing.edit', $club), 'icon' => 'billing', 'external' => false],
                $blade('discounts', 'Discounts', route('admin.clubs.discounts.index', $club), 'discounts'),
                $blade('categories', 'Expense categories', route('admin.clubs.expense-categories.index', $club), 'categories'),
                ['key' => 'ledger', 'label' => 'Ledger', 'href' => route('admin.clubs.ledger', $club), 'icon' => 'ledger', 'external' => false],
                $blade('audit', 'Audit Log', route('admin.clubs.audit-logs', $club), 'audit'),
            ]];
        }

        $portal = [$blade('home', 'Portal home', route('home'), 'home')];
        if ($user->isSuperAdmin()) {
            $portal[] = $blade('clubs', 'All clubs', route('admin.clubs.index'), 'clubs');
            $portal[] = $blade('admins', 'Administrators', route('admin.admins.index'), 'admins');
            $portal[] = $blade('statistics', 'Statistics', route('admin.statistics'), 'statistics');
            $portal[] = $blade('audit-all', 'Audit Log (all clubs)', route('admin.audit-logs.index'), 'audit');
            $portal[] = $blade('portal-settings', 'Portal settings', route('admin.portal-settings.edit'), 'settings');
            $portal[] = $blade('updates', 'Updates', route('admin.updates.index'), 'settings');
            $portal[] = $blade('privacy-notice', 'Privacy notice', route('admin.privacy-notice.edit'), 'audit');
        }
        $portal[] = $blade('security', 'Security & 2FA', route('profile.security'), 'security');
        $groups[] = ['title' => 'Portal', 'items' => $portal];

        return $groups;
    }

    /**
     * Everything the Blade layout needs: the same groups as the Inertia pages
     * for the club in context, the active item and the user block.
     *
     * @return array{groups: list<array>, active: ?string, club: ?Club, homeHref: string, brandSubtitle: null, brandName: string, brandLogoUrl: ?string, user: array, memberHref: string, logoutUrl: string}
     */
    public static function forRequest(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        // Cross-club super-admin pages (statistics, all clubs, administrators, all audit logs) list no club group.
        $club = \Illuminate\Support\Str::is(self::CROSS_CLUB, (string) $request->route()?->getName())
            ? null
            : self::contextClub($request, $user);
        $awaiting = $club ? FundFigures::for($club)->awaiting((int) $user->id)['mine'] : 0;

        return [
            'groups' => self::groups($club, $user, $awaiting),
            'active' => self::activeKey($request->route()?->getName()),
            'club' => $club,
            'homeHref' => $club ? route('admin.clubs.dashboard', $club) : route('home'),
            'brandSubtitle' => null,
            ...self::brand(),
            'user' => self::userProps($user),
            'memberHref' => route('member.dashboard'),
            'logoutUrl' => route('logout'),
        ];
    }

    /** The nav item key for a route name, or null when no item matches. */
    public static function activeKey(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }
        foreach (self::ACTIVE as $key => $patterns) {
            foreach ($patterns as $pattern) {
                if (\Illuminate\Support\Str::is($pattern, $routeName)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The club whose pages the sidebar lists: the one in the URL (directly or
     * through a record such as a payment), else the one the admin last worked
     * in, else the first club they manage. Null when they manage none.
     */
    public static function contextClub(Request $request, User $user): ?Club
    {
        if (! $user->isAdmin()) {
            return null;
        }

        foreach ($request->route()?->parameters() ?? [] as $param) {
            $club = match (true) {
                $param instanceof Club => $param,
                $param instanceof Model && isset($param->club_id) => Club::find($param->club_id),
                default => null,
            };
            if ($club && self::manages($user, $club)) {
                $request->session()->put(self::SESSION_CLUB, $club->id);

                return $club;
            }
        }

        $remembered = $request->session()->get(self::SESSION_CLUB);
        if ($remembered && ($club = Club::find($remembered)) && self::manages($user, $club)) {
            return $club;
        }

        return $user->isSuperAdmin()
            ? Club::where('is_active', true)->orderBy('name')->first()
            : $user->clubs()->wherePivot('role', 'admin')->wherePivot('is_active', true)
                ->where('clubs.is_active', true)->orderBy('clubs.name')->first();
    }

    private static function manages(User $user, Club $club): bool
    {
        return $user->isSuperAdmin() || ($club->is_active && $user->clubs()
            ->where('clubs.id', $club->id)
            ->wherePivot('role', 'admin')
            ->wherePivot('is_active', true)
            ->exists());
    }

    /**
     * @return array{name: string, initials: string, roleLabel: string}
     */
    private static function userProps(User $user): array
    {
        return [
            'name' => $user->name,
            'initials' => \App\Support\Initials::of($user->name),
            'roleLabel' => $user->isSuperAdmin() ? 'Super admin' : 'Club admin',
        ];
    }
}
