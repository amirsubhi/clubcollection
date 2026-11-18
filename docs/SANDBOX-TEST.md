# ToyyibPay sandbox test

A step-by-step test, on a **staging copy** of the portal, that proves ToyyibPay payments work before any real money
is taken. It uses the ToyyibPay **sandbox** (`dev.toyyibpay.com`): a practice copy of ToyyibPay with a pretend bank,
so nothing here costs money.

> **Real FPX stays OFF until every item in the [checklist](#7-checklist) passes.** Both the staging site and the live
> site keep `TOYYIBPAY_SANDBOX=true` until the developers have read your results and said yes. Never put real
> (live) ToyyibPay keys on the staging site.
>
> **Until then, no live club may use ToyyibPay as its payment method.** In sandbox mode a member could "pay" with
> the sandbox bank simulator and the fee would be marked paid with no real money taken. Live clubs collect by bank
> transfer (slip upload) only until the switch to `TOYYIBPAY_SANDBOX=false`.

What the test answers:

| | Question | Why it matters |
|---|---|---|
| a | Does ToyyibPay accept the bills the portal creates (name, description, amount, charge setting)? | A refused bill means members can't pay at all. |
| b | Which fields does ToyyibPay's callback (its server-to-server "this bill was paid" message) really send: `status` or `status_id`, `refno` or `transaction_id`, and is `amount` in ringgit or sen? | The portal marks a fee paid only from this message. If the names differ, fees stay unpaid. |
| c | What does `getBillTransactions` (ToyyibPay's "list this bill's payments" answer) look like, and in what unit is `billpaymentAmount`? | The portal asks it before trusting a callback (`TOYYIBPAY_VERIFY_CALLBACK=true`). |
| d | When the member pays the gateway charge (`billChargeToCustomer`), what does the member pay and what does the club receive? | Decides whether "Member pays it" can be switched on. |
| e | Does ToyyibPay retry a callback that wasn't answered "OK", and are repeats harmless? | The portal relies on retries when ToyyibPay is slow to confirm. |

Plan about two hours. You need a cPanel account with Terminal access and an email address you can read.

---

## 1. Create a ToyyibPay sandbox account

1. Open **https://dev.toyyibpay.com** and register a new account (any email you control). This is separate from a
   real ToyyibPay account.
2. Log in. Find your **User Secret Key** (in your account / settings page; it is a long code with dashes). Copy it
   somewhere private. **Never send it to anyone, including the developers.**
3. Create a **Category** (menu **Category → Create Category**; any name, e.g. "Yuran Kelab"). Copy its
   **Category Code** (a short code of letters and digits).

Menu names on the sandbox site may differ slightly; what you need is the secret key and one category code.

## 2. Set up a staging site on cPanel

The staging site is a second, separate copy of the portal with its own database. Use a subdomain, e.g.
`staging.example.com` (use your own domain).

1. cPanel → **Domains** → **Create A New Domain**: `staging.example.com`, with **Document Root**
   `clubportal-staging/public` (not the live site's folder).
2. cPanel → **SSL/TLS Status** → run **AutoSSL** so the subdomain has HTTPS, and turn on **Force HTTPS Redirect**
   (cPanel → **Domains**). ToyyibPay must be able to reach `https://staging.example.com/webhook/toyyibpay`.
3. Do **not** put password protection (cPanel **Directory Privacy**) on the staging site: it would block ToyyibPay's
   callbacks too.
4. Install the portal into `~/clubportal-staging` exactly as in [INSTALL.md, Path B](INSTALL.md#3-path-b-cpanel-hosting),
   steps 1 to 7 and 11, with these differences in `.env` (step 5):

   ```dotenv
   APP_URL=https://staging.example.com      # the staging address, exactly
   DB_DATABASE=...                          # SQLite (the default) is fine; never the live site's database

   TOYYIBPAY_SANDBOX=true                   # sandbox: no real money
   TOYYIBPAY_SECRET_KEY=<sandbox User Secret Key>
   TOYYIBPAY_CATEGORY_CODE=<sandbox Category Code>
   TOYYIBPAY_WEBHOOK_SECRET=<random, 32+ characters>   # php -r "echo bin2hex(random_bytes(32));"
   TOYYIBPAY_VERIFY_CALLBACK=true
   TOYYIBPAY_CHARGE_VERIFIED=false          # switched on only for test 5, on staging only
   TOYYIBPAY_LOG_CALLBACKS=true             # writes each callback's field names to the Audit Log

   MAIL_MAILER=log                          # optional: emails (welcome passwords, receipts) go to
                                            # storage/logs/laravel.log instead of being sent
   ```

   Make a **new** webhook secret for staging; don't copy the live one. After every `.env` change run
   `php artisan config:cache`.

   `TOYYIBPAY_LOG_CALLBACKS` only works while `TOYYIBPAY_SANDBOX=true`. It records the callback's field names and its
   status, ids and amount, never the payer's name, email or phone.

   The installer (step 7) writes `APP_ENV=production`. **Keep it** (or change it to `APP_ENV=staging`): the staging
   site should behave like the live one. **Don't** set `APP_ENV=local` on a public server.

5. Check the settings in the Terminal:

   ```bash
   cd ~/clubportal-staging
   php artisan toyyibpay:sandbox-check
   ```

   It prints a table of the ToyyibPay settings (keys are shown only as "set (hidden)"). The address must be
   `https://dev.toyyibpay.com (sandbox)`, the webhook secret "set", and "Callback fields in audit log" "on". The
   command refuses to run at all when `TOYYIBPAY_SANDBOX=false`. If it says APP_ENV is production that is fine here:
   with `TOYYIBPAY_SANDBOX=true` no real payment can be taken.

## 3. Create test data by hand

The demo data command (`php artisan db:seed`) **refuses to run on a server**: it only works when `APP_ENV` is `local`
or `testing`. Create a small test club instead, logged in as the super admin you made in the installer:

1. **Clubs → New Club**: name "Staging Test Club", any email, payment gateway **ToyyibPay**. Leave the club's
   ToyyibPay keys empty, so it uses the sandbox keys from `.env`.
2. **Fee Rates**: set **RM 1.00** a month for one job level (e.g. Executive). Small amounts keep the test simple; RM 1
   is ToyyibPay's usual minimum.
3. **Billing settings**: leave **Gateway fee: Club absorbs it** and **Gateway fee per bill RM 1.00**.
4. **Members → Add member**: a test member at that job level, with an email you can read (or, with `MAIL_MAILER=log`,
   find the welcome email with the temporary password in `storage/logs/laravel.log`). Use a made-up name such as
   "Ahli Ujian".
5. **Payments**: generate fee records for that member for **three months**, so the member has three open fees (needed
   for test 4).
6. Open a private browser window, log in as the test member and change the temporary password when asked.

Keep the super admin logged in in a normal window: you will watch **Audit Log** there. Callbacks that match no fee
are shown only in the super admin's **Audit Log** (all clubs).

## 4. The tests

Write down the time of each payment; it helps to find the matching Audit Log rows. In the Audit Log, **View changes**
on a row shows the details as text you can copy.

### Test 1: create a bill from the Terminal (questions a, c)

```bash
php artisan toyyibpay:sandbox-check --create
```

It creates an RM 1.00 sandbox bill that is **not** linked to any fee, using the same code, bill name and description
format as a real member payment, and prints:

- every field it sent to ToyyibPay (the secret key as `[hidden, N characters]`, the callback token as `[hidden]`,
  payer details as `[hidden]`), and a note if the bill name breaks ToyyibPay's documented rules;
- ToyyibPay's raw answer;
- the **bill code** and the **payment page** address.

Then:

1. Open the payment page. Check the amount is **RM 1.00** and the bill name looks right.
2. Choose FPX, pick the sandbox's test bank and choose the "successful" outcome on the bank simulator.
3. In the Terminal run (with your bill code):

   ```bash
   php artisan toyyibpay:sandbox-check --bill=<bill code> --all
   ```

   It prints ToyyibPay's raw answer next to how the portal reads it: **Paid? YES/NO**, the amount in sen, and the
   number of successful payments. `billpaymentAmount` is read as **ringgit** ("1.00" = 100 sen). If the raw answer
   says `"100"` and the portal reads it as 10000 sen, the unit is wrong: report it.
4. In the super admin's **Audit Log** look for **Payment Sandbox Callback** for that bill code (the fields ToyyibPay
   sent) and **Payment Unmatched Webhook** (expected here, because this test bill belongs to no fee).

Optional: `--amount=2.50` for another amount; `--payer=member` to send the "customer pays the charge" setting
(see test 5); `--club=<id>` to use a club's own keys.

### Test 2: a real member payment (questions a, b)

1. As the test member: **Utama → Bayar RM 1.00** (or open a fee) → **Semak & bayar** → keep **FPX** → **Bayar**.
2. On ToyyibPay, pay with the test bank and choose **success**.
3. You should come back to the portal's result page showing **Berjaya** (paid). It may show **Dalam proses** for a few
   seconds and then change by itself.
4. **Copy the address in the browser bar** on the result page (everything after the `?`). This is ToyyibPay's return
   information (it holds no secret).
5. In the **Audit Log** (club or super admin) look for:
   - **Payment Sandbox Callback**: the description lists "Fields received: ..." and "Read by the app as: PAID / NOT
     PAID". Open **View changes** and copy the text.
   - **Payment Paid Via Webhook** for the fee.
6. The fee shows as paid, with a receipt.

What to look for: ToyyibPay's published API describes the callback as `refno`, `status` (1 = paid,
2 = pending, 3 = failed), `reason`, `billcode`, `order_id` and `amount` in ringgit (e.g. `"1.00"`). The portal reads
that shape, and also the older `status_id` / `transaction_id` names, so either works. If the callback entry says
**NOT PAID** while ToyyibPay says the payment succeeded, or the fee stays unpaid, **stop and report it** with the
"Fields received" line: that needs a code fix before go-live.

### Test 3: a failed payment

1. As the member, pay the next fee, but on the bank simulator choose the **failed / rejected** outcome.
2. Back on the portal the result page should say **Bayaran belum diterima** (not paid), and the fee stays unpaid.
3. Copy the browser address (after the `?`) again.
4. Audit Log: a **Payment Sandbox Callback** (if ToyyibPay sends one for failures) reading **NOT PAID**, and **no**
   "Payment Paid Via Webhook" for this fee.
5. Run `php artisan toyyibpay:sandbox-check --bill=<bill code> --all` with this bill's code (the `billcode=` part of
   the address from step 3, or the callback entry): the "every status" list should show the failed attempt
   (`billpaymentStatus` 3), and **Paid? NO**.

### Test 4: "Bayar semua" (one bill for several fees)

1. The member needs at least two open fees. On **Utama** tap **Bayar semua RM …** → **Semak & bayar semua** → FPX.
2. Check the ToyyibPay page shows the **total** of all open fees, pay with success.
3. Result: every fee in the set is paid together. Audit Log: one **Payment Paid Via Webhook** per fee, all naming the
   same group bill, and one **Payment Sandbox Callback**.

### Test 5: the member pays the charge (question d)

Only on staging:

1. In `.env` set `TOYYIBPAY_CHARGE_VERIFIED=true`, then `php artisan config:cache`.
2. As a club admin: **Billing settings → Gateway fee: Member pays it**, gateway fee per bill RM 1.00, save.
3. Generate one more fee for the member if none is open. As the member open it: the review page should show
   **Yuran + caj = Jumlah** (e.g. RM 1.00 + RM 1.00).
4. Tap **Bayar**. On the ToyyibPay page **write down the total the payer is asked to pay** (take a screenshot).
5. Pay with success. Then write down:
   - the `amount` in the **Payment Sandbox Callback** entry (View changes → `values.amount`);
   - `php artisan toyyibpay:sandbox-check --bill=<bill code>`: the `billpaymentAmount`;
   - in the ToyyibPay sandbox dashboard, the transaction's amount and what is shown as settled to you (if shown).
6. Compare with a "club absorbs it" bill: `php artisan toyyibpay:sandbox-check --create` (club pays) and
   `php artisan toyyibpay:sandbox-check --create --payer=member`; open both payment pages (no need to pay) and note
   the total each one asks for.
7. Set `TOYYIBPAY_CHARGE_VERIFIED=false` again and run `php artisan config:cache`, and set Billing settings back to
   **Club absorbs it**.

What to look for: with "Member pays it" the payer should be asked for fee + charge **once** (not twice), and the fee
must still be marked paid. With "Club absorbs it" the payer should be asked for the fee amount only.

### Test 6: retries and repeats (question e)

Does ToyyibPay send the callback again when the portal doesn't answer "OK"?

1. `php artisan toyyibpay:sandbox-check --create` and copy the payment page address.
2. Put the staging site into maintenance mode: `php artisan down`. Every request, the callback included, now gets
   "503 Service Unavailable".
3. Open the payment page and pay with success. (You come back to a maintenance page; that's expected.)
4. Wait **10 minutes**, then `php artisan up`.
5. Wait up to **an hour** and watch the super admin's **Audit Log** for a **Payment Sandbox Callback** with that bill
   code. Write down the payment time and the time the entry appeared (or that none appeared).

Repeats: in tests 2 and 4 check that each fee has **exactly one** "Payment Paid Via Webhook" even if there are several
"Payment Sandbox Callback" rows for the same bill, and **no** "Payment Gateway Paid Twice" warning.

### Test 7: how quickly getBillTransactions lists a payment

Right after a successful payment (test 2 or a new `--create` bill), run
`php artisan toyyibpay:sandbox-check --bill=<bill code>` at once, and again after one minute. Note whether **Paid?**
is YES immediately. A delay explains any "Payment Webhook Unconfirmed" rows in the Audit Log.

## 5. What to send back

Send these to the developers (all of them are free of keys and passwords):

- the full Terminal output of every `toyyibpay:sandbox-check` run (it never prints the secret key or callback
  tokens, and hides payer names, emails and phones);
- the **View changes** text of each **Payment Sandbox Callback**, **Payment Unmatched Webhook**,
  **Payment Webhook Unconfirmed** and **Payment Paid Via Webhook** row;
- the browser address after the `?` from tests 2 and 3;
- the screenshots / totals from test 5;
- the times from tests 6 and 7;
- the filled-in checklist below.

**Never send** `.env`, the User Secret Key, the webhook secret, or the whole `storage/logs/laravel.log`.

## 6. Afterwards

The staging site and the sandbox account can be deleted when the developers are done; nothing there is real money.
On the live site, `TOYYIBPAY_SANDBOX` changes to `false` (with live keys) only after the developers confirm that
every item passed and any fixes are released.

## 7. Checklist

| # | Check | Pass / fail | Notes |
|---|---|---|---|
| 1 | `sandbox-check` settings table: sandbox address, webhook secret set, audit logging on | | |
| 2 | Test 1: `--create` returned a bill code; payment page shows RM 1.00 and a sensible bill name | | |
| 3 | Test 1: `--bill`: Paid? YES and the amount read as 100 sen for an RM 1.00 bill | | |
| 4 | Test 2: result page showed **Berjaya**, the fee is paid, receipt shown | | |
| 5 | Test 2: callback entry read **PAID**; field names copied (`status` or `status_id`? `refno` or `transaction_id`? `amount` like `"1.00"`?); the callback `refno` matches the reference in `--bill` output and differs for each payment | | |
| 6 | Test 3: failed payment: **Bayaran belum diterima**, fee still unpaid, no "Paid Via Webhook" | | |
| 7 | Test 4: "Bayar semua": all fees paid together with one bill for the total | | |
| 8 | Test 5: "Member pays it": payer asked for fee + charge once; fee marked paid; amounts written down | | |
| 9 | Test 5: "Club absorbs it": payer asked for the fee amount only | | |
| 10 | Test 6: a callback arrived after maintenance mode ended (ToyyibPay retries); time noted | | |
| 11 | Tests 2 and 4: one "Paid Via Webhook" per fee, no "Paid Twice" warning | | |
| 12 | Test 7: `--bill` showed the payment immediately / after N seconds | | |
| 13 | Settings restored: `TOYYIBPAY_CHARGE_VERIFIED=false`, Billing settings "Club absorbs it" | | |

Until every row says pass and the developers have confirmed, real FPX stays off (`TOYYIBPAY_SANDBOX=true`).
