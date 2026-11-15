import { HandHeart, Info } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { CategoryBar, CollectedSpentChart } from "@/components/app/Charts";
import { MoneyFlowDiagram } from "@/components/app/Diagrams";
import { IconBubble, NodePattern } from "@/components/app/Visuals";
import { ytd } from "@/data/report";
import { useLang, useT } from "@/i18n";

export function MemberFund() {
  const t = useT();
  const { lang } = useLang();
  const out = ytd.spent + ytd.welfarePaid + ytd.bankCharges;
  return (
    <MemberShell tab="fund" title={t("fundTitle")}>
      <section className="relative overflow-hidden rounded-[24px] bg-deep p-5 text-deep-foreground shadow-[0_18px_40px_-24px_rgb(var(--primary)/0.45)] ring-1 ring-primary/10">
        <NodePattern className="opacity-80" />
        <div className="relative">
          <h1 className="font-sans text-base font-semibold text-deep-foreground/80">{t("fundBalance")}</h1>
          <Money sen={ytd.balance} className="figure mt-1 block font-display text-[44px] font-extrabold leading-none tracking-tight text-deep-foreground" />
          <p className="mt-2 text-base text-deep-foreground/80">{t("fundSub")}</p>
        </div>
      </section>

      <section aria-labelledby="flow-h" className="flex flex-col gap-3 rounded-[20px] border bg-card p-4">
        <div>
          <h2 id="flow-h" className="font-display text-xl font-bold tracking-tight">
            {t("moneyGoes")}
          </h2>
          <p className="text-sm text-muted-foreground">{t("moneyGoesSub")}</p>
        </div>
        <MoneyFlowDiagram lang={lang} />
      </section>

      <section aria-labelledby="chart-h" className="flex flex-col gap-3 rounded-[20px] border bg-card p-4">
        <h2 id="chart-h" className="font-display text-xl font-bold tracking-tight">
          {t("monthlyTitle")}
        </h2>
        <div className="grid grid-cols-2 gap-2">
          <div className="rounded-2xl bg-accent px-3 py-2.5">
            <div className="text-sm text-accent-foreground">{t("collected")}</div>
            <Money sen={ytd.collected} className="font-display text-lg font-bold" />
          </div>
          <div className="rounded-2xl bg-muted px-3 py-2.5">
            <div className="text-sm text-muted-foreground">{t("spent")}</div>
            <Money sen={out} className="font-display text-lg font-bold" />
          </div>
        </div>
        <CollectedSpentChart lang={lang} height={220} />
      </section>

      <section aria-labelledby="split-h" className="flex flex-col gap-3 rounded-[20px] border bg-card p-4">
        <h2 id="split-h" className="font-display text-xl font-bold tracking-tight">
          {t("spendSplit")}
        </h2>
        <CategoryBar lang={lang} />
        <div className="flex min-h-14 items-center gap-3 rounded-2xl bg-info-soft p-3">
          <IconBubble Icon={HandHeart} tone="info" className="bg-card" />
          <div className="min-w-0 flex-1 text-base">
            <span className="font-bold">{ytd.welfareCount}</span> {t("welfareCases")}
          </div>
          <Money sen={ytd.welfarePaid} className="text-base font-bold" />
        </div>
        <p className="text-sm text-muted-foreground">{t("aggregatesOnly")}</p>
      </section>

      <p className="flex gap-2 px-1 text-sm text-muted-foreground">
        <Info className="h-5 w-5 shrink-0" aria-hidden /> {t("fullReport")}
      </p>
    </MemberShell>
  );
}
