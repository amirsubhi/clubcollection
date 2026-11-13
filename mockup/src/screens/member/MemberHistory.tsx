import { ChevronRight } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { PaymentStatusBadge } from "@/components/app/StatusBadges";
import { Card, CardContent } from "@/components/ui/card";
import { memberPayments } from "@/data/payments";
import type { PaymentStatus } from "@/data/types";
import { fmtDate } from "@/lib/format";
import { useLang, useT } from "@/i18n";

export function MemberHistory() {
  const t = useT();
  const { lang } = useLang();
  const label = (s: PaymentStatus) => t(s);
  const paidTotal = memberPayments.filter((p) => p.status === "paid").reduce((s, p) => s + p.amount, 0);
  const owing = memberPayments.filter((p) => p.status !== "paid").reduce((s, p) => s + p.amount, 0);
  return (
    <MemberShell tab="payments" title={t("history")}>
      <div className="grid grid-cols-2 gap-2">
        <Card>
          <CardContent className="p-3 sm:p-3">
            <div className="text-xs text-muted-foreground">{t("paid")} 2026</div>
            <Money sen={paidTotal} className="font-display text-lg font-semibold text-success-foreground" />
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-3 sm:p-3">
            <div className="text-xs text-muted-foreground">{t("pending")}</div>
            <Money sen={owing} className="font-display text-lg font-semibold text-warning-foreground" />
          </CardContent>
        </Card>
      </div>
      <Card>
        <ul className="divide-y" aria-label={t("historySub")}>
          {memberPayments.map((p) => {
            const href = p.status === "paid" ? "#member-receipt" : "#member-invoice";
            return (
              <li key={p.id}>
                <a href={href} className="flex items-center gap-3 px-4 py-3 hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring">
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium">{lang === "bm" ? p.descriptionBm : p.description}</div>
                    <div className="truncate text-xs text-muted-foreground">
                      {p.status === "paid" && p.paidDate
                        ? `${fmtDate(p.paidDate, lang)} · ${p.receiptNo}`
                        : `${t("due")} ${fmtDate(p.dueDate, lang)}`}
                    </div>
                  </div>
                  <div className="flex flex-col items-end gap-1">
                    <Money sen={p.amount} className="text-sm font-semibold" />
                    <PaymentStatusBadge status={p.status} label={label(p.status)} />
                  </div>
                  <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                </a>
              </li>
            );
          })}
        </ul>
      </Card>
    </MemberShell>
  );
}
