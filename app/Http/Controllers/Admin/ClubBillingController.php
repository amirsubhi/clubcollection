<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\AuditService;
use App\Services\BankDetailsChangeService;
use App\Support\Admin\AdminNav;
use App\Support\Admin\FundFigures;
use App\Support\Payments\DisplayStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-club billing settings: which plans members may pay ahead with, the
 * default plan, and who bears the payment gateway's per-bill fee.
 * Club admins of this club (club_admin middleware) and super admins.
 */
class ClubBillingController extends Controller
{
    private const FIELDS = [
        'allow_monthly', 'allow_quarterly', 'allow_yearly', 'default_plan', 'gateway_fee_payer', 'gateway_fee_sen',
        'overdue_grace_days', 'transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_no', 'duitnow_id',
    ];

    public function __construct(private BankDetailsChangeService $bankChanges) {}

    private const PLAN_LABELS = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];

    private const BANK_LABELS = [
        'bank_name' => 'Bank name',
        'bank_account_name' => 'Account holder',
        'bank_account_no' => 'Account number',
        'duitnow_id' => 'DuitNow ID',
    ];

    public function edit(Request $request, Club $club): Response
    {
        $club->load('bankChangeRequestedBy');
        $user = $request->user();
        $gatewayLabel = $club->activeGateway() === 'billplz' ? 'Billplz' : 'ToyyibPay';
        $memberFeeAllowed = $club->memberFeeAllowed();

        $pending = null;
        if ($club->hasPendingBankChange()) {
            $wanted = $club->pending_bank_change;
            $blocker = $this->bankChanges->approvalBlocker($club, $user);
            $rows = [];
            foreach (self::BANK_LABELS as $field => $label) {
                $rows[] = [
                    'field' => $field,
                    'label' => $label,
                    'current' => $club->{$field},
                    'requested' => $wanted[$field] ?? null,
                    'changed' => ($club->{$field} ?? null) !== ($wanted[$field] ?? null),
                ];
            }
            $pending = [
                'requestedBy' => $club->bankChangeRequestedBy?->name ?? 'a former admin',
                'requestedAt' => $club->bank_change_requested_at?->format('Y-m-d\TH:i'),
                'requestedByMe' => (int) $club->bank_change_requested_by === (int) $user->id,
                'rows' => $rows,
                'transferNow' => (bool) $club->transfer_enabled,
                'transferRequested' => ! empty($wanted['transfer_enabled']),
                'canApprove' => $blocker === null,
                'blocker' => $blocker,
                // Sent back with Approve so a request replaced meanwhile is never approved blind.
                'version' => $this->bankChanges->version($club),
            ];
        }

        return Inertia::render('Admin/Clubs/Billing', [
            'nav' => AdminNav::props($club, $user, (new FundFigures($club))->awaiting((int) $user->id)['mine']),
            'clubName' => $club->name,
            'gatewayLabel' => $gatewayLabel,
            'memberFeeAllowed' => $memberFeeAllowed,
            'memberFeeReason' => $memberFeeAllowed ? null
                : 'Not available yet for ToyyibPay. How its "charge to customer" option behaves must first be confirmed in the sandbox. Until then the club absorbs the fee.',
            'plans' => collect(Club::PLAN_MONTHS)->map(fn ($months, $plan) => [
                'key' => $plan,
                'label' => self::PLAN_LABELS[$plan],
                'months' => $months,
            ])->values(),
            'settings' => [
                'allow_monthly' => $club->allow_monthly !== false,
                'allow_quarterly' => $club->allow_quarterly !== false,
                'allow_yearly' => $club->allow_yearly !== false,
                'default_plan' => $club->default_plan ?: 'yearly',
                'gateway_fee_payer' => $memberFeeAllowed ? $club->gatewayFeePayer() : 'club',
                'gateway_fee' => number_format($club->gatewayFeeSen() / 100, 2, '.', ''),
                'overdue_grace_days' => (string) $club->overdueGraceDays(),
                'transfer_enabled' => (bool) $club->transfer_enabled,
                'bank_name' => $club->bank_name ?? '',
                'bank_account_name' => $club->bank_account_name ?? '',
                'bank_account_no' => $club->bank_account_no ?? '',
                'duitnow_id' => $club->duitnow_id ?? '',
            ],
            'transferLive' => $club->transferReady(),
            'pendingBank' => $pending,
            'urls' => [
                'update' => route('admin.clubs.billing.update', $club),
                // Only for an admin who may approve (never the requester).
                'approve' => $pending && $pending['canApprove'] ? route('admin.clubs.billing.bank-change.approve', $club) : null,
                'cancel' => $pending ? route('admin.clubs.billing.bank-change.cancel', $club) : null,
                'dashboard' => route('admin.clubs.dashboard', $club),
                'verifications' => route('admin.clubs.verifications.index', $club),
            ],
        ]);
    }

    public function update(Request $request, Club $club)
    {
        $data = $request->validate([
            'allow_monthly' => 'boolean',
            'allow_quarterly' => 'boolean',
            'allow_yearly' => 'boolean',
            'default_plan' => 'required|in:monthly,quarterly,yearly',
            'gateway_fee_payer' => 'required|in:club,member',
            // Ringgit with at most 2 decimals; stored as sen. RM10 cap catches typos (e.g. 100 for RM1.00).
            'gateway_fee' => 'required|numeric|decimal:0,2|min:0|max:10',
            // Days after the due date before a fee is "Overdue" and reminded (DisplayStatus).
            // (Left out by older clients: the current value is kept.)
            'overdue_grace_days' => 'sometimes|required|integer|min:0|max:'.DisplayStatus::MAX_GRACE_DAYS,
            // Manual bank / DuitNow transfer (P2). Members see these, so plain text.
            'transfer_enabled' => 'boolean',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_name' => 'nullable|string|max:150',
            'bank_account_no' => ['nullable', 'string', 'regex:/^[0-9][0-9 \-]{4,18}[0-9]$/'],
            'duitnow_id' => 'nullable|string|max:100',
        ], [
            'bank_account_no.regex' => 'The account number may only have digits, spaces and dashes (6 to 20 characters).',
        ], [
            'gateway_fee' => 'gateway fee',
            'default_plan' => 'default plan',
            'gateway_fee_payer' => 'who pays the gateway fee',
            'overdue_grace_days' => 'grace period',
            'bank_account_name' => 'account holder',
            'bank_account_no' => 'account number',
            'duitnow_id' => 'DuitNow ID',
        ]);

        $bank = [
            'transfer_enabled' => $request->boolean('transfer_enabled'),
            'bank_name' => self::clean($data['bank_name'] ?? null),
            'bank_account_name' => self::clean($data['bank_account_name'] ?? null),
            'bank_account_no' => self::clean($data['bank_account_no'] ?? null),
            'duitnow_id' => self::clean($data['duitnow_id'] ?? null),
        ];

        $allowed = [
            'monthly' => $request->boolean('allow_monthly'),
            'quarterly' => $request->boolean('allow_quarterly'),
            'yearly' => $request->boolean('allow_yearly'),
        ];

        validator([], [])->after(function (Validator $v) use ($allowed, $data, $club, $bank) {
            // Members can only be offered a transfer when they can see where to send it.
            if ($bank['transfer_enabled'] && ! (new Club($bank))->transferDetailsComplete()) {
                $v->errors()->add('transfer_enabled', 'To turn on bank transfer, fill in the bank name, account holder, and the account number or DuitNow ID.');
            }
            // Until a sandbox run confirms billChargeToCustomer, a ToyyibPay club
            // cannot pass the fee on (config toyyibpay.charge_to_customer_verified).
            if ($data['gateway_fee_payer'] === 'member' && ! $club->memberFeeAllowed()) {
                $v->errors()->add('gateway_fee_payer', 'Members can\'t pay the ToyyibPay fee yet: its "charge to customer" option must first be confirmed in the sandbox.');
            }
            if (! in_array(true, $allowed, true)) {
                $v->errors()->add('allow_monthly', 'Allow at least one billing plan.');
            } elseif (! $allowed[$data['default_plan']]) {
                $v->errors()->add('default_plan', 'The default plan must be one of the allowed plans.');
            }
        })->validate();

        $new = [
            'allow_monthly' => $allowed['monthly'],
            'allow_quarterly' => $allowed['quarterly'],
            'allow_yearly' => $allowed['yearly'],
            'default_plan' => $data['default_plan'],
            'gateway_fee_payer' => $data['gateway_fee_payer'],
            'gateway_fee_sen' => (int) round(((float) $data['gateway_fee']) * 100),
            'overdue_grace_days' => (int) ($data['overdue_grace_days'] ?? $club->overdueGraceDays()),
        ];

        $old = $club->only(self::FIELDS);
        $club->update($new);
        // Bank details members pay into need a second approver (S1); switching
        // transfers off, or on with the current details, applies at once.
        $pending = $this->bankChanges->save($club, $request->user(), $bank);
        $club->refresh();

        AuditService::log(
            'club.billing_updated',
            "Club '{$club->name}' billing settings updated (fee paid by {$new['gateway_fee_payer']}, RM".number_format($new['gateway_fee_sen'] / 100, 2).' per bill; overdue after '.$new['overdue_grace_days'].' day(s); bank transfer '.($club->transfer_enabled ? 'on' : 'off').($pending ? '; new bank details waiting for approval' : '').').',
            $club,
            $club->id,
            $old,
            $club->only(self::FIELDS)
        );

        return redirect()->route('admin.clubs.billing.edit', $club)->with('success', $pending
            ? 'Billing settings saved. The new bank details wait for another admin to approve them; members still see the current details.'
            : 'Billing settings saved.');
    }

    /** A different club admin (or the super admin) approves the pending bank details. */
    public function approveBankChange(Request $request, Club $club)
    {
        $data = $request->validate(['version' => 'required|string|max:64']);
        if ($reason = $this->bankChanges->approvalBlocker($club, $request->user())) {
            return back()->with('error', $reason);
        }
        $this->bankChanges->approve($club, $request->user(), $data['version']);

        return redirect()->route('admin.clubs.billing.edit', $club)->with('success', 'Bank details approved. Members now see the new details.');
    }

    /** Any admin of the club (the requester too) can drop a pending change. */
    public function cancelBankChange(Request $request, Club $club)
    {
        $this->bankChanges->cancel($club, $request->user());

        return redirect()->route('admin.clubs.billing.edit', $club)->with('success', 'Pending bank details change cancelled.');
    }

    /** Trim; an empty field is stored as null. Account numbers keep the spaces/dashes the admin typed. */
    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
