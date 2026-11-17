import { PiggyBank } from "lucide-react";
import { Head } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { toSlices } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { CategoryBar } from "@/components/app/CategoryBar";
import { fmtDate, rm } from "@/lib/format";
import { fill, useLang, useT } from "@/lib/i18n";
import type { MemberFund } from "@/types/member";

interface Props {
  club: { id: number; name: string; paymentsUrl: string };
  fund: MemberFund;
  dashboardUrl: string;
}

/** "Ke mana wang anda pergi": the club's fund this year so far (club-wide totals and categories only). */
export default function Fund({ club, fund, dashboardUrl }: Props) {
  const t = useT();
  const { lang } = useLang();
  const slices = toSlices(fund.categories, { bm: "Lain-lain", en: "Others" });
  return (
    <MemberShell tab="home" navHrefs={{ home: dashboardUrl, payments: club.paymentsUrl }} clubName={club.name} title={t("moneyGoes")} backHref={dashboardUrl}>
      <Head title={t("moneyGoes")} />
      <section aria-label={t("moneyGoes")} className="flex flex-col gap-3 rounded-[20px] border bg-card p-4" data-testid="fund-card">
        <p className="text-sm text-muted-foreground">
          {fill(t("moneyGoesWindow"), { from: fmtDate(fund.window.from, lang), to: fmtDate(fund.window.to, lang) })}
        </p>
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,9rem),1fr))] gap-2">
          <div className="rounded-2xl bg-accent px-3 py-2.5">
            <div className="text-sm text-accent-foreground">{t("collected")}</div>
            <Money sen={fund.collected} className="font-display text-lg font-bold" />
          </div>
          <div className="rounded-2xl bg-muted px-3 py-2.5">
            <div className="text-sm text-muted-foreground">{t("spent")}</div>
            <Money sen={fund.spent} className="font-display text-lg font-bold" />
          </div>
          <div className="col-span-full flex flex-wrap items-center justify-between gap-x-3 rounded-2xl border px-3 py-2.5" data-testid="fund-balance">
            <div>
              <div className="text-sm text-muted-foreground">{t("fundBalance")}</div>
              <div className="text-xs text-muted-foreground">{fill(t("fundBalanceAsOf"), { d: fmtDate(fund.window.to, lang) })}</div>
            </div>
            <Money sen={fund.balance} className="font-display text-lg font-bold" />
          </div>
        </div>
        <p className="text-sm text-muted-foreground">{fill(t("spentSplit"), { a: rm(fund.expenses), b: rm(fund.welfare), c: rm(fund.charges) })}</p>
        {fund.fromReserves > 0 && (
          <p className="flex items-start gap-2 rounded-2xl bg-info-soft px-3 py-2.5 text-sm text-info-foreground" data-testid="from-reserves">
            <PiggyBank className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>{fill(t("fromReserves"), { x: rm(fund.fromReserves), b: rm(fund.balance) })}</span>
          </p>
        )}
        {slices.length > 0 ? (
          <CategoryBar slices={slices} lang={lang} interactive={false} />
        ) : (
          <p className="text-base text-muted-foreground">{t("noSpending")}</p>
        )}
        <p className="text-sm text-muted-foreground">{t("aggregatesOnly")}</p>
      </section>
    </MemberShell>
  );
}
