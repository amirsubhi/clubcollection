import { AlertTriangle, CalendarClock, CheckCircle2, ChevronRight } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { PaymentStatusBadge } from "@/components/app/StatusBadges";
import { IconBubble } from "@/components/app/Visuals";
import { memberPayments } from "@/data/payments";
import type { PaymentStatus } from "@/data/types";
import { fmtDate } from "@/lib/format";
import { useLang, useT } from "@/i18n";

const bubble: Record<PaymentStatus, { Icon: typeof CheckCircle2; tone: "mint" | "danger" | "info" }> = {
  paid: { Icon: CheckCircle2, tone: "mint" },
  overdue: { Icon: AlertTriangle, tone: "danger" },
  pending: { Icon: CalendarClock, tone: "info" },
};

export function MemberHistory() {
  const t = useT();
  const { lang } = useLang();
  const paidTotal = memberPayments.filter((p) => p.status === "paid").reduce((s, p) => s + p.amount, 0);
  const owing = memberPayments.filter((p) => p.status !== "paid").reduce((s, p) => s + p.amount, 0);
  const groups: { key: string; title: string; items: typeof memberPayments }[] = [
    { key: "open", title: lang === "bm" ? "Belum selesai" : "Still to pay", items: memberPayments.filter((p) => p.status !== "paid") },
    { key: "paid", title: lang === "bm" ? "Sudah dibayar" : "Paid", items: memberPayments.filter((p) => p.status === "paid") },
  ];
  return (
    <MemberShell tab="payments" title={t("history")}>
      <div className="grid grid-cols-2 gap-2">
        <div className="rounded-[20px] bg-accent p-4">
          <div className="text-sm font-medium text-accent-foreground">{t("paid")} 2026</div>
          <Money sen={paidTotal} className="figure font-display text-[26px] font-extrabold leading-tight" />
        </div>
        <div className="rounded-[20px] border bg-card p-4">
          <div className="text-sm font-medium text-muted-foreground">{t("pending")}</div>
          <Money sen={owing} className="figure font-display text-[26px] font-extrabold leading-tight" />
        </div>
      </div>
      {groups.map((g) => (
        <section key={g.key} aria-labelledby={`h-${g.key}`} className="flex flex-col gap-2">
          <h2 id={`h-${g.key}`} className="px-1 font-display text-xl font-bold tracking-tight">
            {g.title}
          </h2>
          <ul className="divide-y rounded-[20px] border bg-card">
            {g.items.map((p) => {
              const href = p.status === "paid" ? "#member-receipt" : "#member-pay";
              const b = bubble[p.status];
              return (
                <li key={p.id}>
                  <a href={href} className="flex min-h-[68px] items-center gap-3 px-4 py-3 hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring">
                    <IconBubble Icon={b.Icon} tone={b.tone} />
                    <div className="min-w-0 flex-1">
                      <div className="line-clamp-2 text-base font-semibold leading-snug">{lang === "bm" ? p.descriptionBm : p.description}</div>
                      <div className="text-sm text-muted-foreground">
                        {p.status === "paid" && p.paidDate ? (
                          <>
                            {fmtDate(p.paidDate, lang)} · <span className="font-mono text-[13px]">{p.receiptNo}</span>
                          </>
                        ) : (
                          `${t("due")} ${fmtDate(p.dueDate, lang)}`
                        )}
                      </div>
                    </div>
                    <div className="flex flex-col items-end gap-1">
                      <Money sen={p.amount} className="text-base font-bold" />
                      <PaymentStatusBadge status={p.status} label={t(p.status)} size="sm" />
                    </div>
                    <ChevronRight className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
                  </a>
                </li>
              );
            })}
          </ul>
        </section>
      ))}
    </MemberShell>
  );
}
