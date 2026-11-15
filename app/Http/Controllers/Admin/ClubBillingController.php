<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Per-club billing settings: which plans members may pay ahead with, the
 * default plan, and who bears the payment gateway's per-bill fee.
 * Club admins of this club (club_admin middleware) and super admins.
 */
class ClubBillingController extends Controller
{
    private const FIELDS = ['allow_monthly', 'allow_quarterly', 'allow_yearly', 'default_plan', 'gateway_fee_payer', 'gateway_fee_sen'];

    public function edit(Club $club)
    {
        return view('admin.clubs.billing', [
            'club' => $club,
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
        ], [], [
            'gateway_fee' => 'gateway fee',
            'default_plan' => 'default plan',
            'gateway_fee_payer' => 'who pays the gateway fee',
        ]);

        $allowed = [
            'monthly' => $request->boolean('allow_monthly'),
            'quarterly' => $request->boolean('allow_quarterly'),
            'yearly' => $request->boolean('allow_yearly'),
        ];

        validator([], [])->after(function (Validator $v) use ($allowed, $data, $club) {
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

        AuditService::log(
            'club.billing_updated',
            "Club '{$club->name}' billing settings updated (fee paid by {$new['gateway_fee_payer']}, RM".number_format($new['gateway_fee_sen'] / 100, 2).' per bill).',
            $club,
            $club->id,
            $old,
            $club->fresh()->only(self::FIELDS)
        );

        return redirect()->route('admin.clubs.billing.edit', $club)->with('success', 'Billing settings saved.');
    }
}
