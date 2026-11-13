import { AlertTriangle, CalendarClock, ChevronRight } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { FundSummaryCard } from "@/components/app/FundSummaryCard";
import { Money } from "@/components/app/Money";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { currentMember } from "@/data/club";
import { amountDue, dueNow, upcoming } from "@/data/payments";
import { ytd } from "@/data/report";
import { fmtDate } from "@/lib/format";
import { useLang, useT } from "@/i18n";

export function MemberHome() {
  const t = useT();
  const { lang } = useLang();
  return (
    <MemberShell tab="home">
      <p className="text-sm text-muted-foreground">
        {t("hello")}, <span className="font-medium text-foreground">{currentMember.name.split(" ").slice(0, 2).join(" ")}</span>
      </p>

      <Card className="border-primary/30">
        <CardContent className="flex flex-col gap-4 p-5 sm:p-5">
          <div>
            <div className="text-sm text-muted-foreground">{t("amountDue")}</div>
            <Money sen={amountDue} className="font-display text-4xl font-semibold tracking-tight" />
            <div className="mt-1 text-sm text-muted-foreground">
              {t("includes")}: {t("forMonths")}
            </div>
          </div>
          <Button asChild size="lg" className="w-full">
            <a href="#member-invoice">
              {t("payNow")} <Money sen={amountDue} />
            </a>
          </Button>
        </CardContent>
      </Card>

      <Alert variant="danger">
        <AlertTriangle aria-hidden />
        <div>
          <AlertTitle>{t("overdueTitle")}</AlertTitle>
          <AlertDescription>{t("overdueBody")}</AlertDescription>
        </div>
      </Alert>

      <Card>
        <CardHeader className="flex-row items-center justify-between pb-2">
          <CardTitle>{t("upcoming")}</CardTitle>
          <a href="#member-history" className="inline-flex items-center text-sm font-medium text-primary hover:underline">
            {t("viewAll")} <ChevronRight className="h-4 w-4" aria-hidden />
          </a>
        </CardHeader>
        <CardContent>
          <ul className="divide-y">
            {[...dueNow, ...upcoming].sort((a, b) => a.dueDate.localeCompare(b.dueDate)).map((p) => (
              <li key={p.id} className="flex items-center gap-3 py-2.5">
                <CalendarClock className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium">{lang === "bm" ? p.descriptionBm : p.description}</div>
                  <div className="text-xs text-muted-foreground">
                    {t("due")} {fmtDate(p.dueDate, lang)}
                  </div>
                </div>
                <div className="flex flex-col items-end gap-0.5">
                  <Money sen={p.amount} className="text-sm font-semibold" />
                  {p.status === "overdue" && <Badge variant="danger">{t("overdueBadge")}</Badge>}
                </div>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <FundSummaryCard
        compact
        collected={ytd.collected}
        spent={ytd.spent}
        welfarePaid={ytd.welfarePaid}
        welfareCount={ytd.welfareCount}
        bankCharges={ytd.bankCharges}
        categories={ytd.spentByCategory}
        footer={
          <a href="#member-fund" className="inline-flex items-center text-sm font-medium text-primary hover:underline">
            {t("viewAll")} <ChevronRight className="h-4 w-4" aria-hidden />
          </a>
        }
      />
    </MemberShell>
  );
}
