import { Download, Share2 } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { club, currentMember } from "@/data/club";
import { latestReceipt } from "@/data/payments";
import { fmtDateTime } from "@/lib/format";
import { useLang, useT } from "@/i18n";

export function MemberReceipt() {
  const t = useT();
  const { lang } = useLang();
  const r = latestReceipt;
  return (
    <MemberShell tab="payments" title={t("receipt")} backHash="member-history">
      <Card>
        <CardContent className="flex flex-col gap-4 p-5 sm:p-5">
          <div className="flex items-start justify-between gap-3 border-b pb-4">
            <div className="min-w-0">
              <div className="font-display font-semibold">{club.name}</div>
              <div className="text-xs text-muted-foreground">No. pendaftaran {club.regNo}</div>
            </div>
            <Badge variant="success">{t("paid")}</Badge>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-muted-foreground">{t("receipt")}</div>
            <div className="money font-display text-xl font-semibold">{r.receiptNo}</div>
          </div>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted-foreground">{t("receivedFrom")}</dt>
            <dd className="text-right font-medium">
              {currentMember.name}
              <div className="text-xs font-normal text-muted-foreground">{currentMember.memberNo}</div>
            </dd>
            <dt className="text-muted-foreground">{t("paidOn")}</dt>
            <dd className="text-right">{fmtDateTime(r.paidAt)}</dd>
            <dt className="text-muted-foreground">{t("method")}</dt>
            <dd className="text-right">
              FPX · {r.bank} ({r.gateway})
            </dd>
            <dt className="text-muted-foreground">{t("reference")}</dt>
            <dd className="text-right money">{r.gatewayRef}</dd>
          </dl>
          <table className="w-full text-sm">
            <tbody>
              {r.items.map((p) => (
                <tr key={p.id} className="border-t">
                  <td className="py-2 pr-2">{lang === "bm" ? p.descriptionBm : p.description}</td>
                  <td className="py-2 text-right">
                    <Money sen={p.amount} />
                  </td>
                </tr>
              ))}
              <tr className="border-t-2 border-foreground/70">
                <td className="pt-2 font-semibold">{t("total")}</td>
                <td className="pt-2 text-right">
                  <Money sen={r.amount} className="font-display text-lg font-semibold" />
                </td>
              </tr>
            </tbody>
          </table>
          <p className="text-xs text-muted-foreground">{t("receiptFoot")}</p>
        </CardContent>
      </Card>
      <div className="grid grid-cols-2 gap-2">
        <Button variant="outline" type="button">
          <Share2 aria-hidden /> {t("share")}
        </Button>
        <Button variant="outline" type="button">
          <Download aria-hidden /> {t("download")}
        </Button>
      </div>
    </MemberShell>
  );
}
