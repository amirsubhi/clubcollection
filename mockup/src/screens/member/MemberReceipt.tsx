import { Download, MessageCircle } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { ReceiptCard } from "@/components/app/ReceiptCard";
import { Button } from "@/components/ui/button";
import { useT } from "@/i18n";

export function MemberReceipt() {
  const t = useT();
  return (
    <MemberShell tab="payments" title={t("receipt")} backHash="member-history">
      <ReceiptCard />
      <div className="grid grid-cols-1 gap-2 min-[400px]:grid-cols-2">
        <Button variant="outline" size="lg" type="button" className="h-12">
          <MessageCircle aria-hidden /> {t("shareWa")}
        </Button>
        <Button variant="outline" size="lg" type="button" className="h-12">
          <Download aria-hidden /> {t("download")}
        </Button>
      </div>
    </MemberShell>
  );
}
