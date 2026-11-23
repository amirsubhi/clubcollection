# New wording for member emails (for the owner to approve)

Language phase 2 sends each member email in one language, the member's (saved choice, else the portal default). The
three emails that were English only (welcome, payment confirmation, overdue reminder) and Laravel's password reset
email needed Malay; the three that were in BM and EN together (sign-in security notice, transfer slip rejected,
payment cancelled) are now split, which needed a little new English. Everything is in `lang/ms.json`: the English
sentence is the key, the Malay its value. `**...**` marks bold words, `:name`-style words are filled in when the email is
sent. Dates use the member app's month names (`lang/member/*.json`: Mac, Ogo, Dis ...).

Please check the **42 strings in the first table** (new Malay, plus 2 new English sentences, marked).
The second table only lists Malay that was already in the old emails or the member app, reused as it was, for
reference. Admin-side text (the "Default language" field on Portal settings) is English and not listed.

To change a translation: edit its value in `lang/ms.json` (keep the `:words` and the `**` pairs; a test checks them).

## To approve

| Email | English (key) | Malay |
|---|---|---|
| Member welcome | Welcome to :club — Your Account Details | Selamat datang ke :club — Butiran akaun anda |
| Member welcome | Welcome to :club | Selamat datang ke :club |
| Member welcome | Your account has been created | Akaun anda telah dicipta |
| Member welcome | Hi **:name**, | Salam **:name**, |
| Member welcome | An administrator has created your account on **:portal**. You can now log in and manage your club membership fees. | Pentadbir telah mencipta akaun anda di **:portal**. Anda kini boleh log masuk dan menguruskan yuran keahlian kelab anda. |
| Member welcome | Temporary password | Kata laluan sementara |
| Member welcome | **Important:** This is a temporary password. When you first sign in you will be asked to choose your own password. | **Penting:** Ini kata laluan sementara. Apabila anda log masuk kali pertama, anda akan diminta memilih kata laluan anda sendiri. |
| Member welcome | Login Now | Log masuk sekarang |
| Member welcome | If you did not expect this email, please contact your club administrator. | Jika anda tidak menjangka e-mel ini, sila hubungi pentadbir kelab anda. |
| Member welcome | Do not reply to this email. | Jangan balas e-mel ini. |
| Overdue reminder | Action Required: Overdue Club Fee — :club | Tindakan diperlukan: yuran kelab lewat — :club |
| Overdue reminder | Payment Overdue | Bayaran lewat |
| Overdue reminder | Dear **:name**, | Salam **:name**, |
| Overdue reminder | Your club membership fee payment is **overdue**. Please settle it as soon as possible to avoid any disruption to your membership. | Bayaran yuran keahlian kelab anda sudah **lewat**. Sila jelaskan secepat mungkin supaya keahlian anda tidak terganggu. |
| Overdue reminder | Originally due on **:date** | Tarikh akhir asal **:date** |
| Overdue reminder | 1 day overdue | Lewat 1 hari |
| Overdue reminder | :count days overdue | Lewat :count hari |
| Overdue reminder | Club | Kelab |
| Overdue reminder | Payment Type | Jenis bayaran |
| Overdue reminder | Pay Now | Bayar sekarang |
| Overdue reminder | If you have already made this payment, please contact your club administrator. | Jika anda sudah membuat bayaran ini, sila hubungi pentadbir kelab anda. |
| Overdue reminder | This is an automated reminder, please do not reply. | Ini peringatan automatik, sila jangan balas. |
| Payment confirmation | Payment Confirmed – :club | Bayaran disahkan – :club |
| Payment confirmation | Payment Confirmed | Bayaran disahkan |
| Payment confirmation | Your payment has been received and confirmed. Thank you! | Bayaran anda telah diterima dan disahkan. Terima kasih! |
| Payment confirmation | 1 fee paid together | 1 yuran dibayar sekali gus |
| Payment confirmation | bill :code | bil :code |
| Payment confirmation | Bank transfer / DuitNow (verified by the club) | Pindahan bank / DuitNow (disahkan oleh kelab) |
| Payment confirmation | Bank reference | Rujukan bank |
| Payment confirmation | Transaction ID | ID transaksi |
| Payment confirmation | Please keep this email as your payment receipt. If you have any questions, contact your club administrator. | Sila simpan e-mel ini sebagai resit bayaran anda. Jika ada sebarang pertanyaan, hubungi pentadbir kelab anda. |
| Payment confirmation | This is an automated email, please do not reply. | Ini e-mel automatik, sila jangan balas. |
| Transfer slip rejected | Transfer slip not accepted yet *(new English)* | Slip pindahan belum diterima |
| Payment cancelled | The fees below are **unpaid** again. *(new English)* | Yuran di bawah **belum dibayar**. |
| Laravel's password reset email | Reset your password | Tetapkan semula kata laluan anda |
| Laravel's password reset email | You are receiving this email because we received a password reset request for your account. | Anda menerima e-mel ini kerana kami menerima permintaan untuk menetapkan semula kata laluan akaun anda. |
| Laravel's password reset email | This password reset link will expire in :count minutes. | Pautan untuk menetapkan semula kata laluan ini akan tamat tempoh dalam :count minit. |
| Laravel's password reset email | If you did not request a password reset, no further action is required. | Jika anda tidak meminta untuk menetapkan semula kata laluan, tiada tindakan lanjut diperlukan. |
| Laravel's password reset email | Hello! | Salam! |
| Laravel's password reset email | Regards, | Salam hormat, |
| Laravel's password reset email | If you're having trouble clicking the ":actionText" button, copy and paste the URL below<br>into your web browser: | Jika butang ":actionText" tidak berfungsi, salin dan tampal URL di bawah<br>ke dalam pelayar web anda: |
| Laravel's password reset email | All rights reserved. | Hak cipta terpelihara. |

## Already approved (reused unchanged)

| Email | English (key) | Malay |
|---|---|---|
| Account security notice | Sign-in attempts on your account – :portal | Cubaan log masuk ke akaun anda – :portal |
| Account security notice | Your password was changed – :portal | Kata laluan anda telah ditukar – :portal |
| Account security notice | Sign-in attempts on your account | Cubaan log masuk ke akaun anda |
| Account security notice | Your password was changed | Kata laluan anda telah ditukar |
| Account security notice | Hello **:name**, | Salam **:name**, |
| Account security notice | Someone tried several times to sign in to your :portal account with the wrong password. To protect your account, signing in is paused for :minutes minutes. | Seseorang telah beberapa kali cuba log masuk ke akaun :portal anda dengan kata laluan yang salah. Untuk melindungi akaun anda, log masuk dihentikan seketika selama :minutes minit. |
| Account security notice | If it was you, there is nothing to do: wait a little and try again, or reset your password now. | Jika itu anda, tiada apa yang perlu dibuat: tunggu sebentar dan cuba lagi, atau tetapkan semula kata laluan anda sekarang. |
| Account security notice | **If it was not you,** reset your password and tell your club admin. | **Jika itu bukan anda,** tetapkan semula kata laluan anda dan beritahu pentadbir kelab anda. |
| Account security notice | The password of your :portal account was just changed (:time). | Kata laluan akaun :portal anda baru sahaja ditukar (:time). |
| Account security notice | If it was you, there is nothing to do. | Jika itu anda, tiada apa yang perlu dibuat. |
| Account security notice | **If it was not you,** contact your club admin straight away, and reset your password. | **Jika itu bukan anda,** hubungi pentadbir kelab anda segera, dan tetapkan semula kata laluan anda. |
| Account security notice | This is an automatic security email. We will never ask for your password by email. | Ini e-mel keselamatan automatik. Kami tidak akan sekali-kali meminta kata laluan anda melalui e-mel. |
| Overdue reminder | Period | Tempoh |
| Overdue reminder | Monthly | Bulanan |
| Overdue reminder | Quarterly | Suku tahunan |
| Overdue reminder | Yearly | Tahunan |
| Overdue reminder | Due Date | Tarikh akhir |
| Overdue reminder | Amount | Amaun |
| Payment confirmation | :count fees paid together | :count yuran dibayar sekali gus |
| Payment confirmation | Receipt no. | No. resit |
| Payment confirmation | Paid On | Dibayar pada |
| Payment confirmation | Method | Kaedah |
| Payment confirmation | Reference | Rujukan |
| Payment confirmation | Status | Status |
| Payment confirmation | Paid | Dibayar |
| Transfer slip rejected | Transfer slip needs another look – :club | Slip pindahan perlu dihantar semula – :club |
| Transfer slip rejected | The treasurer checked your transfer slip for this fee but could not confirm it yet, so the fee is still **unpaid**. | Bendahari telah menyemak slip pindahan anda untuk yuran di bawah, tetapi belum dapat mengesahkannya. Yuran ini masih **belum dibayar**. |
| Transfer slip rejected | Reason: | Sebab: |
| Transfer slip rejected | Total | Jumlah |
| Transfer slip rejected | Please read the reason above, then send the right slip or pay online. If money already left your account, don't pay again: contact the treasurer. | Sila semak sebab di atas, kemudian hantar slip yang betul atau bayar dalam talian. Jika wang telah keluar dari akaun anda, jangan bayar sekali lagi. Hubungi bendahari. |
| Transfer slip rejected | Review & pay | Semak & bayar |
| Transfer slip rejected | Automated email, please do not reply. | E-mel automatik, jangan balas. |
| Payment cancelled | Payment cancelled – :club | Bayaran dibatalkan – :club |
| Payment cancelled | Payment cancelled | Bayaran dibatalkan |
| Payment cancelled | Receipt **:no** was cancelled on :date. | Resit **:no** telah dibatalkan pada :date. |
| Payment cancelled | We could not confirm this payment. | Kami tidak dapat mengesahkan bayaran ini. |
| Payment cancelled | This payment was recorded by mistake. | Bayaran ini telah direkodkan secara tersilap. |
| Payment cancelled | This payment was refunded to you. | Bayaran ini telah dikembalikan kepada anda. |
| Payment cancelled | Contact the club treasurer for details. | Hubungi bendahari kelab untuk maklumat lanjut. |
| Payment cancelled | Your transfer slip is still being checked by the treasurer; don't pay again. | Slip pindahan anda masih disemak oleh bendahari; jangan bayar sekali lagi. |
| Payment cancelled | This fee is **unpaid** again. | Yuran ini **belum dibayar**. |
| Payment cancelled | Receipt cancelled | Resit dibatalkan |
| Payment cancelled | You can pay again as usual and you will get a new receipt with a new number. If money already left your account and was not refunded, don't pay again: contact the treasurer. | Anda boleh membayar semula seperti biasa; resit baharu dengan nombor baharu akan dikeluarkan. Jika wang telah keluar dari akaun anda dan belum dikembalikan, jangan bayar sekali lagi: hubungi bendahari. |
| Payment cancelled | View the fee | Lihat yuran |
| Laravel's password reset email | Reset Password | Tetapkan semula kata laluan |
