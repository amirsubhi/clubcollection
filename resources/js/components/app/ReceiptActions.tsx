import { useState } from "react";
import { Printer, Share2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { fmtDateTime, rm } from "@/lib/format";
import { fill, useLang, useT } from "@/lib/i18n";
import type { Sen } from "@/types/domain";

/**
 * Under a receipt: "Kongsi" (share) first, "Cetak" (print) second.
 * Share uses the phone's share sheet (WhatsApp, Telegram, email…) with the receipt's link and a one-line summary;
 * where there is no share sheet (most desktops) the link is copied, and if even that is blocked it is shown to copy by hand.
 */
export function ReceiptActions({ receiptNo, amount, paidAt, clubName, url }: { receiptNo: string; amount: Sen; paidAt: string; clubName: string; url?: string }) {
  const t = useT();
  const { lang } = useLang();
  const [note, setNote] = useState<{ kind: "copied" | "manual"; text: string; link: string } | null>(null);
  // Never the page's own query string (a gateway's return parameters): the server URL, else this path only.
  const link = url ?? (typeof window !== "undefined" ? window.location.origin + window.location.pathname : "");

  const share = async () => {
    const title = fill(t("shareReceiptTitle"), { no: receiptNo });
    const text = fill(t("shareReceiptText"), { no: receiptNo, amount: rm(amount), club: clubName, date: fmtDateTime(paidAt, lang) });
    if (typeof navigator !== "undefined" && typeof navigator.share === "function") {
      try {
        await navigator.share({ title, text, url: link });
        return;
      } catch (e) {
        // The member closed the share sheet: nothing to do.
        if (e instanceof DOMException && e.name === "AbortError") return;
      }
    }
    try {
      await navigator.clipboard.writeText(`${text}\n${link}`);
      setNote({ kind: "copied", text, link });
      window.setTimeout(() => setNote((n) => (n?.kind === "copied" ? null : n)), 5000);
    } catch {
      setNote({ kind: "manual", text, link });
    }
  };

  return (
    <div className="flex flex-col gap-2 print:hidden">
      <Button type="button" size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-lg" onClick={() => void share()} data-testid="share-receipt">
        <Share2 aria-hidden /> {t("shareReceipt")}
      </Button>
      <Button type="button" variant="outline" size="lg" className="h-auto min-h-12 w-full whitespace-normal rounded-full py-2" onClick={() => window.print()} data-testid="print-receipt">
        <Printer aria-hidden /> {t("print")}
      </Button>
      <div aria-live="polite" className="min-h-0">
        {note?.kind === "copied" && (
          <p className="rounded-2xl bg-accent px-4 py-3 text-center text-base font-medium text-accent-foreground" data-testid="receipt-link-copied">
            {t("receiptLinkCopied")}
          </p>
        )}
        {note?.kind === "manual" && (
          <p className="rounded-2xl bg-muted px-4 py-3 text-base">
            {t("receiptLinkManual")}
            <span className="mt-1 block select-all">
              {note.text}
              <span className="block break-all font-mono text-sm">{note.link}</span>
            </span>
          </p>
        )}
      </div>
    </div>
  );
}
