import { useMemo, useState } from "react";
import {
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Ban,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  Clock,
  FilePlus2,
  HandHeart,
  Paperclip,
  PlusCircle,
  Search,
  TrendingDown,
  Wallet,
  X,
} from "lucide-react";
import { AdminShell } from "@/components/app/AdminShell";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { KpiTile } from "@/components/app/Charts";
import { FundStatusBadge, FundTypeBadge } from "@/components/app/StatusBadges";
import { Avatar } from "@/components/app/Visuals";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { expenseCategories, fundItems, statusMeta, welfareCategories } from "@/data/fund";
import { ytd } from "@/data/report";
import type { FundItem, FundStatus } from "@/data/types";
import { fmtDate, rm } from "@/lib/format";
import { cn } from "@/lib/utils";

type TabId = "all" | "expense" | "welfare" | "mine" | "void";

const tabDefs: { id: TabId; label: string; filter: (i: FundItem) => boolean }[] = [
  { id: "all", label: "All", filter: (i) => i.status !== "void" },
  { id: "expense", label: "Expenses", filter: (i) => i.type === "expense" && i.status !== "void" },
  { id: "welfare", label: "Welfare", filter: (i) => i.type === "welfare" && i.status !== "void" },
  { id: "mine", label: "Awaiting my approval", filter: (i) => !!i.awaitingMe },
  { id: "void", label: "Void", filter: (i) => i.status === "void" },
];

function EmptyState() {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-14 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
        <Ban className="h-6 w-6 text-muted-foreground" strokeWidth={1.75} aria-hidden />
      </div>
      <h3 className="font-display text-lg font-bold">No voided records in 2026</h3>
      <p className="max-w-sm text-sm text-muted-foreground">
        Approved records can't be edited or deleted. If one is wrong, open it and choose Void; a second admin must confirm.
      </p>
    </div>
  );
}

/** Faceted filter button (shadcn-admin pattern): dashed outline, selected values shown as pills. */
function Facet<T extends string>({
  title,
  options,
  selected,
  onChange,
  counts,
}: {
  title: string;
  options: { value: T; label: string }[];
  selected: T[];
  onChange: (v: T[]) => void;
  counts: Record<string, number>;
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm" className="h-9 border-dashed font-medium">
          <PlusCircle aria-hidden /> {title}
          {selected.length > 0 && (
            <>
              <span className="mx-0.5 h-4 w-px bg-border" aria-hidden />
              <span className="rounded bg-secondary px-1.5 text-xs font-semibold">{selected.length > 1 ? `${selected.length} selected` : options.find((o) => o.value === selected[0])?.label}</span>
            </>
          )}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-60">
        <DropdownMenuLabel>{title}</DropdownMenuLabel>
        {options.map((o) => (
          <DropdownMenuCheckboxItem
            key={o.value}
            checked={selected.includes(o.value)}
            onSelect={(e) => e.preventDefault()}
            onCheckedChange={(c) => onChange(c ? [...selected, o.value] : selected.filter((x) => x !== o.value))}
          >
            <span className="truncate">{o.label}</span>
            <span className="ml-auto font-mono text-xs text-muted-foreground">{counts[o.value] ?? 0}</span>
          </DropdownMenuCheckboxItem>
        ))}
        {selected.length > 0 && (
          <>
            <DropdownMenuSeparator />
            <button type="button" onClick={() => onChange([])} className="w-full rounded-md px-2 py-2 text-center text-sm hover:bg-muted">
              Clear filter
            </button>
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

type SortKey = "date" | "amount";

function SortHead({ label, k, sort, setSort, className }: { label: string; k: SortKey; sort: { k: SortKey; dir: "asc" | "desc" }; setSort: (s: { k: SortKey; dir: "asc" | "desc" }) => void; className?: string }) {
  const on = sort.k === k;
  const Icon = on ? (sort.dir === "asc" ? ArrowUp : ArrowDown) : ArrowUpDown;
  return (
    <TableHead className={className} aria-sort={on ? (sort.dir === "asc" ? "ascending" : "descending") : "none"}>
      <button
        type="button"
        onClick={() => setSort({ k, dir: on && sort.dir === "desc" ? "asc" : "desc" })}
        className={cn("-mx-2 inline-flex h-8 items-center gap-1 rounded-md px-2 hover:bg-muted hover:text-foreground", on && "text-foreground", className?.includes("text-right") && "flex-row-reverse")}
      >
        {label} <Icon className="h-3.5 w-3.5" aria-hidden />
      </button>
    </TableHead>
  );
}

export function FundUsageList() {
  const [tab, setTab] = useState<TabId>("all");
  const [q, setQ] = useState("");
  const [statuses, setStatuses] = useState<FundStatus[]>([]);
  const [cats, setCats] = useState<string[]>([]);
  const [acct, setAcct] = useState<("cash" | "bank")[]>([]);
  const [sort, setSort] = useState<{ k: SortKey; dir: "asc" | "desc" }>({ k: "date", dir: "desc" });
  const [pageSize, setPageSize] = useState(6);
  const [page, setPage] = useState(0);
  const submitted = fundItems.filter((i) => i.status === "submitted");

  const base = useMemo(() => fundItems.filter(tabDefs.find((d) => d.id === tab)!.filter), [tab]);
  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    const r = base
      .filter((i) => statuses.length === 0 || statuses.includes(i.status))
      .filter((i) => cats.length === 0 || cats.includes(i.category))
      .filter((i) => acct.length === 0 || acct.includes(i.account))
      .filter((i) => !needle || `${i.ref} ${i.description} ${i.payee}`.toLowerCase().includes(needle));
    const m = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => (sort.k === "amount" ? (a.amount - b.amount) * m : (a.paidDate ?? a.invoiceDate).localeCompare(b.paidDate ?? b.invoiceDate) * m));
  }, [base, q, statuses, cats, acct, sort]);

  const pages = Math.max(1, Math.ceil(rows.length / pageSize));
  const p = Math.min(page, pages - 1);
  const shown = rows.slice(p * pageSize, p * pageSize + pageSize);
  const filtered = statuses.length + cats.length + acct.length > 0 || q.length > 0;
  const count = (f: (i: FundItem) => string) => base.reduce<Record<string, number>>((acc, i) => ((acc[f(i)] = (acc[f(i)] ?? 0) + 1), acc), {});

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

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiTile label="Collected (YTD)" value={rm(ytd.collected)} hint="Fees, drives, other" Icon={Wallet} tone="accent" />
        <KpiTile label="Spent (approved)" value={rm(ytd.spent)} hint="Expenses only" Icon={TrendingDown} tone="accent" />
        <KpiTile label="Welfare paid" value={rm(ytd.welfarePaid)} hint={`${ytd.welfareCount} cases`} Icon={HandHeart} tone="accent" />
        <KpiTile label="Awaiting approval" value={`${submitted.length}`} hint="Menunggu kelulusan" Icon={Clock} tone="accent" />
      </div>

      <section className="flex min-w-0 flex-col gap-3">
        <Tabs value={tab} onValueChange={(v) => (setTab(v as TabId), setPage(0))}>
          <div className="scrollbar-none -mx-1 overflow-x-auto px-1 pb-1">
            <TabsList>
              {tabDefs.map((d) => (
                <TabsTrigger key={d.id} value={d.id}>
                  {d.label}
                  <span className="rounded-full bg-background/70 px-1.5 font-mono text-[11px]">{fundItems.filter(d.filter).length}</span>
                </TabsTrigger>
              ))}
            </TabsList>
          </div>
        </Tabs>

        {/* Toolbar */}
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
          <div className="relative w-full sm:w-64">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input value={q} onChange={(e) => (setQ(e.target.value), setPage(0))} placeholder="Filter ref, payee, description…" className="h-9 pl-9" aria-label="Filter records" />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Facet<FundStatus>
              title="Status"
              options={(Object.keys(statusMeta) as FundStatus[]).map((s) => ({ value: s, label: statusMeta[s].label }))}
              selected={statuses}
              onChange={(v) => (setStatuses(v), setPage(0))}
              counts={count((i) => i.status)}
            />
            <Facet<string>
              title="Category"
              options={[...expenseCategories, ...welfareCategories].map((c) => ({ value: c, label: c }))}
              selected={cats}
              onChange={(v) => (setCats(v), setPage(0))}
              counts={count((i) => i.category)}
            />
            <Facet<"cash" | "bank">
              title="Account"
              options={[
                { value: "cash", label: "Cash in hand" },
                { value: "bank", label: "Bank" },
              ]}
              selected={acct}
              onChange={(v) => (setAcct(v), setPage(0))}
              counts={count((i) => i.account)}
            />
            {filtered && (
              <Button variant="ghost" size="sm" className="h-9" onClick={() => (setQ(""), setStatuses([]), setCats([]), setAcct([]))}>
                Reset <X aria-hidden />
              </Button>
            )}
          </div>
        </div>

        <div className="overflow-hidden rounded-lg border bg-card">
          {rows.length === 0 ? (
            tab === "void" ? (
              <EmptyState />
            ) : (
              <p className="px-4 py-10 text-center text-sm text-muted-foreground">No records match these filters.</p>
            )
          ) : (
            <>
              {/* Desktop table */}
              <div className="hidden md:block">
                <Table>
                  <TableHeader className="bg-muted/50">
                    <TableRow className="hover:bg-transparent">
                      <SortHead label="Date paid" k="date" sort={sort} setSort={setSort} />
                      <TableHead>Record</TableHead>
                      <TableHead>Paid to</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="text-center">
                        <Paperclip className="mx-auto h-4 w-4" aria-label="Attachments" />
                      </TableHead>
                      <TableHead>Maker / checker</TableHead>
                      <SortHead label="Amount" k="amount" sort={sort} setSort={setSort} className="text-right" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {shown.map((i) => (
                      <TableRow key={i.id} className="cursor-pointer" onClick={() => (window.location.hash = "fund-item")}>
                        <TableCell className="whitespace-nowrap text-muted-foreground">{fmtDate(i.paidDate ?? i.invoiceDate)}</TableCell>
                        <TableCell className="whitespace-nowrap">
                          <a href="#fund-item" className="font-mono text-[13px] font-medium hover:underline" onClick={(e) => e.stopPropagation()}>
                            {i.ref}
                          </a>
                        </TableCell>
                        <TableCell className="max-w-[260px]">
                          <div className="truncate" title={`${i.category} · ${i.payee}`}>
                            {i.payee}
                          </div>
                        </TableCell>
                        <TableCell>
                          <FundTypeBadge type={i.type} />
                        </TableCell>
                        <TableCell>
                          <FundStatusBadge status={i.status} />
                        </TableCell>
                        <TableCell className={cn("text-center font-mono text-xs", i.attachments.length === 0 && "font-bold text-danger-foreground")}>{i.attachments.length}</TableCell>
                        <TableCell>
                          <div className="flex items-center -space-x-1.5">
                            <span title={`Recorded by ${i.recordedBy}`}>
                              <Avatar name={i.recordedBy} size={28} ring />
                            </span>
                            {i.approvedBy ? (
                              <span title={`Approved by ${i.approvedBy}`}>
                                <Avatar name={i.approvedBy} size={28} ring />
                              </span>
                            ) : (
                              <span className="inline-flex h-7 w-7 items-center justify-center rounded-full border border-dashed border-input bg-card text-[11px] text-muted-foreground ring-2 ring-card" title="Not approved yet">
                                ?
                              </span>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="text-right">
                          <span className="money font-semibold">−{rm(i.amount)}</span>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
              {/* Mobile list */}
              <ul className="divide-y md:hidden">
                {shown.map((i) => (
                  <li key={i.id}>
                    <a href="#fund-item" className="flex items-start gap-3 px-3 py-3 hover:bg-muted/50">
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <span className="font-mono text-[13px] font-medium">{i.ref}</span>
                          <FundTypeBadge type={i.type} />
                        </div>
                        <div className="mt-1 line-clamp-2 text-sm">{i.description}</div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <span>{fmtDate(i.paidDate ?? i.invoiceDate)}</span>
                          <span className="inline-flex items-center gap-0.5">
                            <Paperclip className="h-3 w-3" aria-hidden />
                            {i.attachments.length}
                          </span>
                          <span>by {i.recordedBy.split(" ")[0]}</span>
                        </div>
                      </div>
                      <div className="flex flex-col items-end gap-1.5">
                        <span className="money text-sm font-bold">−{rm(i.amount)}</span>
                        <FundStatusBadge status={i.status} />
                      </div>
                    </a>
                  </li>
                ))}
              </ul>
              {/* Pagination */}
              <div className="flex flex-col gap-2 border-t px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                <span className="text-muted-foreground">
                  {rows.length} record{rows.length === 1 ? "" : "s"} · <Money sen={rows.reduce((s, i) => s + i.amount, 0)} className="font-semibold text-foreground" />
                </span>
                <div className="flex items-center gap-3">
                  <div className="hidden items-center gap-2 sm:flex">
                    <span className="text-muted-foreground">Rows</span>
                    <Select value={String(pageSize)} onValueChange={(v) => (setPageSize(Number(v)), setPage(0))}>
                      <SelectTrigger className="h-8 w-[68px]" aria-label="Rows per page">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {[6, 10, 20].map((n) => (
                          <SelectItem key={n} value={String(n)}>
                            {n}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <span className="font-medium">
                    Page {p + 1} of {pages}
                  </span>
                  <div className="flex items-center gap-1">
                    {[
                      { Icon: ChevronsLeft, to: 0, label: "First page", dis: p === 0 },
                      { Icon: ChevronLeft, to: p - 1, label: "Previous page", dis: p === 0 },
                      { Icon: ChevronRight, to: p + 1, label: "Next page", dis: p >= pages - 1 },
                      { Icon: ChevronsRight, to: pages - 1, label: "Last page", dis: p >= pages - 1 },
                    ].map(({ Icon, to, label, dis }) => (
                      <Button key={label} variant="outline" size="icon" className="h-8 w-8" disabled={dis} onClick={() => setPage(to)} aria-label={label}>
                        <Icon />
                      </Button>
                    ))}
                  </div>
                </div>
              </div>
            </>
          )}
        </div>
      </section>
      <p className="text-xs text-muted-foreground">
        Only approved records count towards balances, the member card and the AGM report. Drafts and items awaiting approval are excluded.
      </p>
    </AdminShell>
  );
}
