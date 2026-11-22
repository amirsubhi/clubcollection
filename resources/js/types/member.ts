import type { PaymentStatus, Sen } from "./domain";

/** App\Support\Member\MemberPaymentPresenter::summary(). Money is integer sen. */
export interface MemberPaymentRow {
  id: number;
  invoiceNo: string;
  periodStart: string;
  periodEnd: string;
  frequency: "monthly" | "quarterly" | "yearly";
  amount: Sen;
  dueDate: string;
  status: PaymentStatus;
  /** Due or overdue and its due date has passed: listed under "Perlu dibayar", not "Akan datang". */
  pastDue?: boolean;
  /** Last day of the grace period ("Lewat selepas <date>"); set only while a past-due fee is still "due". */
  lateAfter?: string | null;
  paidDate: string | null;
  clubId: number;
  /** Present on Home rows. */
  clubName?: string;
  /** billStatus: where to check an FPX payment in progress (status "processing" only). */
  urls: { invoice: string; review: string | null; billStatus?: string | null };
}

/** MemberPaymentPresenter::receipt(): only for paid fees. */
export interface MemberReceipt {
  receiptNo: string;
  amount: Sen;
  paidAt: string;
  /** "ToyyibPay" / "Billplz", or null when the treasurer recorded it by hand. */
  gateway: string | null;
  /** How it was paid: online (gateway), bank transfer checked by the treasurer, or recorded by hand. */
  method: "gateway" | "transfer" | "manual";
  transactionId: string | null;
  reference: string | null;
  /** The club as the receipt was issued (payments.issuer_snapshot; the live club for older fees). */
  issuer?: ReceiptIssuer;
  /** Paid in a set ("Bayar semua"): the number is the set's receipt; this page shows one fee of it. */
  partOf?: { receiptNo: string; amount: Sen; count: number; url: string } | null;
}

/** MemberPaymentPresenter::cancellation(): an admin cancelled a payment ("Cancel payment"); reason is a code worded by the page. */
export interface PaymentCancellationInfo {
  receiptNo: string;
  cancelledOn: string;
  reason: "slip_not_genuine" | "money_not_received" | "recorded_by_mistake" | "refunded" | "other" | string;
}

/** MemberPaymentPresenter::issuer(): who issued a receipt. logoUrl is a same-origin /storage URL. */
export interface ReceiptIssuer {
  name: string;
  registrationNo: string | null;
  address: string | null;
  email: string | null;
  logoUrl: string | null;
  /** The club's receipt footer as issued (plain text, may hold line breaks); null when none. */
  footer?: string | null;
}

/** Props every single-payment page receives (PaymentController::context()). */
export interface PaymentContext {
  member: { name: string; email: string };
  /** whatsapp: digits for wa.me when the club set a help number (PaymentController::payClubProps). */
  payClub: {
    id: number;
    name: string;
    email: string | null;
    whatsapp: string | null;
    /** Club profile as it is now (invoice header). */
    logoUrl?: string | null;
    registrationNo?: string | null;
    address?: string | null;
  };
  paymentsUrl: string;
  dashboardUrl: string;
}

export interface FundCategory {
  name: string;
  /** BM name when it differs (FundFigures::CHARGES_LABEL_MS for bank / gateway charges). */
  nameMs?: string;
  /** expense (a category), welfare (one total, never a case count) or charges (bank / gateway). */
  kind?: "expense" | "welfare" | "charges";
  amount: Sen;
}

export interface HomeClub {
  id: number;
  name: string;
  /** Club profile logo (same-origin /storage URL), or null. */
  logoUrl: string | null;
  /** Month (1-12) the club's financial year starts; 1 = the calendar year. */
  fyStartMonth: number;
  jobLevel: string;
  role: string;
  paymentsUrl: string;
  summary: { paid: number; due: number; overdue: number; processing: number; verifying: number; totalPaid: Sen };
  /** "Ke mana wang anda pergi": the club's fund page (Member/Fund). */
  fundUrl: string;
}

/** Member/Fund: FundFigures for this year so far, the same figures the club's admins see. spent = expenses + welfare + charges. */
export interface MemberFund {
  window: { from: string; to: string; range: string };
  collected: Sen;
  spent: Sen;
  expenses: Sen;
  welfare: Sen;
  /** Bank / gateway charges the club absorbed. */
  charges: Sen;
  /** Spending above what came in this year (paid from earlier savings); 0 when none. */
  fromReserves: Sen;
  /** Fund balance (baki dana) at the end of today. */
  balance: Sen;
  categories: FundCategory[];
}

/** PaymentController::transferProps(): the club's bank details for a manual transfer. */
export interface TransferOption {
  bankName: string;
  accountName: string;
  accountNo: string | null;
  duitnowId: string | null;
  /** What the member types as the transfer reference: YURAN-{id}, or YURAN-G{id} for "Bayar semua". */
  reference: string;
  submitUrl: string;
  today: string;
  minDate: string;
  maxFiles: number;
  maxMb: number;
}

/** PaymentController::verificationProps(): a slip waiting for the treasurer. */
export interface TransferVerification {
  submittedAt: string | null;
  transferDate: string | null;
  transferRef: string | null;
  slips: { id: number; name: string; isPdf: boolean; url: string }[];
}

/** "Bayar semua" (R1b): every fee still owed in one club, offered from two fees on. */
export interface PayAllOffer {
  url: string;
  totalSen: Sen;
  count: number;
  clubName: string;
}

/** PayAllController::groupProps(): the fees of a "Bayar semua" set, oldest first. */
export interface PaymentGroup {
  fees: MemberPaymentRow[];
  paymentIds: number[];
  /** Sum of the fees, without any gateway charge. */
  sumSen: Sen;
  /** Set on the result page: how the group was paid. */
  method: "gateway" | "transfer" | null;
}

/** MemberPaymentPresenter::groupReceipt(): one receipt, one line per fee (each with its own receipt no.). */
export interface GroupReceipt extends MemberReceipt {
  lines: { id: number; receiptNo: string; invoiceNo: string; periodStart: string; periodEnd: string; amount: Sen; invoiceUrl: string }[];
  /** Slip receipt only: fees of the slip paid online instead (not in the total). */
  paidOnline: { id: number; periodStart: string; periodEnd: string; amount: Sen; invoiceUrl: string }[];
}
