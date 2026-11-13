import { createContext, useContext } from "react";

export type Lang = "bm" | "en";

const dict = {
  navHome: { bm: "Utama", en: "Home" },
  navPayments: { bm: "Bayaran", en: "Payments" },
  navFund: { bm: "Dana", en: "Fund" },
  navAccount: { bm: "Akaun", en: "Account" },
  hello: { bm: "Assalamualaikum & salam sejahtera", en: "Hello" },
  amountDue: { bm: "Jumlah perlu dibayar", en: "Amount due" },
  payNow: { bm: "Bayar", en: "Pay" },
  overdueTitle: { bm: "1 bayaran tertunggak", en: "1 payment overdue" },
  overdueBody: {
    bm: "Yuran Oktober 2026 telah lewat. Ia termasuk dalam jumlah di atas.",
    en: "October 2026 fee is past due. It is included in the amount above.",
  },
  includes: { bm: "Termasuk", en: "Includes" },
  upcoming: { bm: "Akan datang", en: "Upcoming" },
  due: { bm: "Tarikh akhir", en: "Due" },
  viewAll: { bm: "Lihat semua", en: "View all" },
  moneyGoes: { bm: "Ke mana wang anda pergi", en: "Where your money goes" },
  moneyGoesSub: { bm: "Tahun 2026 setakat ini, item yang diluluskan sahaja", en: "2026 so far, approved items only" },
  collected: { bm: "Dikutip", en: "Collected" },
  spent: { bm: "Dibelanjakan", en: "Spent" },
  balance: { bm: "Baki", en: "Balance" },
  welfare: { bm: "Kebajikan", en: "Welfare" },
  welfareCases: { bm: "kes kebajikan dibantu", en: "welfare cases helped" },
  bankCharges: { bm: "Caj bank & FPX", en: "Bank & FPX charges" },
  aggregatesOnly: {
    bm: "Hanya jumlah keseluruhan dipaparkan. Dokumen dan nama penerima tidak dikongsi.",
    en: "Totals only. Documents and recipient names are not shared.",
  },
  invoice: { bm: "Invois", en: "Invoice" },
  reviewPay: { bm: "Semak & bayar", en: "Review & pay" },
  billedTo: { bm: "Dibilkan kepada", en: "Billed to" },
  item: { bm: "Butiran", en: "Item" },
  amount: { bm: "Amaun", en: "Amount" },
  total: { bm: "Jumlah", en: "Total" },
  payWith: { bm: "Kaedah bayaran", en: "Payment method" },
  fpxLine: { bm: "Perbankan dalam talian FPX", en: "FPX online banking" },
  viaGateway: { bm: "melalui ToyyibPay", en: "via ToyyibPay" },
  chooseBank: { bm: "Pilih bank anda", en: "Choose your bank" },
  handoff: {
    bm: "Anda akan dibawa ke ToyyibPay, kemudian ke laman bank anda. Jangan tutup tetingkap sehingga selesai.",
    en: "You will go to ToyyibPay, then to your bank. Don't close the window until it finishes.",
  },
  continueTo: { bm: "Teruskan ke ToyyibPay", en: "Continue to ToyyibPay" },
  noFees: { bm: "Tiada caj tambahan kepada anda.", en: "No extra charge to you." },
  successTitle: { bm: "Bayaran berjaya", en: "Payment successful" },
  successBody: { bm: "Terima kasih. Resit telah dihantar ke e-mel anda.", en: "Thank you. A receipt has been emailed to you." },
  receiptNo: { bm: "No. resit", en: "Receipt no." },
  paidOn: { bm: "Dibayar pada", en: "Paid on" },
  bank: { bm: "Bank", en: "Bank" },
  reference: { bm: "Rujukan", en: "Reference" },
  viewReceipt: { bm: "Lihat resit", en: "View receipt" },
  backHome: { bm: "Kembali ke Utama", en: "Back to Home" },
  pendingTitle: { bm: "Kami sedang mengesahkan bayaran anda…", en: "We're confirming your payment…" },
  pendingBody: {
    bm: "Bank belum memberi jawapan muktamad. Ini biasanya mengambil masa beberapa minit.",
    en: "Your bank hasn't given a final answer yet. This usually takes a few minutes.",
  },
  dontPayAgain: { bm: "Jangan bayar sekali lagi.", en: "Don't pay again." },
  pendingHint: {
    bm: "Kami akan kemas kini status dan menghantar resit secara automatik.",
    en: "We'll update the status and email your receipt automatically.",
  },
  checkAgain: { bm: "Semak status semula", en: "Check status again" },
  failedTitle: { bm: "Bayaran tidak berjaya", en: "Payment not completed" },
  failedBody: { bm: "Bank anda menolak atau membatalkan transaksi ini.", en: "Your bank declined or cancelled this transaction." },
  failedMoneyLeft: {
    bm: "Jika wang telah keluar dari akaun anda, jangan bayar lagi. Hubungi bendahari dengan rujukan di bawah.",
    en: "If money left your account, don't pay again. Contact the treasurer with the reference below.",
  },
  tryAgain: { bm: "Cuba lagi", en: "Try again" },
  contactTreasurer: { bm: "Hubungi bendahari", en: "Contact the treasurer" },
  history: { bm: "Sejarah bayaran", en: "Payment history" },
  historySub: { bm: "Semua bayaran anda bagi 2026", en: "All your 2026 payments" },
  paid: { bm: "Dibayar", en: "Paid" },
  pending: { bm: "Belum dibayar", en: "Pending" },
  overdue: { bm: "Tertunggak", en: "Overdue" },
  receipt: { bm: "Resit rasmi", en: "Official receipt" },
  receivedFrom: { bm: "Diterima daripada", en: "Received from" },
  method: { bm: "Kaedah", en: "Method" },
  receiptFoot: {
    bm: "Resit ini dijana oleh komputer dan tidak memerlukan tandatangan.",
    en: "This is a computer-generated receipt; no signature is required.",
  },
  share: { bm: "Kongsi", en: "Share" },
  download: { bm: "Muat turun PDF", en: "Download PDF" },
  account: { bm: "Akaun saya", en: "My account" },
  memberNo: { bm: "No. ahli", en: "Member no." },
  phone: { bm: "Telefon", en: "Phone" },
  email: { bm: "E-mel", en: "Email" },
  language: { bm: "Bahasa", en: "Language" },
  fundTitle: { bm: "Dana kelab", en: "Club fund" },
  fundSub: { bm: "Bagaimana yuran digunakan tahun ini", en: "How fees are used this year" },
  spentBy: { bm: "Perbelanjaan mengikut kategori", en: "Spending by category" },
  fullReport: {
    bm: "Penyata penuh dibentangkan di Mesyuarat Agung Tahunan.",
    en: "The full statement is presented at the AGM.",
  },
  back: { bm: "Kembali", en: "Back" },
  overdueBadge: { bm: "Lewat", en: "Late" },
  forMonths: { bm: "Okt & Nov 2026", en: "Oct & Nov 2026" },
} as const;

export type DictKey = keyof typeof dict;

export const LangContext = createContext<{ lang: Lang; setLang: (l: Lang) => void }>({
  lang: "bm",
  setLang: () => undefined,
});

export function useT() {
  const { lang } = useContext(LangContext);
  return (key: DictKey) => dict[key][lang];
}

export function useLang() {
  return useContext(LangContext);
}
