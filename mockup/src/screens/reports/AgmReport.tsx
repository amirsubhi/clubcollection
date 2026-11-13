import type { ReactNode } from "react";
import { CheckCircle2, Download, FileSpreadsheet } from "lucide-react";
import { AdminShell } from "@/components/app/AdminShell";
import { PageHeader } from "@/components/app/PageHeader";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { club } from "@/data/club";
import {
  bankCharges,
  closingBalance,
  closingByAccount,
  fy,
  openingBalance,
  openingByAccount,
  outstandingFees,
  paymentsByCategory,
  receipts,
  reconciliation,
  signOff,
  totalBankCharges,
  totalCategory,
  totalPayments,
  totalReceipts,
  totalWelfare,
  welfareCases,
  type Line,
} from "@/data/report";
import { num } from "@/lib/format";
import { cn } from "@/lib/utils";

function Section({ title, bm, children }: { title: string; bm: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-1">
      <h2 className="border-b-2 border-foreground/80 pb-1 font-display text-sm font-semibold uppercase tracking-wide">
        {bm} <span className="font-normal normal-case text-muted-foreground">· {title}</span>
      </h2>
      <table className="w-full text-sm">
        <tbody>{children}</tbody>
      </table>
    </section>
  );
}

function L({ label, sub, amount, indent = 0, strong, rule, sign }: { label: ReactNode; sub?: string; amount?: number; indent?: 0 | 1 | 2; strong?: boolean; rule?: "single" | "double"; sign?: "less" }) {
  return (
    <tr className={cn(strong && "font-semibold")}>
      <td className={cn("py-1 pr-3 align-top", indent === 1 && "pl-3 sm:pl-5", indent === 2 && "pl-6 sm:pl-10")}>
        {label}
        {sub && <span className="block text-xs font-normal text-muted-foreground">{sub}</span>}
      </td>
      <td
        className={cn(
          "money w-[112px] py-1 text-right align-top sm:w-[150px]",
          rule === "single" && "border-t border-foreground/60",
          rule === "double" && "border-b-[3px] border-double border-t border-foreground/80",
        )}
      >
        {amount === undefined ? "" : sign === "less" ? `(${num(amount)})` : num(amount)}
      </td>
    </tr>
  );
}

const lines = (ls: Line[], indent: 1 | 2 = 2) => ls.map((l) => <L key={l.label} label={l.label} sub={l.note} amount={l.amount} indent={indent} />);

export function AgmReport() {
  const balanced = openingBalance + totalReceipts - totalPayments === closingBalance && closingByAccount.reduce((s, l) => s + l.amount, 0) === closingBalance;
  const recOk = reconciliation.statementBalance - reconciliation.unpresented === reconciliation.bookBalance;
  return (
    <AdminShell active="agm-report" wide={false}>
      <PageHeader
        eyebrow="Reports · Laporan"
        title="AGM financial report"
        description="Preview of the statement presented at the Annual General Meeting. Only approved records are included."
        actions={
          <>
            <Button variant="outline" type="button">
              <FileSpreadsheet aria-hidden /> CSV
            </Button>
            <Button type="button">
              <Download aria-hidden /> Download PDF
            </Button>
          </>
        }
      />

      <Card className="mx-auto w-full p-4 sm:p-8">
        <div className="flex flex-col gap-6">
          <header className="text-center">
            <div className="font-display text-lg font-semibold">{club.name}</div>
            <div className="text-xs text-muted-foreground">No. pendaftaran {club.regNo}</div>
            <h1 className="mt-3 font-display text-base font-bold uppercase tracking-wide sm:text-lg">Penyata Penerimaan dan Pembayaran</h1>
            <div className="text-sm text-muted-foreground">Statement of Receipts and Payments</div>
            <div className="mt-1 text-sm">bagi tempoh {fy.periodBm}</div>
            <div className="mt-2 inline-block rounded bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning-foreground">DRAF · sample figures</div>
          </header>

          <div className="flex justify-end text-xs font-semibold text-muted-foreground">
            <span className="w-[112px] text-right sm:w-[150px]">RM</span>
          </div>

          <Section bm="Baki awal" title="Opening balance, 1 Jan 2026">
            {openingByAccount.map((l) => (
              <L key={l.label} label={l.bm} sub={l.label} amount={l.amount} indent={1} />
            ))}
            <L label="Jumlah baki awal" amount={openingBalance} strong rule="single" />
          </Section>

          <Section bm="Penerimaan" title="Receipts">
            {receipts.map((g) => (
              <FragmentRows key={g.heading} heading={g.bm} sub={g.heading} items={g.lines} />
            ))}
            <L label="Jumlah penerimaan" sub="Total receipts" amount={totalReceipts} strong rule="single" />
          </Section>

          <Section bm="Pembayaran" title="Payments">
            <L label="Perbelanjaan mengikut kategori" sub="Expenses by category" indent={1} strong />
            {paymentsByCategory.map((l) => (
              <L key={l.label} label={l.bm} sub={l.label} amount={l.amount} indent={2} />
            ))}
            <L label="" amount={totalCategory} rule="single" />
            <L label="Sumbangan kebajikan (setiap kes)" sub="Welfare, per case" indent={1} strong />
            {lines(welfareCases)}
            <L label="" amount={totalWelfare} rule="single" />
            <L label="Caj bank" sub="Bank charges (incl. payment gateway fees)" indent={1} strong />
            {lines(bankCharges)}
            <L label="" amount={totalBankCharges} rule="single" />
            <L label="Jumlah pembayaran" sub="Total payments" amount={totalPayments} strong rule="single" />
          </Section>

          <Section bm="Baki akhir" title="Closing balance, 31 Dec 2026">
            <L label="Baki awal" sub="Opening balance" amount={openingBalance} indent={1} />
            <L label="Tambah: Jumlah penerimaan" sub="Add: receipts" amount={totalReceipts} indent={1} />
            <L label="Tolak: Jumlah pembayaran" sub="Less: payments" amount={totalPayments} indent={1} sign="less" />
            <L label="Baki akhir" sub="Closing balance" amount={closingBalance} strong rule="double" />
            <L label="Diwakili oleh · Represented by" indent={1} strong />
            {closingByAccount.map((l) => (
              <L key={l.label} label={l.bm} sub={l.label} amount={l.amount} indent={2} />
            ))}
            <L label="" amount={closingBalance} rule="double" strong />
          </Section>

          <Section bm="Penyesuaian bank" title="Bank reconciliation, 31 Dec 2026">
            <L label="Baki penyata bank" sub="Balance per bank statement" amount={reconciliation.statementBalance} indent={1} />
            <L label="Tolak: cek belum dikemukakan" sub={reconciliation.unpresentedNote} amount={reconciliation.unpresented} indent={1} sign="less" />
            <L label="Baki bank mengikut buku" sub="Bank balance per books (agrees with above)" amount={reconciliation.bookBalance} strong rule="double" />
          </Section>

          <div className="flex flex-col gap-1 rounded-md bg-muted p-3 text-sm">
            <div>
              Yuran tertunggak · Outstanding fees: <strong>{outstandingFees.members} members</strong>, RM {num(outstandingFees.amount)} (not included above; this is a
              receipts and payments statement).
            </div>
            <div className={cn("flex items-center gap-1.5 text-xs", balanced && recOk ? "text-success-foreground" : "text-danger-foreground")}>
              <CheckCircle2 className="h-4 w-4" aria-hidden />
              Checks: opening + receipts − payments = closing; cash + bank = closing; bank reconciles.
            </div>
          </div>

          <section aria-label="Sign-off" className="grid grid-cols-1 gap-6 pt-4 sm:grid-cols-2">
            {signOff.map((s) => (
              <div key={s.name} className="flex flex-col gap-1 text-sm">
                <div className="h-12 border-b border-dashed border-foreground/50" aria-hidden />
                <div className="font-semibold">{s.name}</div>
                <div className="text-muted-foreground">
                  {s.role} <span className="text-xs">({s.en})</span>
                </div>
                <div className="text-xs text-muted-foreground">Tarikh: ____________</div>
              </div>
            ))}
          </section>
          <p className="text-xs text-muted-foreground">
            Lampiran (appendices, in the PDF): index of voucher and receipt numbers, attachment index with SHA-256 hashes.
          </p>
        </div>
      </Card>
    </AdminShell>
  );
}

function FragmentRows({ heading, sub, items }: { heading: string; sub: string; items: Line[] }) {
  const subtotal = items.reduce((s, l) => s + l.amount, 0);
  return (
    <>
      <L label={heading} sub={sub} indent={1} strong />
      {lines(items)}
      <L label="" amount={subtotal} rule="single" />
    </>
  );
}
