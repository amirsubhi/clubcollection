# Pilihan bayaran yuran / Payment options for club fees

*Nota satu halaman untuk AJK / One-page note for the committee — 15 Nov 2026*

> **Penting / Important:** the figures below come from **search-result extracts read on 15 Nov 2026**. The providers'
> own sites were blocked from the session that did the research. **Confirm every fee on the provider's site (and in
> the signed agreement) before deciding.** Sources are listed at the end.

## Latar belakang / Background

- 50–150 members (100 used below), fees RM5–10 per member per month.
- Typical situation for a small club: fees may be collected into an individual committee member's account, payroll
  deduction may not be available, and the club may not be registered with ROS (Jabatan Pendaftaran Pertubuhan).

## Perbandingan caj / Fee comparison

Online gateways charge a **flat fee per bill**, which is heavy on small amounts.

| Pilihan / Option | Caj / Fee per bill | On RM5 | On RM10 | Notes |
|---|---|---|---|---|
| ToyyibPay Standard (FPX) | RM1.00 | 20% | 10% | Already built into the app. Individuals can sign up with IC + Malaysian bank account. |
| ToyyibPay Santai (FPX) | RM0 | 0% | 0% | Registered non-profits only; payouts take about 10 business days. |
| Billplz Basic (FPX) | RM1.25 + SST (≈RM1.35) | 27% | 13.5% | Also built in, but costlier. |
| Stripe (card) | 3% + RM1 | 23% | 13% | Not suited to small bills. |
| CHIP DuitNow QR | 1%, min RM0.15 | 3% | 1.5% | Cheapest automated QR; needs a new integration. |
| Bank static DuitNow QR (Maybank, CIMB) | 0% (promotional) | 0% | 0% | Needs a business/club account; promotional rate, check its end date. |
| DuitNow / IBG transfer to the club account | RM0 | 0% | 0% | Manual: member uploads the slip, treasurer verifies in the app (coming next). |

Avoid Billplz FPX, Stripe, senangPay, iPay88 and Fiuu for bills this small.

## Kos setahun untuk 100 ahli / Annual cost for 100 members

Fee RM10 per month (RM12,000 collected a year). Fewer, larger bills cost far less:

| Kekerapan / How often members pay | Bills a year | ToyyibPay RM1.00 | Billplz ≈RM1.35 | Stripe 3% + RM1 | CHIP QR 1% (min RM0.15) |
|---|---|---|---|---|---|
| Bulanan / Monthly (RM10) | 1,200 | **RM1,200** (10%) | RM1,620 | RM1,560 | RM180 |
| Suku tahunan / Quarterly (RM30) | 400 | **RM400** (3.3%) | RM540 | RM760 | RM120 |
| Tahunan / Yearly (RM120) | 100 | **RM100** (0.8%) | RM135 | RM460 | RM120 |

At RM5 a month (RM6,000 a year) the ToyyibPay cost is the same in ringgit, so the share doubles: monthly 20%,
quarterly 6.7%, yearly 1.7%.

## Cadangan / Recommendation

**Sekarang / Now**
1. **ToyyibPay Standard**, already integrated, opened by the treasurer.
2. Pay it out to a **dedicated bank account used only for club money** (not the treasurer's everyday account), with
   statements shared with the committee every month.
3. **Bill yearly or quarterly by default.** The app now lets the club choose which plans members see (monthly,
   quarterly, yearly), pre-selects the default, and shows members how much in charges a longer plan saves.
4. **Who pays the RM1 charge** is one club-wide setting: the club absorbs it on every bill, or members pay it on top of
   every bill (shown clearly on the review page). With yearly billing the club's cost is small (about RM100 a year), so
   absorbing it is the simple choice. Charging members on monthly bills only, while the club absorbs it on yearly ones,
   would need a per-plan setting (a possible future addition). **For ToyyibPay the app keeps "club absorbs" until its
   "charge to customer" option has been tested in the sandbox** (see the technical note).

**Pilihan percuma / Free option (coming next):** DuitNow transfer straight to the club account, with the member
uploading the transfer slip and the treasurer confirming it in the app. No gateway charge.

## Langkah tadbir urus / Governance steps

1. **Akaun khas kelab / Dedicated club-only account** now; monthly statements to the committee.
2. **Daftar dengan ROS / Register the club with ROS** (Jabatan Pendaftaran Pertubuhan, via eROSES).
3. **Akaun bank pertubuhan / Society bank account** with **2-of-3 signatories** (e.g. president, secretary, treasurer).
4. Then apply for **ToyyibPay Santai (RM0 FPX)** and a **bank static DuitNow QR** (0% while the promotion lasts).

A personal account holding club money is an **audit and continuity risk** (no second signatory, mixed funds, and the
money is hard to reach if the treasurer leaves or is unavailable). Moving to a society account removes that risk.

## Nota teknikal / Technical note

- ToyyibPay `billChargeToCustomer` mapping used by the app: club absorbs → blank, member pays → `'0'` (our reading of
  the API docs: FPX charge to the customer). **Not yet verified**; the "member pays" option stays disabled for ToyyibPay
  clubs until the sandbox confirms it and `TOYYIBPAY_CHARGE_VERIFIED=true` is set. The app never adds the fee to a ToyyibPay bill amount, so members are not charged twice.
- Billplz has no such option, so when members pay the charge the app adds it to the Billplz bill amount.
- Changing the setting does not affect a bill a member has already opened: that bill keeps the fee mode it was created
  with (the review page shows it), so a payment in progress is never orphaned.
- The fee amount per bill entered in the club settings is used for display and for the dashboard's "fees this year"
  figure. ToyyibPay applies its own fee; keep the setting equal to your plan's actual fee.

## Sumber / Sources (read as search extracts, 15 Nov 2026 — confirm on each site)

- ToyyibPay pricing plans: https://www.toyyibpay.com/pricing-plans/
- ToyyibPay pricing explained: https://malaysiapg.com.my/blog/toyyibpay-pricing-explained/
- Billplz pricing: https://support.billplz.com/guide/pricing
- CHIP pricing: https://chip-in.asia/pricing
- Stripe fees in Malaysia: https://www.profitvana.com/guides/stripe-fees-in-malaysia
- CIMB static QR promotional rate 2026: https://www.cimb.com.my/en/business/important-notices/2026/extension-of-static-qr-promotional-rate-2026.html
- QR payment gateway comparison: https://hitpayapp.com/blog/qr-payment-gateway-malaysia-comparison
- ToyyibPay terms of service: https://www.toyyibpay.com/wp-content/uploads/2026/08/TERMS-OF-SERVICE-210726.pdf
- Registering a society with ROS: https://memberlytic.com/blog/how-to-register-society-club-malaysia-ros-guide
