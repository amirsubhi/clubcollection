import { Info } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { FundSummaryCard } from "@/components/app/FundSummaryCard";
import { Money } from "@/components/app/Money";
import { Card, CardContent } from "@/components/ui/card";
import { ytd } from "@/data/report";
import { useT } from "@/i18n";

export function MemberFund() {
  const t = useT();
  return (
    <MemberShell tab="fund" title={t("fundTitle")}>
      <p className="text-sm text-muted-foreground">{t("fundSub")}</p>
      <Card>
        <CardContent className="flex items-center justify-between gap-3 p-4 sm:p-4">
          <div>
            <div className="text-xs text-muted-foreground">{t("balance")}</div>
            <Money sen={ytd.balance} className="font-display text-2xl font-semibold" />
          </div>
        </CardContent>
      </Card>
      <FundSummaryCard
        collected={ytd.collected}
        spent={ytd.spent}
        welfarePaid={ytd.welfarePaid}
        welfareCount={ytd.welfareCount}
        bankCharges={ytd.bankCharges}
        categories={ytd.spentByCategory}
      />
      <p className="flex gap-2 text-xs text-muted-foreground">
        <Info className="h-4 w-4 shrink-0" aria-hidden /> {t("fullReport")}
      </p>
    </MemberShell>
  );
}
