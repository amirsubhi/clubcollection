import { useMemo, useState } from "react";
import { Ban, ChevronRight, FilePlus2, HandHeart, Paperclip, Search } from "lucide-react";
import { AdminShell } from "@/components/app/AdminShell";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { FundStatusBadge, FundTypeBadge } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { expenseCategories, fundItems, welfareCategories } from "@/data/fund";
import { ytd } from "@/data/report";
import type { FundItem, Sen } from "@/data/types";
import { fmtDate } from "@/lib/format";

type TabId = "all" | "expense" | "welfare" | "mine" | "void";

const tabDefs: { id: TabId; label: string; filter: (i: FundItem) => boolean }[] = [
  { id: "all", label: "All", filter: (i) => i.status !== "void" },
  { id: "expense", label: "Expenses", filter: (i) => i.type === "expense" && i.status !== "void" },
  { id: "welfare", label: "Welfare", filter: (i) => i.type === "welfare" && i.status !== "void" },
  { id: "mine", label: "Awaiting my approval", filter: (i) => !!i.awaitingMe },
  { id: "void", label: "Void", filter: (i) => i.status === "void" },
];

export function KpiCard({ label, sub, value, money = true, tone }: { label: string; sub?: string; value: Sen | number; money?: boolean; tone?: "warning" }) {
  return (
    <Card className={tone === "warning" ? "border-warning/50 bg-warning-soft" : undefined}>
      <CardContent className="p-4 sm:p-4">
        <div className="text-xs font-medium text-muted-foreground">{label}</div>
        {money ? <Money sen={value} className="mt-1 block font-display text-xl font-semibold" /> : <div className="mt-1 font-display text-xl font-semibold">{value}</div>}
        {sub && <div className="mt-0.5 text-xs text-muted-foreground">{sub}</div>}
      </CardContent>
    </Card>
  );
}

function EmptyState() {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-14 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
        <Ban className="h-6 w-6 text-muted-foreground" aria-hidden />
      </div>
      <h3 className="font-display font-semibold">No voided records in 2026</h3>
      <p className="max-w-sm text-sm text-muted-foreground">
        Approved records can't be edited or deleted. If one is wrong, open it and choose Void; a second admin must confirm.
      </p>
    </div>
  );
}

export function FundUsageList() {
  const [tab, setTab] = useState<TabId>("all");
  const [q, setQ] = useState("");
  const [cat, setCat] = useState("any");
  const [acct, setAcct] = useState("any");
  const awaiting = fundItems.filter((i) => i.status === "submitted").length;

  const rows = useMemo(() => {
    const def = tabDefs.find((d) => d.id === tab)!;
    const needle = q.trim().toLowerCase();
    return fundItems
      .filter(def.filter)
      .filter((i) => cat === "any" || i.category === cat)
      .filter((i) => acct === "any" || i.account === acct)
      .filter((i) => !needle || `${i.ref} ${i.description} ${i.payee}`.toLowerCase().includes(needle));
  }, [tab, q, cat, acct]);

  return (
    <AdminShell active="fund-usage">
      <PageHeader
        title="Fund Usage"
        eyebrow="Penggunaan Dana · FY 2026"
        description="Every ringgit spent, with documents and a second-admin approval."
        actions={
          <>
            <Button asChild variant="outline">
              <a href="#welfare-new">
                <HandHeart aria-hidden /> New welfare case
              </a>
            </Button>
            <Button asChild>
              <a href="#fund-new">
                <FilePlus2 aria-hidden /> New expense
              </a>
            </Button>
          </>
        }
      />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        <KpiCard label="Collected (YTD)" sub="Fees, drives, other" value={ytd.collected} />
        <KpiCard label="Spent (approved)" sub="Expenses only" value={ytd.spent} />
        <KpiCard label="Welfare paid" sub={`${ytd.welfareCount} cases`} value={ytd.welfarePaid} />
        <KpiCard label="Balance" sub="Cash + bank" value={ytd.balance} />
        <KpiCard label="Awaiting approval" sub="Menunggu kelulusan" value={awaiting} money={false} tone="warning" />
      </div>

      <Card>
        <CardContent className="flex flex-col gap-3 p-3 sm:p-4">
          <Tabs value={tab} onValueChange={(v) => setTab(v as TabId)}>
            <div className="-mx-1 overflow-x-auto px-1 pb-1">
              <TabsList>
                {tabDefs.map((d) => (
                  <TabsTrigger key={d.id} value={d.id}>
                    {d.label}
                    <span className="rounded-full bg-secondary px-1.5 text-[11px] text-muted-foreground">{fundItems.filter(d.filter).length}</span>
                  </TabsTrigger>
                ))}
              </TabsList>
            </div>
          </Tabs>
          <div className="grid gap-2 sm:grid-cols-[1fr_200px_160px]">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search ref, payee, description" className="pl-9" aria-label="Search" />
            </div>
            <Select value={cat} onValueChange={setCat}>
              <SelectTrigger aria-label="Category">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="any">All categories</SelectItem>
                {[...expenseCategories, ...welfareCategories].map((c) => (
                  <SelectItem key={c} value={c}>
                    {c}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Select value={acct} onValueChange={setAcct}>
              <SelectTrigger aria-label="Account">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="any">Cash & bank</SelectItem>
                <SelectItem value="cash">Cash only</SelectItem>
                <SelectItem value="bank">Bank only</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>

        {rows.length === 0 ? (
          tab === "void" ? (
            <EmptyState />
          ) : (
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">No records match these filters.</p>
          )
        ) : (
          <>
            {/* Desktop table */}
            <div className="hidden border-t md:block">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    <TableHead>Date paid</TableHead>
                    <TableHead>Record</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-center">
                      <Paperclip className="mx-auto h-4 w-4" aria-label="Attachments" />
                    </TableHead>
                    <TableHead>Recorded / approved by</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((i) => (
                    <TableRow key={i.id} className="cursor-pointer" onClick={() => (window.location.hash = "fund-item")}>
                      <TableCell className="whitespace-nowrap text-muted-foreground">{fmtDate(i.paidDate ?? i.invoiceDate)}</TableCell>
                      <TableCell className="max-w-[320px]">
                        <a href="#fund-item" className="font-medium hover:underline" onClick={(e) => e.stopPropagation()}>
                          {i.ref}
                        </a>
                        <div className="truncate text-xs text-muted-foreground">
                          {i.category} · {i.payee}
                        </div>
                      </TableCell>
                      <TableCell>
                        <FundTypeBadge type={i.type} />
                      </TableCell>
                      <TableCell className="text-right">
                        <Money sen={i.amount} className="font-medium" />
                      </TableCell>
                      <TableCell>
                        <FundStatusBadge status={i.status} />
                      </TableCell>
                      <TableCell className={`text-center ${i.attachments.length === 0 ? "text-danger-foreground" : ""}`}>{i.attachments.length}</TableCell>
                      <TableCell className="text-xs">
                        <div>{i.recordedBy}</div>
                        <div className="text-muted-foreground">{i.approvedBy ? `✓ ${i.approvedBy}` : "—"}</div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            {/* Mobile list */}
            <ul className="divide-y border-t md:hidden">
              {rows.map((i) => (
                <li key={i.id}>
                  <a href="#fund-item" className="flex items-start gap-3 px-3 py-3 hover:bg-muted/50">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-sm font-semibold">{i.ref}</span>
                        <FundTypeBadge type={i.type} />
                      </div>
                      <div className="mt-0.5 line-clamp-2 text-sm">{i.description}</div>
                      <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span>{fmtDate(i.paidDate ?? i.invoiceDate)}</span>
                        <span className="inline-flex items-center gap-0.5">
                          <Paperclip className="h-3 w-3" aria-hidden />
                          {i.attachments.length}
                        </span>
                        <span>by {i.recordedBy.split(" ")[0]}</span>
                      </div>
                    </div>
                    <div className="flex flex-col items-end gap-1">
                      <Money sen={i.amount} className="text-sm font-semibold" />
                      <FundStatusBadge status={i.status} />
                    </div>
                    <ChevronRight className="mt-1 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                  </a>
                </li>
              ))}
            </ul>
          </>
        )}
      </Card>
      <p className="text-xs text-muted-foreground">
        Only approved records count towards balances, the member card and the AGM report. Drafts and items awaiting approval are excluded.
      </p>
    </AdminShell>
  );
}
