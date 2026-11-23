# Translator brief: adding a member language

For language step 2 (Chinese (Simplified), Tamil, French, Spanish) and any language after it. Step 1 (branch
`i18n-more-languages`) made the code ready, so **a new language is files only, and no code**. The keys below are
frozen. If a sentence does not work in your language, ask for a new key. Do not change the code or the English.

Only the member side is translated: the member app, the sign-in pages and the member emails. Admin pages and admin
emails stay in English.

## Files to produce for a language `<code>`

Use the file-name code: `zh`, `ta`, `fr`, `es` (two or three lower-case letters, or `pt_BR` style).

| File | What | How many |
|---|---|---|
| `lang/member/<code>.json` | Member app text. Copy `lang/member/en.json`, keep every key, translate every value, fill in `_meta`. | `_meta` + 398 texts |
| `lang/<code>.json` | Sign-in pages, password pages, member emails, Laravel's reset email. Copy `lang/ms.json`. The **key** is the English sentence: keep it and translate the **value**. | 180 sentences |
| `lang/<code>/auth.php` | Sign-in errors (copy `lang/ms/auth.php`) | 3 lines |
| `lang/<code>/passwords.php` | Password reset messages (copy `lang/ms/passwords.php`) | 5 lines |
| `lang/<code>/validation.php` | Form errors on the sign-in and password pages (copy `lang/ms/validation.php`) | 5 messages + 4 field names |

Nothing else is needed. The language list (member app picker, sign-in picker, Portal settings "Default language", the
save route) is read from the `lang/member/*.json` files. Checked on Laravel 13 with `fallback_locale` = `en`:
- A `validation.php` line your file leaves out shows Laravel's English line. It is never blank and never Malay.
- `lang/<code>/validation.php` only needs the lines `lang/ms/validation.php` has. They cover the sign-in and
  password forms. The member app's own form errors are codes, translated in `lang/member` (`flash_*`).
- A sentence missing from `lang/<code>.json` shows the English key. The tests catch this.

`npm run build` must run after the files are added, because the member app bundles its text. The tests that check
your files are `tests/Unit/MemberLangFilesTest.php` and `tests/Unit/LaravelLangFilesTest.php`.

### `_meta` (first entry of `lang/member/<code>.json`)

```json
"_meta": { "name": "Bahasa Melayu", "label": "BM", "htmlLang": "ms", "localeTag": "ms-MY", "beta": false }
```

- **`name`**: the language's own name, as its speakers write it. The picker lists it.
- **`label`**: the short text on the closed picker. At most 6 characters, because it must fit a 320 px phone.
- **`htmlLang`**: the `<html lang>` value. It decides the font the browser picks, so Chinese **must** be `zh-Hans`
  or the browser may draw Japanese glyphs.
- **`localeTag`**: the BCP 47 tag the browser uses for plural rules and clock times.
- **`beta`**: when `true`, the picker shows "(beta)" after the name. Use it until a native speaker has checked the
  file.

Suggested values:

| Code | name | label | htmlLang | localeTag |
|---|---|---|---|---|
| `zh` | 简体中文 | 中文 | `zh-Hans` | `zh-Hans-MY` |
| `ta` | தமிழ் | தமிழ் | `ta` | `ta-MY` |
| `fr` | Français | FR | `fr` | `fr-FR` |
| `es` | Español | ES | `es` | `es-ES` |

## Placeholders, patterns and counts

- **Placeholders** are filled in by the code. Keep every one, spelled exactly the same, anywhere in the sentence:
  - `{n}`, `{x}`, `{d}`, `{no}` and similar in `lang/member`;
  - `:name`, `:club`, `:count` and similar in `lang/*.json` and the PHP files.

  `:Attribute` (capital letter) is the field name with a capital first letter.
- **Bold**: `**word**` in `lang/<code>.json` marks bold text in an email. Keep the same number of `**` pairs.
- **Date and time patterns** (`lang/member`; the emails use `dateFormat` too). You may reorder the parts and add
  words or characters:

  | Key | English | Parts it may use |
  |---|---|---|
  | `dateFormat` | `{d} {m} {y}` | `{d}` day, `{m}` short month name (`monthShort_N`), `{y}` year (all three) |
  | `monthYearFormat` | `{m} {y}` | `{m}`, `{y}` |
  | `periodSameYear` | `{from} – {to} {y}` | `{from}`, `{to}` (short month names), `{y}` |
  | `periodRange` | `{from} – {to}` | `{from}`, `{to}` (whole month-year values) |
  | `dateTimeFormat` | `{date}, {time}` | `{date}`, `{time}` |
  | `timeFormat` | `{h}:{mm} {ap}` | `{h}` 12-hour, `{H}` 24-hour, `{HH}` 24-hour with a leading 0, `{mm}`, `{ap}` (= `timeAm` / `timePm`) |

  Chinese example: `dateFormat` `{y}年{m}{d}日` with `monthShort_10` `10月`, which gives "2026年10月5日". French
  example: `timeFormat` `{HH}:{mm}`.
- **Counts in `lang/member`**: a text with a number comes as `<base>_one` and `<base>_other`. The browser picks the
  form for the number with the language's plural rules (`Intl.PluralRules` with `localeTag`):
  - Fill both, even when your language has one form. For Chinese, give `_one` the same text as `_other`.
  - A language with more forms may add `<base>_two`, `_few`, `_many` or `_zero`. A form you leave out uses `_other`.
  - French "one" covers 0 and 1. French "many" (millions) falls back to `_other`.
  - The 14 bases are `clubsN`, `feesN`, `flowWelfareSub`, `lateN`, `openN`, `paidTogether`, `payAllHint`,
    `processingN`, `receiptPartOf`, `sentTogether`, `slipLimits`, `tooManyFiles`, `uploaderLimits` and
    `verifyingN`.
  - `clubsN`, `feesN`, `paidTogether`, `payAllHint`, `receiptPartOf` and `sentTogether` are only ever shown for 2
    or more.
- **Counts in `lang/*.json`**: keys with `|` are Laravel `trans_choice` forms, for example
  `":count day overdue|:count days overdue"`. Give as many forms, separated by `|`, as Laravel uses for the
  language, checked in `MessageSelector::getPluralIndex`:
  - `zh` and `ms`: **one** form, used for every number (example: "Lewat :count hari");
  - `ta`, `es` and `en`: two forms, `1 | other`;
  - `fr`: two forms, `0 and 1 | other`.
- **Whole sentences**: every key is a complete phrase or sentence. Nothing is joined from pieces in code. Word
  order is yours: "Pay {x}", "Due {d}" and "Hi, {name}" may put the placeholder anywhere.
- **Fixed numbers** written into the text, such as `months_1` / `months_3` / `months_12`, "10 MB", "15 minutes",
  "6-digit code" and "Upload 1 to 3 files only", are not counts. Translate them as written.

## Do not translate

- **Payment names**: FPX, DuitNow, ToyyibPay, Billplz, RM (amounts are always "RM 1,234.50", formatted by the code).
- **Codes**: 2FA, PDF, JPG, PNG, WebP, MB, the `YURAN-…` transfer reference and receipt and invoice numbers.
- **Names**: product, portal and club names (the portal name comes from Portal settings), people's names.
- **Placeholders**: `{n}`, `{x}`, `:name`, `:Attribute`, `:count` and the like.
- **Markers**: the `**` bold markers, and the `|` between plural forms.
- **Pinned Malay**: `waHelloMsg`, `waRefMsg` and `waFeeMsg`. They are the WhatsApp message a member sends to the
  treasurer, always in Malay. Leave their values exactly as in `en.json`.
- **Malay-only fund statuses**: `fundStatus_*` are shown to admins as "Members see: …" in Malay. Translate them
  normally; the admin tooltip always reads the Malay file.

## Readers are in Malaysia

- **Malay is Malaysian Malay** (Bahasa Malaysia, Dewan Bahasa dan Pustaka usage). Never use Indonesian words or
  spellings: write "kata laluan", not "kata sandi"; "e-mel", not "email" or "surel"; "wang", not "uang"; "bil",
  not "tagihan"; "akaun", not "akun"; "percuma", not "gratis". This is the owner's rule. It applies to every
  Malay string, new or changed, and the owner approves new Malay (`docs/i18n-new-wording.md`).
- **Chinese**: Simplified Chinese as written in Malaysia. Use the money and bank words Malaysian banks use.
  Amounts stay "RM".
- **Tamil**: Malaysian Tamil usage, the words Malaysian Tamil readers see from their bank and in the newspapers,
  not India-specific terms.
- **French and Spanish**: the readers are expatriates in Malaysia. Use standard international French and Spanish.
  Malaysian terms (FPX, DuitNow, RM) stay as they are.

## Tone

- **Plain and polite.** The readers are club members, many on a phone, some not confident with apps or money
  words.
- **Short sentences.** Address the member as "you", in the polite form of your language (French "vous", Spanish
  "usted", Tamil நீங்கள்).
- **Everyday words** for money and banking, the words a bank's own app uses.
- **Consistent words**: one word for "fee", one for "receipt", one for "overdue", used the same way everywhere.
- **Calm about money problems.** Say what happened and what to do next. Never blame the member.

## Frozen key counts (end of step 1)

| File | Keys |
|---|---|
| `lang/member/en.json` (and `ms.json`) | `_meta` + 398 texts, including 14 `_one`/`_other` pairs and 9 date/time pattern keys |
| `lang/ms.json` (the list `lang/<code>.json` must cover) | 180 |
| `lang/ms/auth.php` / `passwords.php` / `validation.php` | 3 / 5 / 9 |

## Text the server sends already worded (known gaps for step 2)

Checked in step 1. Everything else the member app shows arrives as a code and is translated from `lang/member`. This
covers flash messages (`member.*` → `flash_*`), statuses, frequencies, cancellation reasons, and the form errors of
the slip upload, Pay all, 2FA and (since step 1) pay ahead.

| Text | Where it comes from | Languages | What a new language sees |
|---|---|---|---|
| Expense category names | `FundFigures` (`name`, `nameMs`), typed by the admin | English + Malay | English |
| "Welfare" / "Bank / gateway charges" slices | `FundFigures` constants | English + Malay | English |
| Rejection reason on a transfer slip | typed by the admin | as typed | as typed |
| Club, job level, fee and gateway names | data | as stored | as stored |
| Privacy notice | Portal settings, written by the owner | Malay and/or English | the English, else the Malay |
| "Too many wrong codes" when turning 2FA off | `ProfileController` code `2fa_disable_locked` | text says "15 minutes" | the fixed 15 minutes, even when less is left |
| Password-change email time | `account-security-notice` (`d/m/Y H:i`) | numeric | numeric, day first |
| Sign-in, password and 2FA pages | `__()`, `trans()` (`lang/<code>.json`, `lang/<code>/*.php`) | per locale | your files, else English |
| `DisplayStatus::LABELS` (`bm` / `en`) | admin side only; members get the status code | Malay + English | not shown to members |

Member pages never set Laravel's locale (only the sign-in pages and mail do), so a sentence a member controller made
with `__()` would come out in English. None does today: keep member messages as `member.*` codes.

Also found by the pseudo-language check (below):
- **"(beta)"** after a beta language's name is the same in every language. It is not a key.
- **Label and value side by side** (heading-style, not sentences): "Invoice INV-000020", "Registration no. …",
  "Reason: …", "Discount: …". The label is a key (`invoice`, `regNo`, `reason`, `discount`) and the value follows
  it.
- **`trans_choice` in emails** picks forms with Laravel's plural rules for the locale:
  - for `zh`, `ta`, `fr` and `es` they are right;
  - for a code Laravel does not know (the pseudo `xx`) it always takes the first form ("[69 day overdue]").

## Before translating: the pseudo-language gate

`php scripts/i18n-pseudo.php` writes a made-up language `xx` ("[Pseudo] (beta)", `<html lang="en-XA">`) into a
**test or dev copy**:
- every text is English with accented letters, in `[ ]`, about 40% longer;
- run `npm run build` after it; `--clean` removes it.

It is never shipped:
- the files are in `.gitignore`, and the release zip is a `git archive` export;
- the script refuses to run where `.env` says `APP_ENV=production`;
- `tests/Unit/PseudoLangTest.php` checks both, and the transform.

Result at the end of step 1, on a scratch copy with the e2e seed. The pass covered the sign-in, reset and failed
sign-in pages, and the member pages for four members: home, payments, fund, receipt, paid result, security,
transfer review (verifying), pay all, review, unpaid invoice, group slip result. Two emails were sent too: the
payment confirmation and the overdue reminder.
- **No unbracketed words** in member-page text or attributes, except:
  - data: club and member names, RM amounts, invoice, receipt and YURAN- references, bank details, file names,
    the year picker;
  - the other languages' own names in the pickers;
  - the label "XX";
  - "(beta)".
- **(b) The pickers.** `xx` appears in both pickers with no code change. Choosing it sets `cp_lang=xx` and
  `<html lang="en-XA">`, and saves `users.locale = xx`. A fresh browser opens in `xx`.
- **(b) The emails.** Both emails came out entirely bracketed, with `<html lang="en-XA">` and the dates in its
  `dateFormat`.
- **(c) `langFromLocale`.** It now returns the code when it has a file (`xx` stays `xx`). Before step 1 it turned
  every non-English locale into Malay.
- **Privacy page.** It showed the Malay notice to every language but English. A third language now gets the
  English notice (Malay readers still get Malay).

## Screenshots

- Chinese and Tamil need system fonts on the machine that takes browser screenshots: `fonts-noto-cjk` and
  `fonts-noto-core` (Tamil) on Debian or Kali. The Kali test box was checked at the end of step 1:
  - `fc-list :lang=zh` lists only Droid Sans Fallback, which draws Chinese but not well;
  - `fc-list :lang=ta` lists only GNU FreeFont (FreeSans, FreeSerif, FreeSerifBold);
  - install both Noto packages before taking step 2 screenshots.
- The app does not ship CJK or Tamil web fonts. The UI font stack falls back to the system font for those
  characters.
