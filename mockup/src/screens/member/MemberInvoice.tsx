import { Lock } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { PaymentStatusBadge } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { club, currentMember } from "@/data/club";
import { amountDue, dueNow } from "@/data/payments";
import { fmtDate, rm } from "@/lib/format";
import { useLang, useT } from "@/i18n";

export function MemberInvoice() {
  const t = useT();
  const { lang } = useLang();
  return (
    <MemberShell tab="payments" title={t("invoice")} backHash="member-home">
      <section className="flex flex-col gap-5 rounded-[20px] border bg-card p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="text-sm text-muted-foreground">{club.name}</div>
            <div className="font-mono text-lg">INV-2026-001411</div>
          </div>
          <PaymentStatusBadge status="overdue" label={t("overdue")} size="sm" />
        </div>
        <dl className="grid grid-cols-2 gap-3 text-base">
          <div className="min-w-0">
            <dt className="text-sm text-muted-foreground">{t("billedTo")}</dt>
            <dd className="font-semibold">{currentMember.name}</dd>
            <dd className="font-mono text-sm text-muted-foreground">{currentMember.memberNo}</dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">{t("due")}</dt>
            <dd className="font-semibold">{fmtDate("2026-10-31", lang)}</dd>
          </div>
        </dl>
        <table className="w-full text-base">
          <thead>
            <tr className="border-b text-left text-sm text-muted-foreground">
              <th className="py-2 font-medium">{t("item")}</th>
              <th className="py-2 text-right font-medium">{t("amount")}</th>
            </tr>
          </thead>
          <tbody>
            {dueNow.map((p) => (
              <tr key={p.id} className="border-b">
                <td className="py-3 pr-2">{lang === "bm" ? p.descriptionBm : p.description}</td>
                <td className="py-3 text-right">
                  <Money sen={p.amount} />
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <td className="pt-3 font-semibold">{t("total")}</td>
              <td className="pt-3 text-right">
                <Money sen={amountDue} className="font-display text-2xl font-bold" />
              </td>
            </tr>
          </tfoot>
        </table>
      </section>
      <Button asChild size="xl" className="w-full rounded-full text-lg">
        <a href="#member-pay">
          <Lock aria-hidden /> {t("reviewPay")} {rm(amountDue)}
        </a>
      </Button>
    </MemberShell>
  );
}
