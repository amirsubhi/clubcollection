# Club Portal – Usability research for the redesign

Scope: a mobile-first portal where staff-club members pay fees by FPX (ToyyibPay) and a treasurer records expenses and *sumbangan kematian* (death-benefit) payouts with maker-checker approval, ending in an AGM statement. Many members are older or not confident with technology, and the UI is in BM and English. The visual direction is "modern fintech" with light and dark themes. Each rule below is something to apply to the screens, followed by its source.

## 1. Mobile payment & checkout

1. **Do** show one "Semak & Bayar / Review & pay" step that is clearly *not* a receipt. Put the item, period, member name and total in a summary list. Put a "Change" link next to each item that can be changed. The primary button should sit near the total, in the first screen view. Users mistake review steps for confirmations, which drives abandonment. [Baymard – order review](https://baymard.com/guidelines/2387-order-review-steps-for-multistep-checkouts), [GOV.UK – Check answers](https://design-system.service.gov.uk/patterns/check-answers)
2. **Do** put the amount and the action on the button ("Bayar RM 60.00 / Pay RM 60.00"). Disable the button after the first tap so nobody pays twice. Show no new fees or totals at the review step. [Baymard – checkout UX guide](https://baymard.com/learn/checkout-flow-ux-optimization), [WCAG 3.3.4 Error Prevention (Financial)](https://www.w3.org/WAI/WCAG22/Understanding/error-prevention-legal-financial-data)
3. **Do** treat the redirect back from FPX as *unknown*. Fetch the status from the server and show one of three distinct result screens: **Berjaya** (success), **Dalam proses** (pending: "don't pay again, we'll update you", with a "Check again" button) and **Gagal** (failed: plain reason, "No money was taken", "Try again"). [Stripe – FPX payments](https://docs.stripe.com/payments/fpx), [Airwallex – FPX flow](https://www.airwallex.com/docs/payments/payment-methods/apac/fpx/desktopmobile-website-browser)
4. **Do** list FPX banks with logo and name. Put the most used banks first (Maybank2u, CIMB Clicks, Bank Islam, RHB, Public Bank), then an A–Z list with search. Gray out banks that are offline and say why. [Stripe – FPX Bank Element](https://docs.stripe.com/stripe-js/elements/fpx-bank)
5. **Do** add quiet trust cues around the pay block: a padlock with "Secured by FPX / ToyyibPay", the club's name as payee and a support contact. Group the payment details in a tinted card. Do not use fake seals. [Baymard – perceived security](https://baymard.com/blog/perceived-security-of-payment-form)
6. **Do** make the success screen a lasting receipt. It needs a reference number, FPX transaction ID, date and time, amount, what was paid for, "Download PDF / Share to WhatsApp" and "what happens next". [GOV.UK – Confirmation pages](https://design-system.service.gov.uk/patterns/confirmation-pages)

## 2. Fintech dashboard

1. **Do** lead with one number. Members see their status ("Yuran 2026: Sudah bayar ✓" or "RM 60 tertunggak"). Admins see the fund balance. Use the largest type for that number and keep bold colour for it alone. [NN/g – Dashboards & preattentive processing](https://www.nngroup.com/articles/dashboards-preattentive/)
2. **Do** limit KPI tiles to 3–4 (collected, outstanding, spent, awaiting approval). Show each as a number plus a change against the last period. Use bars or lines, never gauges or pies. [NN/g – Dashboards & preattentive processing](https://www.nngroup.com/articles/dashboards-preattentive/)
3. **Do** show the last 5 activity rows (icon, label, date, signed amount), newest first, with "See all". [NN/g – Status trackers](https://www.nngroup.com/articles/status-tracker-progress-update/)
4. **Do** give 2–4 labelled quick actions (Pay, Receipts, Fund, Help). Icons need text labels. [NN/g – Icon usability](https://www.nngroup.com/articles/icon-usability/)

## 3. Accessible charts

1. **Do** give bars, lines and segments at least 3:1 contrast against the background and against each other, in **both** themes. [WCAG 1.4.11 Non-text Contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast)
2. **Do** never use colour alone. Add a direct label or value at the end of each bar or line, use +/− signs and words for income vs. expense, and add patterns or line styles where series touch. [WCAG 1.4.1 Use of Color](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color)
3. **Do** prefer horizontal bars (length is read most accurately) and label them directly instead of using a legend. [NN/g – Dashboards & preattentive processing](https://www.nngroup.com/articles/dashboards-preattentive/)
4. **Do** provide a data table or text summary next to each chart ("Kutipan RM 12,400; Belanja RM 8,150"), so screen-reader users and printed AGM copies get the same information. [WCAG 1.1.1 Non-text Content](https://www.w3.org/WAI/WCAG22/Understanding/non-text-content)
5. **Do** define separate chart tokens for dark mode. Lighten and desaturate series colours, and dim gridlines without letting them fall below the needed contrast. [Material – Dark theme](https://m2.material.io/design/color/dark-theme)

## 4. Approval / workflow UI

1. **Do** use a status chip on every record with text + icon + colour: Draf, Menunggu kelulusan, Dikembalikan, Diluluskan, Dibayar. [GOV.UK – Tag](https://design-system.service.gov.uk/components/tag/), [WCAG 1.4.1](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color)
2. **Do** show a vertical timeline on record detail. Each event shows who did it, their role (Maker/Checker), the time and any note. Put the latest event first or highlight it. [NN/g – Status trackers](https://www.nngroup.com/articles/status-tracker-progress-update/)
3. **Do** enforce maker ≠ checker in the UI. Hide or disable Approve for the person who created the record, and explain why ("You created this record; another committee member must approve it").
4. **Do** require a reason before a record is returned or rejected. The reason appears on the timeline and at the top of the maker's edit form. [GOV.UK – Error message](https://design-system.service.gov.uk/components/error-message/)
5. **Do** confirm irreversible actions (Approve payout, Mark paid) with a specific dialog that restates the beneficiary and amount ("Luluskan RM 1,000 kepada Waris Ahmad?"). Keep the destructive and primary buttons apart. Do not add confirmations to routine actions. [NN/g – Confirmation dialogs](https://www.nngroup.com/articles/confirmation-dialog/), [NN/g – Proximity of consequential options](https://www.nngroup.com/articles/proximity-consequential-options/)

## 5. Older / less tech-savvy users

1. **Do** make every tap target at least 44×44 px, and 48 px for primary actions, with 8 px or more between targets. The WCAG AA minimum is 24 px, which is too small here. [WCAG 2.5.8](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html), [Apple HIG – Accessibility](https://developer.apple.com/design/human-interface-guidelines/accessibility), [Android – Touch targets](https://support.google.com/accessibility/android/answer/7101858)
2. **Do** use body text of at least 16 px (17–18 px preferred) and amounts of 28 px or more. Keep text at 4.5:1 contrast or better, and avoid light-grey secondary text. [NN/g – Older adults](https://www.nngroup.com/articles/usability-for-senior-citizens/), [WCAG 1.4.3](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum)
3. **Do** keep the pay path to 3 taps or fewer from home (Pay → choose bank → Pay RM X). Pre-fill the member and amount, and never ask for something the system already knows. [WCAG 3.3.7 Redundant Entry](https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry)
4. **Do** write plain BM first, with English beside it or behind a persistent BM/EN toggle. Use everyday words ("Bayar", "Resit"), not jargon ("Transaksi FPX B2C"). [GOV.UK – Writing for user interfaces](https://www.gov.uk/service-manual/design/writing-for-user-interfaces)
5. **Do** show help on every payment screen (a WhatsApp link to the treasurer). Digital-literacy gaps are the main barrier for Malaysian seniors. [HRMARS – e-payment adoption among MY/CN seniors](https://hrmars.com/papers_submitted/28607/factors-shaping-e-payment-adoption-intentions-among-senior-citizens-a-cross-national-comparative-study-of-malaysia-and-china.pdf), [The Edge – TNG 3.4M users aged 60+](https://theedgemalaysia.com/node/812677)

## 6. Dark mode for finance

1. **Do** use a dark grey base (around #121212 or a navy tint), not pure black. Show raised surfaces as lighter tones. [Material – Dark theme](https://m2.material.io/design/color/dark-theme)
2. **Do** use off-white text (about 87% white) and desaturated accents. Check that green/red amounts still reach 4.5:1. [Material – Dark theme](https://m2.material.io/design/color/dark-theme), [WCAG 1.4.3](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum)
3. **Do** follow the system setting by default and offer a manual toggle. Keep receipts and AGM exports light, because people print and share them. [Apple HIG – Dark Mode](https://developer.apple.com/design/human-interface-guidelines/dark-mode)
4. **Don't** rely on shadows or thin 1 px borders to separate cards in dark mode. Use a surface tone step or a border with 3:1 contrast. [WCAG 1.4.11](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast)

## 7. Forms & file upload (mobile)

1. **Do** offer "Ambil gambar / Take photo" (`accept="image/*" capture="environment"`) and "Pilih fail / Choose file" (images + PDF) for receipts and death certificates. [MDN – capture attribute](https://developer.mozilla.org/en-US/docs/Web/HTML/Attributes/capture)
2. **Do** show each file as its own row with a thumbnail, name, size, a status (Uploading % / Uploaded ✓ / Error + reason) and a Remove control of 44 px or more. [GOV.UK – File upload](https://design-system.service.gov.uk/components/file-upload), [QLD Health DS – File upload states](https://www.design-system.health.qld.gov.au/components/file-upload)
3. **Do** put labels above fields. Use `inputmode="decimal"` with an "RM" prefix for amounts, and pick dates with a native picker. [GOV.UK – Text input](https://design-system.service.gov.uk/components/text-input/)
4. **Do** validate when the user submits. Show an error summary at the top that links to each field, plus an inline message saying how to fix it ("Masukkan jumlah, contoh 150.00"). [GOV.UK – Error summary](https://design-system.service.gov.uk/components/error-summary/), [NN/g – Error messages](https://www.nngroup.com/articles/error-message-guidelines/), [WCAG 3.3.1](https://www.w3.org/WAI/WCAG22/Understanding/error-identification)
5. **Do** keep the draft if an upload or network call fails, and state the file limits up front (e.g. "JPG/PNG/PDF, max 5 MB").

## Screen checklist

| Screen | Apply rules |
|---|---|
| Member home | 2.1, 2.3, 2.4, 5.1, 5.2, 5.4, 6.1–6.3 |
| Review & pay | 1.1, 1.2, 1.4, 1.5, 5.1, 5.3, 5.5 |
| Pay result (success / pending / failed) | 1.3, 1.6, 3.2, 4.1, 5.4, 5.5 |
| Payment history / receipt | 1.6, 2.3, 4.1, 6.3 |
| Member fund page | 2.1, 3.1–3.5, 5.2 |
| Admin dashboard | 2.1–2.4, 3.1–3.5, 4.1, 6.1–6.4 |
| Fund usage list | 2.3, 4.1, 5.1, 3.2 (signed amounts) |
| New expense / welfare form | 7.1–7.5, 4.4 (show return reason), 5.1, 5.3 |
| Record detail / approval | 4.1–4.5, 7.2 (attachments) |
| Mobile approval | 4.3, 4.5, 5.1, 1.2 (amount on Approve button) |
| AGM statement | 3.1–3.4, 6.3, 2.2 |

## Applied in mockup v2

| Screen | Rules applied, and how |
|---|---|
| Member home (`#member-home`) | 2.1 one hero number (Deep Pine card, 52px amount); 2.4 four labelled quick actions; 2.3 recent activity, newest first; 5.1 44px+ targets, 56px+ rows; 5.2 16px body; 5.4 BM first with BM/EN toggle; 5.5 WhatsApp help row; 6.1–6.3 dark base #0E1513, system theme by default. |
| Review & pay (`#member-pay`) | 1.1 bottom-sheet review step with "Tukar" link; 1.2 "Bayar RM 50.00" on the button, disabled after first tap; 1.4 popular banks first, A–Z list, offline bank greyed with reason; 1.5 trust card (FPX via ToyyibPay, payee, no extra charge); 5.3 home → Bayar → bank → pay (3 taps); 5.5 help link. |
| Pay result (`#pay-success/-pending/-failed`) | 1.3 three distinct outcomes; pending says "Jangan bayar sekali lagi" + "Semak status semula", failed says "Tiada wang diambil" + reason + "Cuba lagi"; 1.6 success shows a paper receipt (receipt no., FPX transaction ID, date, items, download / WhatsApp, "Apa seterusnya"); 4.1 status chips with icon + text; animated tick respects reduced motion. |
| History / receipt | 1.6, 2.3 grouped "Belum selesai / Sudah dibayar" rows; 4.1 chips; 6.3 receipt stays light paper in dark mode. |
| Member fund (`#member-fund`) | 2.1 fund balance hero; 3.1–3.5 validated chart colours per theme, dashed second series + direct end labels + legend, text summary and "Lihat sebagai jadual" table; "Where your money goes" flow diagram with RM amounts; 5.2. |
| Admin dashboard (`#admin-dashboard`) | 2.1–2.2 four KPI tiles with change vs last period; 3.1–3.5 area chart + 100% category bar (labels inside, legend, table view); 2.3 recent activity with signed amounts and "Money in/out" words; 4.1 chips in approvals queue; 6.1–6.4 dark tokens, borders not shadows. |
| Fund usage list | 2.3, 4.1 chips, 3.2 signed "−RM" amounts; data table with faceted filters, sort and pagination. |
| New expense / welfare form | 7.1–7.5 unchanged uploader; 4.4 new "Returned by checker" state shows the reason at the top of the form. |
| Record detail (`#fund-item`) | 4.1 chips; 4.2 audit trail newest first, latest highlighted, maker/checker roles; 4.3 maker cannot approve; 4.5 dialog titles restate amount and payee; Disediakan → Diluluskan rail, LULUS / BATAL stamp, maker-checker diagram. |
| Mobile approval | 1.2 "Approve RM 6,895.00"; 4.3, 4.5; 5.1 48px buttons. |
| AGM statement | 3.1–3.4 category bar with legend and the full statement as the table; 6.3 paper sheet stays light, mono figures, double-rule totals; 2.2 three KPI tiles. |

Chart colours (validated with the dataviz palette script, adjacent pairs): light `#0F7A5C, #D99A1C, #2F6FB8, #B5523A` on `#FFFFFF`, all checks pass (marigold 2.4:1 → relief via direct labels + table); dark `#2E9E7B, #C08A1E, #5A8FD6, #D0705A` on `#16201D`, all checks pass.
