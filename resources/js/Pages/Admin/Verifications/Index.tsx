import { forwardRef, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import {
  AlertTriangle,
  BadgeCheck,
  CheckCircle2,
  Clock,
  ExternalLink,
  FileText,
  Inbox,
  Keyboard,
  Landmark,
  Layers,
  Minimize2,
  Play,
  Search,
  XCircle,
  ZoomIn,
} from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { ApproveSlipDialog, RejectSlipDialog, type SlipSummary } from "@/components/app/SlipDialogs";
import { StatusChip } from "@/components/app/StatusBadges";
import { Avatar } from "@/components/app/Visuals";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input, Label } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { FilterButton, FilterGroup } from "@/components/ui/tabs";
import { fmtDate, fmtDateTime, fmtPeriod } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { AdminNavProps, FundAttachment } from "@/types/admin";

/** TransferVerificationController::card() plus the actions. */
export interface PendingSlip {
  id: number;
  invoiceNo: string;
  member: { name: string; email: string | null };
  periodStart: string;
  periodEnd: string;
  frequency: "monthly" | "quarterly" | "yearly";
  amount: number;
  dueDate: string;
  transferDate: string | null;
  transferRef: string | null;
  expectedRef: string;
  submittedAt: string | null;
  slips: FundAttachment[];
  /** "Bayar semua": one slip for several fees, approved or rejected as a set. */
  group: SlipGroup | null;
  blocker: string | null;
  urls: { approve: string; reject: string; payment: string };
}

/** TransferVerificationController::groupCard(). */
export interface SlipGroup {
  id: number;
  reference: string;
  totalSen: number;
  /** What approving marks paid: totalSen minus fees paid online while the slip was checked. */
  approveSen: number;
  paidOnlineSen: number;
  fees: {
    id: number;
    invoiceNo: string;
    periodStart: string;
    periodEnd: string;
    frequency: "monthly" | "quarterly" | "yearly";
    amount: number;
    status: string;
    url: string;
  }[];
}

/** TransferVerificationController::history(). */
export interface HandledSlip {
  id: number;
  paymentId: number;
  outcome: "approved" | "rejected" | "gateway_paid" | "gateway_paid_after_manual" | "group_refund" | "underpaid";
  /** gateway_paid_after_manual: how an admin had recorded it ("cash", "bank transfer", "other"). */
  recordedAs?: string | null;
  member: string;
  amount: number;
  periodStart: string | null;
  periodEnd: string | null;
  by: string;
  at: string;
  reason: string | null;
  reference: string | null;
  paidDate: string | null;
  url: string | null;
}

interface Props {
  nav: AdminNavProps;
  club: { name: string; transferReady: boolean };
  tab: "pending" | "handled";
  pending: PendingSlip[];
  handled: HandledSlip[];
  today: string;
  urls: { index: string; billing: string };
}

const freq = { monthly: "Monthly", quarterly: "Quarterly", yearly: "Yearly" };

const feeLabel = (s: { periodStart: string; periodEnd: string }) => `Membership fee ${fmtPeriod(s.periodStart, s.periodEnd, "en")}`;

function Fact({ label, children, mono = false }: { label: string; children: React.ReactNode; mono?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={mono ? "break-words font-mono text-sm" : "text-sm font-medium"}>{children}</dd>
    </div>
  );
}

const isPdf = (a: FundAttachment) => a.mime === "application/pdf" || a.name.toLowerCase().endsWith(".pdf");
const isImage = (a: FundAttachment) => !!a.mime?.startsWith("image/");

/**
 * The slip, large, inline. Images show from the unaudited thumbnail route (the same file the gallery
 * tile used), so a queue of 80 slips doesn't write 80 "viewed" audit rows on every load; "Open full size"
 * and "Open PDF" use the audited inline view. PDFs aren't framed: the page CSP has object-src 'none' and
 * the attachment stream is sandboxed, which browsers won't render in a frame.
 */
function SlipPreview({ slips, member }: { slips: FundAttachment[]; member: string }) {
  const [index, setIndex] = useState(0);
  const [zoom, setZoom] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  /** Set by the Zoom button: focus the zoomed box so the arrow keys scroll it. */
  const focusBox = useRef(false);
  useEffect(() => {
    if (zoom && focusBox.current) box.current?.focus();
    focusBox.current = false;
  }, [zoom]);
  const slip = slips[Math.min(index, slips.length - 1)];
  if (!slip) {
    return <p className="flex min-h-40 items-center justify-center rounded-md border border-dashed p-6 text-sm text-muted-foreground">No slip file on record.</p>;
  }
  const src = slip.thumbUrl ?? slip.url;
  return (
    <figure className="flex min-w-0 flex-col gap-2" data-testid="slip-preview">
      {isImage(slip) && src ? (
        <div
          ref={box}
          {...(zoom ? { tabIndex: 0, role: "region", "aria-label": "Zoomed slip, use arrow keys to scroll" } : {})}
          className={cn(
            "relative rounded-md border bg-muted outline-none focus-visible:ring-2 focus-visible:ring-ring [touch-action:pan-x_pan-y_pinch-zoom]",
            // Unzoomed the image fits inside (its max-height leaves room for the 1px border), and the box never scrolls.
            zoom ? "h-[70vh] max-h-[640px] cursor-zoom-out overflow-auto" : "flex max-h-[70vh] items-center justify-center overflow-hidden lg:max-h-[600px]",
          )}
        >
          <img
            src={src}
            alt={`Transfer slip from ${member}`}
            loading="lazy"
            onClick={() => setZoom((z) => !z)}
            className={zoom ? "max-w-none origin-top-left" : "max-h-[calc(70vh-2px)] w-auto max-w-full cursor-zoom-in object-contain lg:max-h-[598px]"}
            style={zoom ? { width: "200%" } : undefined}
          />
        </div>
      ) : (
        <div className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-md border bg-muted p-6 text-center">
          <FileText className="h-10 w-10 text-muted-foreground" strokeWidth={1.5} aria-hidden />
          <div>
            <p className="text-sm font-semibold">{isPdf(slip) ? "PDF slip" : "Slip file"}</p>
            <p className="break-all text-xs text-muted-foreground">
              {slip.name} · {slip.sizeKb} KB
            </p>
          </div>
          {slip.url && (
            <Button asChild>
              <a href={slip.url} target="_blank" rel="noopener">
                <ExternalLink aria-hidden /> Open {isPdf(slip) ? "PDF" : "file"}
                <span className="sr-only"> (opens in a new tab)</span>
              </a>
            </Button>
          )}
        </div>
      )}
      <figcaption className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
        {isImage(slip) && src && (
          <Button type="button" variant="outline" size="sm" aria-pressed={zoom} onClick={() => ((focusBox.current = !zoom), setZoom((z) => !z))}>
            {zoom ? <Minimize2 aria-hidden /> : <ZoomIn aria-hidden />} {zoom ? "Fit" : "Zoom"}
          </Button>
        )}
        {slips.length > 1 && (
          <span className="inline-flex items-center gap-1" role="group" aria-label="Slip files">
            {slips.map((x, i) => (
              <Button
                key={x.id}
                type="button"
                size="sm"
                variant={i === index ? "secondary" : "ghost"}
                aria-pressed={i === index}
                aria-label={`Slip ${i + 1} of ${slips.length}`}
                onClick={() => (setIndex(i), setZoom(false))}
              >
                {i + 1}
              </Button>
            ))}
          </span>
        )}
        <span className="min-w-0 flex-1 truncate">{slip.name}</span>
        {isImage(slip) && slip.url && (
          <a href={slip.url} target="_blank" rel="noopener" className="inline-flex min-h-9 items-center gap-1 font-semibold text-primary hover:underline">
            Open full size <ExternalLink className="h-3.5 w-3.5" aria-hidden />
            <span className="sr-only"> (opens in a new tab)</span>
          </a>
        )}
      </figcaption>
    </figure>
  );
}

interface CardProps {
  s: PendingSlip;
  today: string;
  active: boolean;
  position: string;
  /** Confirm-button suffix: "and next" when a later slip follows, "and continue" when the page moves back to an earlier one. */
  after?: "next" | "continue";
  onActivate: () => void;
  /** Called as approve / reject is sent: where to move once the card has gone. */
  onDone: (s: PendingSlip) => void;
  /** The visit failed, was cancelled or was refused: forget the planned move. */
  onFailed: (refused: boolean) => void;
}

const SlipCard = forwardRef<HTMLLIElement, CardProps>(function SlipCard({ s, today, active, position, after, onActivate, onDone, onFailed }, ref) {
  const post = (url: string, data: Record<string, string>) => {
    // Before the visit: the new props arrive (and the card leaves the list) before any onSuccess runs.
    onDone(s);
    let answered = false; // a page came back (success or validation errors)
    router.post(url, data, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        answered = true;
      },
      onError: () => {
        answered = true;
        onFailed(true);
      },
      // Cancelled, interrupted or no page at all (network / server error): nothing changed, drop the planned move.
      onFinish: () => {
        if (!answered) onFailed(false);
      },
    });
  };
  const g = s.group;
  const amount = g ? g.totalSen : s.amount;
  // Fees paid online meanwhile are left out of the approval (their share is flagged for a refund).
  const approveAmount = g ? g.approveSen : s.amount;
  const label = g ? `${g.fees.length} fees: ${fmtPeriod(g.fees[0].periodStart, g.fees[g.fees.length - 1].periodEnd, "en")}` : feeLabel(s);
  const summary: SlipSummary = {
    id: s.id,
    member: s.member,
    amount: approveAmount,
    feeLabel: label,
    transferDate: s.transferDate,
    transferRef: s.transferRef,
    expectedRef: s.expectedRef,
  };
  const refMatches = !!s.transferRef && s.transferRef.toUpperCase().includes(s.expectedRef);
  const headingId = `slip-${s.id}-name`;
  return (
    <li
      ref={ref}
      tabIndex={-1}
      aria-labelledby={headingId}
      aria-current={active || undefined}
      onFocus={onActivate}
      onPointerDown={onActivate}
      className={cn(
        "grid min-w-0 scroll-mt-20 grid-cols-[minmax(0,1fr)] gap-4 rounded-lg border bg-card p-4 outline-none sm:p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:grid-rows-[auto_1fr] lg:gap-x-6",
        active && "ring-2 ring-primary/70 ring-offset-2 ring-offset-background",
      )}
      data-testid="slip-card"
      data-active={active || undefined}
    >
      {/* Mobile order: who and how much, the slip, then the facts and the actions. Desktop: slip on the left. */}
      <div className="flex items-start gap-3 lg:col-start-2 lg:row-start-1">
        <Avatar name={s.member.name} size={44} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <h2 id={headingId} className="truncate font-display text-lg font-bold tracking-tight">
              {s.member.name}
            </h2>
            <Money sen={amount} className="font-display text-2xl font-bold" />
          </div>
          <div className="text-sm text-muted-foreground">
            {g ? (
              <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                <StatusChip Icon={Layers} tone="info">
                  Pay all · {g.fees.length} fees
                </StatusChip>
                <span>One slip for the whole set: approve or reject them together.</span>
              </span>
            ) : (
              <span className="block break-words">
                {feeLabel(s)} · {freq[s.frequency]} · {s.invoiceNo}
              </span>
            )}
          </div>
          <p className="text-xs text-muted-foreground">{position}</p>
        </div>
      </div>

      <div className="min-w-0 lg:col-start-1 lg:row-span-2 lg:row-start-1">
        <SlipPreview slips={s.slips} member={s.member.name} />
      </div>

      <div className="flex min-w-0 flex-col gap-4 lg:col-start-2 lg:row-start-2">
        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-md bg-muted/60 p-3">
          <Fact label="Transfer date">{s.transferDate ? fmtDate(s.transferDate) : "—"}</Fact>
          <Fact label="Slip sent">{s.submittedAt ? fmtDateTime(s.submittedAt) : "—"}</Fact>
          <Fact label="Reference to look for" mono>
            {s.expectedRef}
          </Fact>
          <Fact label="Member's bank ref" mono>
            {s.transferRef ?? <span className="font-sans text-muted-foreground">Not given</span>}
          </Fact>
        </dl>

        {g && (
          <div className="overflow-hidden rounded-md border" data-testid="slip-group">
            <table className="w-full text-sm">
              <caption className="sr-only">Fees covered by this slip</caption>
              <thead className="bg-muted/60 text-left text-xs text-muted-foreground">
                <tr>
                  <th scope="col" className="px-3 py-2 font-medium">Fee</th>
                  <th scope="col" className="hidden px-3 py-2 font-medium sm:table-cell">Invoice</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {g.fees.map((f) => (
                  <tr key={f.id}>
                    <td className="px-3 py-2">
                      <a href={f.url} className="hover:underline">
                        {feeLabel(f)}
                      </a>
                      <span className="block text-xs text-muted-foreground">
                        {freq[f.frequency]}
                        <span className="sm:hidden"> · {f.invoiceNo}</span>
                        {f.status !== "pending_verification" && <span className="font-semibold text-danger-foreground"> · now {f.status.replace("_", " ")}</span>}
                      </span>
                    </td>
                    <td className="hidden px-3 py-2 font-mono text-xs sm:table-cell">{f.invoiceNo}</td>
                    <td className="px-3 py-2 text-right">
                      <Money sen={f.amount} />
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="border-t bg-muted/40">
                <tr>
                  <th scope="row" colSpan={2} className="hidden px-3 py-2 text-left font-semibold sm:table-cell">
                    Total
                  </th>
                  <th scope="row" className="px-3 py-2 text-left font-semibold sm:hidden">
                    Total
                  </th>
                  <td className="px-3 py-2 text-right font-bold">
                    <Money sen={g.totalSen} />
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}

        {g && g.paidOnlineSen > 0 && (
          <Alert variant="warning" data-testid="slip-group-paid-online">
            <AlertTriangle aria-hidden />
            <AlertTitle>Part of this set was paid online while the slip was being checked</AlertTitle>
            <AlertDescription>
              Approving marks the remaining fees paid (<Money sen={g.approveSen} />). If the transfer of <Money sen={g.totalSen} /> arrived, refund{" "}
              <Money sen={g.paidOnlineSen} /> to the member: it is flagged in the audit log.
            </AlertDescription>
          </Alert>
        )}

        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <ApproveSlipDialog
              slip={summary}
              today={today}
              after={after}
              triggerProps={{ disabled: !!s.blocker }}
              onConfirm={(paidDate, reference) =>
                post(s.urls.approve, {
                  paid_date: paidDate,
                  reference,
                })
              }
            />
            <RejectSlipDialog slip={summary} after={after} triggerProps={{ disabled: !!s.blocker }} onConfirm={(reason) => post(s.urls.reject, { reason })} />
            <a href={s.urls.payment} className="ml-auto inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-primary hover:underline">
              Payment record <ExternalLink className="h-3.5 w-3.5" aria-hidden />
            </a>
          </div>
          {s.blocker ? (
            <p className="text-xs text-muted-foreground">{s.blocker}</p>
          ) : (
            <p className="text-xs text-muted-foreground">
              Look for {s.expectedRef}
              {s.transferRef && !refMatches ? ` or ${s.transferRef}` : ""} in the club's bank statement before approving.
            </p>
          )}
        </div>
      </div>
    </li>
  );
});

type SortKey = "sent" | "transfer-asc" | "transfer-desc" | "amount-desc" | "amount-asc";

const sortOptions: { value: SortKey; label: string }[] = [
  { value: "sent", label: "Slip sent, oldest first" },
  { value: "transfer-asc", label: "Transfer date, oldest first" },
  { value: "transfer-desc", label: "Transfer date, newest first" },
  { value: "amount-desc", label: "Amount, highest first" },
  { value: "amount-asc", label: "Amount, lowest first" },
];

const isSortKey = (v: string | null): v is SortKey => sortOptions.some((o) => o.value === v);
const norm = (v: string) => v.toLowerCase().replace(/[\s-]+/g, "");
const slipAmount = (p: PendingSlip) => (p.group ? p.group.totalSen : p.amount);

/** Member name or email, the YURAN-/YURAN-G reference, the member's bank ref or an invoice number. */
function matches(p: PendingSlip, q: string): boolean {
  if (!q) return true;
  const hay = [
    p.member.name,
    p.member.email ?? "",
    p.expectedRef,
    p.transferRef ?? "",
    p.invoiceNo,
    p.group?.reference ?? "",
    ...(p.group?.fees.map((f) => f.invoiceNo) ?? []),
  ];
  return hay.some((h) => norm(h).includes(q));
}

function sortSlips(list: PendingSlip[], sort: SortKey): PendingSlip[] {
  if (sort === "sent") return list; // the server's order
  const out = [...list];
  if (sort === "amount-desc") return out.sort((a, b) => slipAmount(b) - slipAmount(a));
  if (sort === "amount-asc") return out.sort((a, b) => slipAmount(a) - slipAmount(b));
  const dir = sort === "transfer-asc" ? 1 : -1;
  // Slips without a transfer date go last either way.
  return out.sort((a, b) => (!a.transferDate ? 1 : !b.transferDate ? -1 : a.transferDate.localeCompare(b.transferDate) * dir));
}

/**
 * Shortcuts only reach this from inside the slips list (WCAG 2.1.4); they are also off while typing or
 * composing (IME), inside a dialog or a select, with a modifier held, and for a held-down A / R.
 */
function shortcutsBlocked(e: KeyboardEvent): boolean {
  if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented || e.isComposing) return true;
  if (e.repeat && (e.key.toLowerCase() === "a" || e.key.toLowerCase() === "r")) return true;
  const t = e.target as HTMLElement | null;
  if (t?.closest("input, textarea, select, [contenteditable=''], [contenteditable='true'], [role=combobox], [role=listbox], [role=menu]")) return true;
  return !!document.querySelector("[role=dialog], [role=alertdialog]");
}

const SHORTCUTS_KEY = "verifications.shortcuts";

function Kbd({ children }: { children: React.ReactNode }) {
  return <kbd className="rounded border bg-muted px-1.5 py-0.5 font-mono text-xs font-semibold text-foreground">{children}</kbd>;
}

function WaitingQueue({ pending, today }: { pending: PendingSlip[]; today: string }) {
  const initial = useMemo(() => new URLSearchParams(typeof window === "undefined" ? "" : window.location.search), []);
  const [query, setQuery] = useState(initial.get("q") ?? "");
  const [sort, setSort] = useState<SortKey>(() => {
    const v = initial.get("sort");
    return isSortKey(v) ? v : "sent";
  });
  const [activeId, setActiveId] = useState<number | null>(null);
  const [announce, setAnnounce] = useState("");
  const [shortcuts, setShortcuts] = useState(() => {
    try {
      return window.localStorage.getItem(SHORTCUTS_KEY) !== "off";
    } catch {
      return true;
    }
  });
  const toggleShortcuts = () => {
    const on = !shortcuts;
    setShortcuts(on);
    setAnnounce(on ? "Keyboard shortcuts on." : "Keyboard shortcuts off.");
    try {
      window.localStorage.setItem(SHORTCUTS_KEY, on ? "on" : "off");
    } catch {
      /* private mode: the choice just isn't remembered */
    }
  };
  const cards = useRef(new Map<number, HTMLLIElement>());
  /** Set by approve / reject: the card handled, its place, and the one to move to once it has left the list. */
  const focusNext = useRef<{
    done: number;
    index: number;
    next: number | null;
  } | null>(null);

  const list = useMemo(
    () =>
      sortSlips(
        pending.filter((p) => matches(p, norm(query.trim()))),
        sort,
      ),
    [pending, query, sort],
  );
  const total = list.reduce((sum, p) => sum + slipAmount(p), 0);
  const activeIndex = list.findIndex((p) => p.id === activeId);

  // Keep ?q= and ?sort= in the address bar (also after an approve, whose redirect drops them), so a reload keeps them.
  useEffect(() => {
    const url = new URL(window.location.href);
    query.trim() ? url.searchParams.set("q", query.trim()) : url.searchParams.delete("q");
    sort !== "sent" ? url.searchParams.set("sort", sort) : url.searchParams.delete("sort");
    if (url.href !== window.location.href) window.history.replaceState(window.history.state, "", url.href);
  }, [query, sort, pending]);

  const go = useCallback(
    (index: number, say?: string) => {
      const p = list[index];
      if (!p) return;
      setActiveId(p.id);
      const el = cards.current.get(p.id);
      el?.focus({ preventScroll: true });
      el?.scrollIntoView({
        block: "start",
        behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
      });
      setAnnounce(`${say ? `${say} ` : ""}Slip ${index + 1} of ${list.length}: ${p.member.name}.`);
    },
    [list],
  );

  // A new search or sort drops a planned move (declared first, so it runs before the effect below).
  useEffect(() => {
    focusNext.current = null;
  }, [query, sort]);

  const onFailed = useCallback((refused: boolean) => {
    focusNext.current = null;
    setAnnounce(refused ? "The slip is still waiting. See the message at the top of the page." : "Not sent. The slip is still waiting.");
  }, []);

  // After approve / reject the card leaves the list: move to the one that followed it.
  useEffect(() => {
    if (focusNext.current === null) return;
    const { done, index, next } = focusNext.current;
    focusNext.current = null;
    // Still waiting (the server refused it): stay on it, the banner at the top says why.
    if (list.some((p) => p.id === done)) {
      setAnnounce("The slip is still waiting. See the message at the top of the page.");
      return;
    }
    if (list.length === 0) return setAnnounce("No slips left to check.");
    // The planned next card may have gone too (handled by someone else meanwhile): take the one now in its place.
    const i = list.findIndex((p) => p.id === next);
    go(i >= 0 ? i : Math.min(index, list.length - 1), "Moved to the next slip.");
  }, [list, go]);

  const onDone = (s: PendingSlip) => {
    const i = list.findIndex((p) => p.id === s.id);
    const next = list[i + 1] ?? list[i - 1];
    focusNext.current = {
      done: s.id,
      index: Math.max(0, i),
      next: next ? next.id : null,
    };
  };

  const listRef = useRef<HTMLUListElement>(null);
  // On the list itself, not the document: the keys do nothing unless focus is on a slip card (WCAG 2.1.4).
  // React bubbles keys out of the (portalled) dialogs too, so the DOM check keeps those out.
  const onListKey = (ev: React.KeyboardEvent<HTMLUListElement>) => {
    const e = ev.nativeEvent;
    if (!shortcuts || !listRef.current?.contains(e.target as Node) || shortcutsBlocked(e)) return;
    const key = e.key.toLowerCase();
    if (key === "j" || key === "k") {
      e.preventDefault();
      const from = activeIndex < 0 ? (key === "j" ? -1 : list.length) : activeIndex;
      go(Math.max(0, Math.min(list.length - 1, from + (key === "j" ? 1 : -1))));
    } else if (key === "a" || key === "r") {
      const p = activeIndex >= 0 ? list[activeIndex] : null;
      if (!p) {
        e.preventDefault();
        setAnnounce("Press J to pick a slip first.");
        return;
      }
      const btn = cards.current.get(p.id)?.querySelector<HTMLButtonElement>(`[data-slip-action=${key === "a" ? "approve" : "reject"}]`);
      if (!btn) return;
      e.preventDefault();
      if (btn.disabled) {
        setAnnounce(p.blocker ?? "This slip can't be handled here.");
        return;
      }
      btn.click();
    }
  };

  return (
    <>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <Label htmlFor="slip-search">Search slips</Label>
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input
              id="slip-search"
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Member, YURAN- reference or bank ref"
              autoComplete="off"
              className="pl-9"
            />
          </div>
        </div>
        <div className="flex flex-col gap-1.5 sm:w-64">
          <Label htmlFor="slip-sort">Sort</Label>
          <Select value={sort} onValueChange={(v) => setSort(v as SortKey)}>
            <SelectTrigger id="slip-sort">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {sortOptions.map((o) => (
                <SelectItem key={o.value} value={o.value}>
                  {o.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p className="text-sm text-muted-foreground">
          {list.length === pending.length ? `${list.length} slip${list.length === 1 ? "" : "s"}` : `${list.length} of ${pending.length} slips`} · <Money sen={total} /> in
          total · {sortOptions.find((o) => o.value === sort)?.label.toLowerCase()}
        </p>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground" data-testid="shortcut-hint">
          <Button type="button" size="sm" variant="outline" className="hidden md:inline-flex" onClick={() => go(activeIndex >= 0 ? activeIndex : 0)} disabled={list.length === 0}>
            <Play aria-hidden /> Start reviewing
          </Button>
          <span id="slip-shortcuts-hint" className={cn("hidden flex-wrap items-center gap-1.5 md:inline-flex", !shortcuts && "opacity-60")}>
            <Keyboard className="h-4 w-4" aria-hidden />
            <span className="font-semibold">On a slip:</span> <Kbd>J</Kbd>/<Kbd>K</Kbd> next/previous · <Kbd>A</Kbd> approve · <Kbd>R</Kbd> reject
          </span>
          <Button type="button" size="sm" variant="ghost" role="switch" aria-checked={shortcuts} aria-describedby="slip-shortcuts-hint" onClick={toggleShortcuts}>
            Keyboard shortcuts {shortcuts ? "on" : "off"}
          </Button>
        </div>
      </div>
      <div className="sr-only" role="status" aria-live="polite" aria-atomic="true">
        {announce}
      </div>

      {list.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-lg border bg-card p-10 text-center">
          <Search className="h-8 w-8 text-muted-foreground" strokeWidth={1.5} aria-hidden />
          <p className="font-semibold">No slips match “{query.trim()}”</p>
          <Button variant="outline" size="sm" onClick={() => setQuery("")}>
            Clear search
          </Button>
        </div>
      ) : (
        <ul ref={listRef} onKeyDown={onListKey} className="flex flex-col gap-4" aria-label="Slips waiting">
          {list.map((s, i) => (
            <SlipCard
              key={s.id}
              ref={(el) => {
                if (el) cards.current.set(s.id, el);
                else cards.current.delete(s.id);
              }}
              s={s}
              today={today}
              active={s.id === activeId}
              position={`Slip ${i + 1} of ${list.length}`}
              after={list.length < 2 ? undefined : i < list.length - 1 ? "next" : "continue"}
              onActivate={() => setActiveId(s.id)}
              onDone={onDone}
              onFailed={onFailed}
            />
          ))}
        </ul>
      )}
    </>
  );
}

const outcomeMeta: Record<
  HandledSlip["outcome"],
  {
    label: string;
    tone: "success" | "danger" | "warning";
    Icon: typeof CheckCircle2;
  }
> = {
  approved: { label: "Approved", tone: "success", Icon: CheckCircle2 },
  rejected: { label: "Rejected", tone: "danger", Icon: XCircle },
  gateway_paid: {
    label: "Paid online too",
    tone: "warning",
    Icon: AlertTriangle,
  },
  gateway_paid_after_manual: {
    label: "Refund the online payment",
    tone: "warning",
    Icon: AlertTriangle,
  },
  group_refund: {
    label: "Group payment not applied",
    tone: "warning",
    Icon: AlertTriangle,
  },
  underpaid: {
    label: "Paid less than the bill",
    tone: "warning",
    Icon: AlertTriangle,
  },
};

function HandledList({ items }: { items: HandledSlip[] }) {
  if (items.length === 0) {
    return <p className="rounded-lg border bg-card p-6 text-center text-sm text-muted-foreground">Nothing handled yet.</p>;
  }
  return (
    <ul className="flex flex-col divide-y rounded-lg border bg-card">
      {items.map((h) => {
        const m = outcomeMeta[h.outcome];
        return (
          <li key={h.id} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:gap-4">
            <div className="flex min-w-0 flex-1 items-start gap-3">
              <Avatar name={h.member} />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="truncate text-sm font-semibold">{h.member}</span>
                  <StatusChip Icon={m.Icon} tone={m.tone}>
                    {m.label}
                  </StatusChip>
                </div>
                <div className="truncate text-xs text-muted-foreground">
                  {h.periodStart && h.periodEnd
                    ? feeLabel({
                        periodStart: h.periodStart,
                        periodEnd: h.periodEnd,
                      })
                    : `Payment #${h.paymentId}`}{" "}
                  · {h.by} · {fmtDateTime(h.at)}
                </div>
                {h.outcome === "rejected" && h.reason && <p className="mt-1 text-sm">“{h.reason}”</p>}
                {h.outcome === "approved" && (
                  <p className="mt-1 text-sm text-muted-foreground">
                    Paid {h.paidDate ? fmtDate(h.paidDate) : ""}
                    {h.reference ? ` · ref ${h.reference}` : ""}
                  </p>
                )}
                {h.outcome === "group_refund" && (
                  <p className="mt-1 text-sm text-warning-foreground">
                    Online payment received for a group bill
                    {h.reference ? ` (txn ${h.reference})` : ""}, but nothing was marked paid: refund it, or record the fees.
                  </p>
                )}
                {h.outcome === "underpaid" && (
                  <p className="mt-1 text-sm text-warning-foreground">
                    Online payment was less than the bill
                    {h.reference ? ` (txn ${h.reference})` : ""}: nothing was marked paid.
                  </p>
                )}
                {h.outcome === "gateway_paid_after_manual" && (
                  <p className="mt-1 text-sm text-warning-foreground">
                    Paid online after it was recorded as {h.recordedAs ?? "paid by hand"}
                    {h.reference ? ` (txn ${h.reference})` : ""}. Both payments reached the club: refund the online payment.
                  </p>
                )}
                {h.outcome === "gateway_paid" && (
                  <p className="mt-1 text-sm text-warning-foreground">
                    Paid online while a transfer slip was also sent
                    {h.reference ? ` (txn ${h.reference})` : ""}. If both payments reached the club, refund one to the member.
                  </p>
                )}
              </div>
            </div>
            <div className="flex items-center justify-between gap-3 pl-12 sm:flex-col sm:items-end sm:pl-0">
              <Money sen={h.amount} className="text-sm font-bold" />
              {h.url && (
                <div className="flex items-center gap-3">
                  <a href={`${h.url}#slips`} className="text-xs font-semibold text-primary hover:underline">
                    View slips
                  </a>
                  <a href={h.url} className="text-xs font-semibold text-primary hover:underline">
                    Payment record
                  </a>
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

export default function VerificationsIndex({ nav, club, tab: initialTab, pending, handled, today, urls }: Props) {
  const [tab, setTab] = useState(initialTab);
  return (
    <AdminLayout nav={nav} active="verifications" title="Payment verifications">
      <PageHeader
        eyebrow="Bank transfers and DuitNow"
        title="Payment verifications"
        description="Members who paid by bank transfer or DuitNow sent these slips. Check each one against the club's bank statement, then approve or reject it."
        actions={
          <a href={urls.billing} className="inline-flex min-h-9 items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
            <Landmark className="h-4 w-4" aria-hidden /> Bank details
          </a>
        }
      />

      {!club.transferReady && (
        <Alert variant="info">
          <Landmark aria-hidden />
          <div>
            <AlertTitle>Bank transfer is off for members</AlertTitle>
            <AlertDescription>
              New slips can't be sent until you add the club's bank details and turn it on in{" "}
              <a href={urls.billing} className="font-semibold underline">
                Billing settings
              </a>
              . Slips already sent still show here.
            </AlertDescription>
          </div>
        </Alert>
      )}

      {/* Two equal halves that may wrap their label on a narrow phone, instead of clipping "Recently handled". */}
      <FilterGroup aria-label="Show slips" className="grid h-auto w-full grid-cols-2 sm:inline-flex sm:w-auto">
        <FilterButton active={tab === "pending"} onClick={() => setTab("pending")} className="min-h-10 flex-wrap whitespace-normal text-center">
          <Clock className="h-4 w-4" aria-hidden /> Waiting
          <span className="rounded-full bg-background/70 px-1.5 font-mono text-xs">{pending.length}</span>
        </FilterButton>
        <FilterButton active={tab === "handled"} onClick={() => setTab("handled")} className="min-h-10 flex-wrap whitespace-normal text-center">
          <BadgeCheck className="h-4 w-4" aria-hidden /> Recently handled
          <span className="rounded-full bg-background/70 px-1.5 font-mono text-xs">{handled.length}</span>
        </FilterButton>
      </FilterGroup>

      {tab === "pending" ? (
        pending.length === 0 ? (
          <div className="flex flex-col items-center gap-2 rounded-lg border bg-card p-10 text-center">
            <Inbox className="h-8 w-8 text-muted-foreground" strokeWidth={1.5} aria-hidden />
            <p className="font-semibold">No slips waiting</p>
            <p className="text-sm text-muted-foreground">When a member sends a transfer slip it shows up here.</p>
          </div>
        ) : (
          <WaitingQueue pending={pending} today={today} />
        )
      ) : (
        <HandledList items={handled} />
      )}
    </AdminLayout>
  );
}
