import { useState } from "react";
import { Building2, Lock } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { fpxBanks } from "@/data/club";
import { amountDue, dueNow } from "@/data/payments";
import { useLang, useT } from "@/i18n";
import { rm } from "@/lib/format";

export function MemberPay() {
  const t = useT();
  const { lang } = useLang();
  const [bank, setBank] = useState("Maybank2u");
  return (
    <MemberShell tab="payments" title={`${t("reviewPay")} ${rm(amountDue)}`} backHash="member-invoice">
      <Card>
        <CardContent className="flex flex-col gap-3 p-5 sm:p-5">
          <div className="text-sm text-muted-foreground">{t("total")}</div>
          <Money sen={amountDue} className="font-display text-4xl font-semibold" />
          <ul className="flex flex-col gap-1 text-sm">
            {dueNow.map((p) => (
              <li key={p.id} className="flex justify-between gap-2">
                <span className="min-w-0 truncate text-muted-foreground">{lang === "bm" ? p.descriptionBm : p.description}</span>
                <Money sen={p.amount} />
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col gap-4 p-5 sm:p-5">
          <div>
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{t("payWith")}</div>
            <div className="mt-2 flex items-center gap-3 rounded-md border border-primary/40 bg-accent p-3">
              <Building2 className="h-6 w-6 shrink-0 text-primary" aria-hidden />
              <div className="min-w-0">
                <div className="font-semibold">{t("fpxLine")}</div>
                <div className="text-sm text-muted-foreground">{t("viaGateway")}</div>
              </div>
            </div>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="bank">{t("chooseBank")}</Label>
            <Select value={bank} onValueChange={setBank}>
              <SelectTrigger id="bank">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {fpxBanks.map((b) => (
                  <SelectItem key={b} value={b}>
                    {b}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <p className="text-sm text-muted-foreground">{t("handoff")}</p>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        <Button asChild size="lg" className="w-full">
          <a href="#pay-success">
            <Lock aria-hidden /> {t("continueTo")}
          </a>
        </Button>
        <p className="text-center text-xs text-muted-foreground">{t("noFees")}</p>
        <p className="text-center text-xs text-violet-800">
          Mockup: see the other results →{" "}
          <a className="underline" href="#pay-pending">
            pending
          </a>{" "}
          ·{" "}
          <a className="underline" href="#pay-failed">
            failed
          </a>
        </p>
      </div>
    </MemberShell>
  );
}
