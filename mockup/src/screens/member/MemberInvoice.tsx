import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { club, currentMember } from "@/data/club";
import { amountDue, dueNow } from "@/data/payments";
import { fmtDate } from "@/lib/format";
import { useLang, useT } from "@/i18n";

export function MemberInvoice() {
  const t = useT();
  const { lang } = useLang();
  return (
    <MemberShell tab="payments" title={t("invoice")} backHash="member-home">
      <Card>
        <CardContent className="flex flex-col gap-4 p-5 sm:p-5">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="text-xs text-muted-foreground">{club.name}</div>
              <div className="font-display text-lg font-semibold">INV-2026-001411</div>
            </div>
            <Badge variant="danger">{t("overdue")}</Badge>
          </div>
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <div>
              <dt className="text-xs text-muted-foreground">{t("billedTo")}</dt>
              <dd className="font-medium">{currentMember.name}</dd>
              <dd className="text-xs text-muted-foreground">{currentMember.memberNo}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">{t("due")}</dt>
              <dd className="font-medium">{fmtDate("2026-10-31", lang)}</dd>
            </div>
          </dl>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs text-muted-foreground">
                <th className="py-2 font-medium">{t("item")}</th>
                <th className="py-2 text-right font-medium">{t("amount")}</th>
              </tr>
            </thead>
            <tbody>
              {dueNow.map((p) => (
                <tr key={p.id} className="border-b">
                  <td className="py-2.5 pr-2">{lang === "bm" ? p.descriptionBm : p.description}</td>
                  <td className="py-2.5 text-right">
                    <Money sen={p.amount} />
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td className="pt-3 font-semibold">{t("total")}</td>
                <td className="pt-3 text-right">
                  <Money sen={amountDue} className="font-display text-lg font-semibold" />
                </td>
              </tr>
            </tfoot>
          </table>
        </CardContent>
      </Card>
      <Button asChild size="lg" className="w-full">
        <a href="#member-pay">
          {t("reviewPay")} <Money sen={amountDue} />
        </a>
      </Button>
    </MemberShell>
  );
}
