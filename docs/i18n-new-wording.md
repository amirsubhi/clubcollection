# Malay wording for the owner to approve

**Approved by the owner on 2026-11-23**, with three owner changes: "Data protection contact" is "Pegawai
perlindungan data"; the overdue heading is "Bayaran tertunggak" (as its subject); and the "Salam" openers greet by
the Malaysia time instead (section 3).

Rule: Malaysian Malay only (DBP usage), never Indonesian (`tests/Unit/MalayWordingTest.php` refuses a list of
Indonesian words in every Malay file). Text lives in `lang/ms.json` (sign-in pages and member emails: the English
sentence is the key) and `lang/member/ms.json` (member app). To change a translation, edit its value and keep the
`:words` and the `**` pairs (bold); a test checks them.

**Live** = members already see the old wording in v2.0.1 (sign-in pages, member app, or the Malay half of the old
bilingual emails). **New** = Malay first added in this change (the emails that were English only in v2.0.1: welcome,
overdue reminder, Laravel's password reset).

## 1. Changes from the Malay review (before → after)

21 strings: 15 live, 6 new.

| | Where | Before | After |
|---|---|---|---|
| Live | Transfer slip rejected, heading | Slip pindahan belum diterima | Slip pindahan belum dapat disahkan |
| Live | Payment cancelled (set) | Yuran di bawah \*\*belum dibayar\*\*. | Yuran di bawah kembali \*\*belum dibayar\*\*. |
| Live | Payment cancelled (one fee) | Yuran ini \*\*belum dibayar\*\*. | Yuran ini kembali \*\*belum dibayar\*\*. |
| Live | Sign-in pages, help | Perlukan bantuan? Hubungi pentadbir kelab | Perlu bantuan? Hubungi pentadbir kelab (as the member app's "Perlu bantuan?") |
| Live | Sign-in pages, paused | Demi keselamatan anda, kami hentikan seketika. | Demi keselamatan anda, kami hentikan proses ini seketika. |
| Live | Front page | Ahli baru? Cara menyertai | Ahli baharu? Cara menyertai |
| Live | App `chargeByMember` | Kelab anda menetapkan caj gateway dibayar oleh ahli. | Kelab anda menetapkan caj bayaran dalam talian dibayar oleh ahli. |
| Live | App `methodTransferSub` | Pindah sendiri, kemudian hantar slip | Buat pindahan sendiri, kemudian hantar slip |
| Live | App `processingGroupNote` | Status dikemas kini sendiri apabila … | Status dikemas kini secara automatik apabila … |
| Live | App `processingWarnBody` | … jangan bayar lagi. … ia dikemas kini sendiri apabila … | … jangan bayar sekali lagi. … ia dikemas kini secara automatik apabila … |
| Live | App `openBillWarn` | … jangan bayar lagi. … | … jangan bayar sekali lagi. … |
| Live | App `failedMoneyLeftAdvice` | … jangan bayar lagi — hubungi bendahari … | … jangan bayar sekali lagi — hubungi bendahari … |
| Live | App `heroProcessingBody` | … Semak status dahulu sebelum bayar lagi. | … Semak status dahulu sebelum membayar sekali lagi. |
| Live | App `flash_check_status_first` | … dahulu sebelum bayar lagi. | … dahulu sebelum membayar sekali lagi. |
| Live | App `secCodesBody` | … guna satu kod ini untuk log masuk. … | … guna salah satu kod ini untuk log masuk. … |
| New | Overdue reminder, subject | Tindakan diperlukan: yuran kelab lewat — :club | Tindakan diperlukan: yuran kelab tertunggak — :club |
| New | Overdue reminder, body | Bayaran yuran keahlian kelab anda sudah \*\*lewat\*\*. … | Bayaran yuran keahlian kelab anda telah \*\*melepasi tarikh akhir\*\*. … |
| New | Welcome | … Apabila anda log masuk kali pertama, … | … Apabila anda log masuk buat kali pertama, … |
| New | Welcome | Jika anda tidak menjangka e-mel ini, sila hubungi pentadbir kelab anda. | Jika anda tidak sepatutnya menerima e-mel ini, sila hubungi pentadbir kelab anda. |
| New | Welcome | Jangan balas e-mel ini. | Sila jangan balas e-mel ini. |
| New | Password reset | Anda menerima e-mel ini kerana kami menerima permintaan untuk menetapkan semula kata laluan akaun anda. | Anda menerima e-mel ini kerana terdapat permintaan untuk menetapkan semula kata laluan akaun anda. |

**Owner's choice:** "Data protection contact" (privacy notice, live): "Hubungan perlindungan data" → "Pegawai
perlindungan data".

## 2. The other new email Malay (unchanged by the review)

| Email | English (key) | Malay |
|---|---|---|
| Welcome | Welcome to :club — Your Account Details | Selamat datang ke :club — Butiran akaun anda |
| Welcome | Welcome to :club | Selamat datang ke :club |
| Welcome | Your account has been created | Akaun anda telah dicipta |
| Welcome | Hi \*\*:name\*\*, | Selamat pagi / petang / malam \*\*:name\*\*, (section 3) |
| Welcome | An administrator has created your account on \*\*:portal\*\*. You can now log in and manage your club membership fees. | Pentadbir telah mencipta akaun anda di \*\*:portal\*\*. Anda kini boleh log masuk dan menguruskan yuran keahlian kelab anda. |
| Welcome | Temporary password | Kata laluan sementara |
| Welcome | Login Now | Log masuk sekarang |
| Overdue reminder | Payment Overdue | Bayaran tertunggak (owner: as the subject) |
| Overdue reminder | Dear \*\*:name\*\*, | Selamat pagi / petang / malam \*\*:name\*\*, (section 3) |
| Overdue reminder | Originally due on \*\*:date\*\* | Tarikh akhir asal \*\*:date\*\* |
| Overdue reminder | 1 day overdue / :count days overdue | Lewat 1 hari / Lewat :count hari |
| Overdue reminder | Club / Payment Type / Pay Now | Kelab / Jenis bayaran / Bayar sekarang |
| Overdue reminder | If you have already made this payment, please contact your club administrator. | Jika anda sudah membuat bayaran ini, sila hubungi pentadbir kelab anda. |
| Overdue reminder | This is an automated reminder, please do not reply. | Ini peringatan automatik, sila jangan balas. |
| Payment confirmation | Payment Confirmed – :club / Payment Confirmed | Bayaran disahkan – :club / Bayaran disahkan |
| Payment confirmation | Your payment has been received and confirmed. Thank you! | Bayaran anda telah diterima dan disahkan. Terima kasih! |
| Payment confirmation | 1 fee paid together / bill :code | 1 yuran dibayar sekali gus / bil :code |
| Payment confirmation | Bank transfer / DuitNow (verified by the club) | Pindahan bank / DuitNow (disahkan oleh kelab) |
| Payment confirmation | Bank reference / Transaction ID | Rujukan bank / ID transaksi |
| Payment confirmation | Please keep this email as your payment receipt. If you have any questions, contact your club administrator. | Sila simpan e-mel ini sebagai resit bayaran anda. Jika ada sebarang pertanyaan, hubungi pentadbir kelab anda. |
| Payment confirmation | This is an automated email, please do not reply. | Ini e-mel automatik, sila jangan balas. |
| Password reset | Reset your password (the subject) | Tetapkan semula kata laluan anda |
| Password reset | This password reset link will expire in :count minutes. | Pautan untuk menetapkan semula kata laluan ini akan tamat tempoh dalam :count minit. |
| Password reset | If you did not request a password reset, no further action is required. | Jika anda tidak meminta untuk menetapkan semula kata laluan, tiada tindakan lanjut diperlukan. |
| Password reset | Hello! / Regards, / All rights reserved. | Selamat pagi! (section 3) / Salam hormat, / Hak cipta terpelihara. |
| Password reset | If you're having trouble clicking the ":actionText" button, copy and paste the URL below`\n`into your web browser: | Jika butang ":actionText" tidak berfungsi, salin dan tampal URL di bawah`\n`ke dalam pelayar web anda: |

`\n` is a line break inside the key: Laravel's mail layout uses exactly this sentence, so the `lang/ms.json` key and
value both keep it (not `<br>`). The rest of the email Malay was already in the old bilingual emails or the member
app and is reused unchanged.

## 3. The greeting by the time of day (owner's request)

Every Malay member email opens with the Malaysia time it is written (`MailText::greeting`, `plainGreeting` for the
password reset's "Hello!"), not "Salam":

| Malaysia time | Greeting |
|---|---|
| 05:00 – 11:59 | Selamat pagi **nama**, |
| 12:00 – 18:59 | Selamat petang **nama**, |
| 19:00 – 04:59 | Selamat malam **nama**, |

Malaysia time is fixed (Asia/Kuala_Lumpur, as the 08:00 reminder schedule), not `APP_TIMEZONE`, which is UTC on an
install that never set it. It is the time the email is sent, not read: the daily overdue reminder (08:00) always says
"Selamat pagi". English and the other languages keep their own opener (Hi / Dear / Hello).
