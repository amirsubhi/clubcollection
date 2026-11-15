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
  paidDate: string | null;
  clubId: number;
  /** Present on Home rows. */
  clubName?: string;
  urls: { invoice: string; review: string | null };
}

/** MemberPaymentPresenter::receipt(): only for paid fees. */
export interface MemberReceipt {
  receiptNo: string;
  amount: Sen;
  paidAt: string;
  /** "ToyyibPay" / "Billplz", or null when the treasurer recorded it by hand. */
  gateway: string | null;
  transactionId: string | null;
  reference: string | null;
}

/** Props every single-payment page receives (PaymentController::context()). */
export interface PaymentContext {
  member: { name: string; email: string };
  payClub: { id: number; name: string; email: string | null };
  paymentsUrl: string;
  dashboardUrl: string;
}

export interface FundCategory {
  name: string;
  amount: Sen;
}

export interface HomeClub {
  id: number;
  name: string;
  jobLevel: string;
  role: string;
  paymentsUrl: string;
  summary: { paid: number; pending: number; overdue: number; totalPaid: Sen };
  fund: { collected: Sen; spent: Sen; categories: FundCategory[] };
}
