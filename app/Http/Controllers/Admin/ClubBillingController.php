<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\AuditService;
use App\Services\BankDetailsChangeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Per-club billing settings: which plans members may pay ahead with, the
 * default plan, and who bears the payment gateway's per-bill fee.
 * Club admins of this club (club_admin middleware) and super admins.
 */
class ClubBillingController extends Controller
{
    private const FIELDS = [
        'allow_monthly', 'allow_quarterly', 'allow_yearly', 'default_plan', 'gateway_fee_payer', 'gateway_fee_sen',
        'transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_no', 'duitnow_id',
    ];

    public function __construct(private BankDetailsChangeService $bankChanges) {}

    public function edit(Club $club)
    {
        $club->load('bankChangeRequestedBy');

        return view('admin.clubs.billing', [
            'club' => $club,
            'bankChangeVersion' => $this->bankChanges->version($club),
            'bankApprovalBlocker' => $club->hasPendingBankChange() ? $this->bankChanges->approvalBlocker($club, auth()->user()) : null,
            'plans' => Club::PLAN_MONTHS,
            'gatewayLabel' => $club->activeGateway() === 'billplz' ? 'Billplz' : 'ToyyibPay',
            'memberFeeAllowed' => $club->memberFeeAllowed(),
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
        ];

        $old = $club->only(self::FIELDS);
        $club->update($new);
        // Bank details members pay into need a second approver (S1); switching
        // transfers off, or on with the current details, applies at once.
        $pending = $this->bankChanges->save($club, $request->user(), $bank);
        $club->refresh();

        AuditService::log(
            'club.billing_updated',
            "Club '{$club->name}' billing settings updated (fee paid by {$new['gateway_fee_payer']}, RM".number_format($new['gateway_fee_sen'] / 100, 2).' per bill; bank transfer '.($club->transfer_enabled ? 'on' : 'off').($pending ? '; new bank details waiting for approval' : '').').',
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
