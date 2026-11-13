import { HandHeart } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Money } from "./Money";
import type { Line } from "@/data/report";
import type { Sen } from "@/data/types";
import { useLang, useT } from "@/i18n";

export interface FundSummaryCardProps {
  collected: Sen;
  spent: Sen;
  welfarePaid: Sen;
  welfareCount: number;
  bankCharges: Sen;
  categories: Line[];
  compact?: boolean;
  footer?: React.ReactNode;
}

/** Member transparency card: aggregates only, no documents or names. */
export function FundSummaryCard({ collected, spent, welfarePaid, welfareCount, bankCharges, categories, compact, footer }: FundSummaryCardProps) {
  const t = useT();
  const { lang } = useLang();
  const totalOut = spent + welfarePaid + bankCharges;
  const max = Math.max(...categories.map((c) => c.amount), welfarePaid);
  const shown = compact ? [...categories].sort((a, b) => b.amount - a.amount).slice(0, 4) : categories;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("moneyGoes")}</CardTitle>
        <CardDescription>{t("moneyGoesSub")}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-2">
          <div className="rounded-md bg-muted p-3">
            <div className="text-xs text-muted-foreground">{t("collected")}</div>
            <Money sen={collected} className="font-display text-lg font-semibold" />
          </div>
          <div className="rounded-md bg-muted p-3">
            <div className="text-xs text-muted-foreground">{t("spent")}</div>
            <Money sen={totalOut} className="font-display text-lg font-semibold" />
          </div>
        </div>
        <div className="h-2.5 w-full overflow-hidden rounded-full bg-secondary" aria-hidden>
          <div className="h-full rounded-full bg-primary" style={{ width: `${Math.round((totalOut / collected) * 100)}%` }} />
        </div>
        <ul className="flex flex-col gap-2.5" aria-label={t("spentBy")}>
          {shown.map((c) => (
            <li key={c.label}>
              <div className="flex items-baseline justify-between gap-2 text-sm">
                <span className="min-w-0 truncate">{lang === "bm" && c.bm ? c.bm : c.label}</span>
                <Money sen={c.amount} className="text-muted-foreground" />
              </div>
              <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-secondary" aria-hidden>
                <div className="h-full rounded-full bg-primary/70" style={{ width: `${(c.amount / max) * 100}%` }} />
              </div>
            </li>
          ))}
        </ul>
        <div className="flex items-center gap-3 rounded-md border border-info/20 bg-info-soft p-3">
          <HandHeart className="h-5 w-5 shrink-0 text-info" aria-hidden />
          <div className="min-w-0 flex-1 text-sm">
            <span className="font-semibold">{welfareCount}</span> {t("welfareCases")}
          </div>
          <Money sen={welfarePaid} className="text-sm font-semibold" />
        </div>
        {!compact && (
          <div className="flex justify-between text-sm text-muted-foreground">
            <span>{t("bankCharges")}</span>
            <Money sen={bankCharges} />
          </div>
        )}
        <p className="text-xs text-muted-foreground">{t("aggregatesOnly")}</p>
        {footer}
      </CardContent>
    </Card>
  );
}
