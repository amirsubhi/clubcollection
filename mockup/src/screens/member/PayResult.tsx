import { CheckCircle2, Clock, Phone, XCircle } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { latestReceipt } from "@/data/payments";
import { fmtDateTime } from "@/lib/format";
import { useT } from "@/i18n";
import { cn } from "@/lib/utils";

export type PayOutcome = "success" | "pending" | "failed";

const icon = {
  success: { Icon: CheckCircle2, cls: "bg-success-soft text-success" },
  pending: { Icon: Clock, cls: "bg-warning-soft text-warning" },
  failed: { Icon: XCircle, cls: "bg-danger-soft text-danger" },
};

export function PayResult({ outcome }: { outcome: PayOutcome }) {
  const t = useT();
  const { Icon, cls } = icon[outcome];
  const title = outcome === "success" ? t("successTitle") : outcome === "pending" ? t("pendingTitle") : t("failedTitle");
  const body = outcome === "success" ? t("successBody") : outcome === "pending" ? t("pendingBody") : t("failedBody");
  const r = latestReceipt;
  return (
    <MemberShell tab="payments" title={title} hideNav={outcome === "pending"}>
      <div className="flex flex-col items-center gap-3 pt-4 text-center" aria-live="polite">
        <div className={cn("flex h-16 w-16 items-center justify-center rounded-full", cls)}>
          <Icon className="h-9 w-9" aria-hidden />
        </div>
        <h1 className="font-display text-2xl font-semibold">{title}</h1>
        <p className="text-sm text-muted-foreground">{body}</p>
        <Money sen={r.amount} className="font-display text-3xl font-semibold" />
      </div>

      {outcome === "pending" && (
        <Alert variant="warning">
          <Clock aria-hidden />
          <AlertDescription className="text-foreground">
            <p className="font-semibold">{t("dontPayAgain")}</p>
            <p>{t("pendingHint")}</p>
          </AlertDescription>
        </Alert>
      )}
      {outcome === "failed" && (
        <Alert variant="danger">
          <XCircle aria-hidden />
          <AlertDescription className="text-foreground">
            <p className="font-semibold">{t("failedMoneyLeft")}</p>
          </AlertDescription>
        </Alert>
      )}

      <Card>
        <CardContent className="p-4 sm:p-4">
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            {outcome === "success" && (
              <>
                <dt className="text-muted-foreground">{t("receiptNo")}</dt>
                <dd className="text-right font-semibold money">{r.receiptNo}</dd>
              </>
            )}
            <dt className="text-muted-foreground">{t("paidOn")}</dt>
            <dd className="text-right">{fmtDateTime(r.paidAt)}</dd>
            <dt className="text-muted-foreground">{t("bank")}</dt>
            <dd className="text-right">FPX · {r.bank}</dd>
            <dt className="text-muted-foreground">{t("reference")}</dt>
            <dd className="text-right money">{r.gatewayRef}</dd>
          </dl>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        {outcome === "success" && (
          <>
            <Button asChild size="lg">
              <a href="#member-receipt">{t("viewReceipt")}</a>
            </Button>
            <Button asChild variant="outline" size="lg">
              <a href="#member-home">{t("backHome")}</a>
            </Button>
          </>
        )}
        {outcome === "pending" && (
          <>
            <Button size="lg" variant="outline" type="button">
              {t("checkAgain")}
            </Button>
            <Button asChild variant="ghost" size="lg">
              <a href="#member-home">{t("backHome")}</a>
            </Button>
          </>
        )}
        {outcome === "failed" && (
          <>
            <Button asChild size="lg">
              <a href="tel:+60167789012">
                <Phone aria-hidden /> {t("contactTreasurer")}
              </a>
            </Button>
            <Button asChild variant="outline" size="lg">
              <a href="#member-pay">{t("tryAgain")}</a>
            </Button>
          </>
        )}
      </div>
    </MemberShell>
  );
}
