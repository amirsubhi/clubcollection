import { useEffect, useRef, useState } from "react";
import { Link, router } from "@inertiajs/react";
import { ArrowDown, ArrowUp, ArrowUpDown, Ban, ChevronLeft, ChevronRight, Clock, FilePlus2, HandHeart, Landmark, Paperclip, PlusCircle, Search, TrendingDown, Wallet, X } from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { KpiTile, SpentSplit } from "@/components/app/Charts";
import { FundStatusBadge, FundTypeBadge, StatusChip } from "@/components/app/StatusBadges";
import { Avatar } from "@/components/app/Visuals";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { FilterButton, FilterGroup } from "@/components/ui/tabs";
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { statusMeta } from "@/lib/fund-meta";
import { fmtDate, rm } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { AdminNavProps, FigureWindow, FundRow, Option } from "@/types/admin";
import type { FundStatus } from "@/types/domain";

type TabId = "all" | "expense" | "welfare" | "mine" | "void";

interface Filters {
  tab: TabId;
  q: string;
  status: FundStatus[];
  category: number[];
  sort: "date" | "amount";
  dir: "asc" | "desc";
  per: number;
}

interface Props {
  nav: AdminNavProps;
  /** FundFigures: same figures and windows as the dashboard. spentYtd = expenses + welfare + bank charges. */
  kpis: {
    window: FigureWindow;
    collectedYtd: number;
    spentYtd: number;
    expensesYtd: number;
    chargesYtd: number;
    welfareYtd: number;
    welfareCountYtd: number;
    balance: number;
    balanceAsOf: string;
    awaiting: number;
    awaitingSum: number;
    awaitingMine: number;
    year: number;
  };
  tabCounts: Record<TabId, number>;
  filters: Filters;
  categories: Option[];
  rows: FundRow[];
  pagination: { page: number; pages: number; total: number; totalAmount: number; prev: string | null; next: string | null };
  urls: { index: string; createExpense: string; createWelfare: string };
}

const tabDefs: { id: TabId; label: string }[] = [
  { id: "all", label: "All" },
  { id: "expense", label: "Expenses" },
  { id: "welfare", label: "Welfare" },
  { id: "mine", label: "Awaiting my approval" },
  { id: "void", label: "Void" },
];

function Facet<T extends string | number>({
  title,
  options,
  selected,
  onChange,
}: {
  title: string;
  options: { value: T; label: string }[];
  selected: T[];
  onChange: (v: T[]) => void;
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm" className="h-9 border-dashed font-medium">
          <PlusCircle aria-hidden /> {title}
          {selected.length > 0 && (
            <>
              <span className="mx-0.5 h-4 w-px bg-border" aria-hidden />
              <span className="rounded bg-secondary px-1.5 text-xs font-semibold">
                {selected.length > 1 ? `${selected.length} selected` : options.find((o) => o.value === selected[0])?.label}
              </span>
            </>
          )}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-60">
        <DropdownMenuLabel>{title}</DropdownMenuLabel>
        {options.map((o) => (
          <DropdownMenuCheckboxItem
            key={String(o.value)}
            checked={selected.includes(o.value)}
            onSelect={(e) => e.preventDefault()}
            onCheckedChange={(c) => onChange(c ? [...selected, o.value] : selected.filter((x) => x !== o.value))}
          >
            <span className="truncate">{o.label}</span>
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

function SortHead({ label, k, filters, onSort, className }: { label: string; k: Filters["sort"]; filters: Filters; onSort: (k: Filters["sort"], dir: Filters["dir"]) => void; className?: string }) {
  const on = filters.sort === k;
  const Icon = on ? (filters.dir === "asc" ? ArrowUp : ArrowDown) : ArrowUpDown;
  return (
    <TableHead className={className} aria-sort={on ? (filters.dir === "asc" ? "ascending" : "descending") : "none"}>
      <button
        type="button"
        onClick={() => onSort(k, on && filters.dir === "desc" ? "asc" : "desc")}
        className={cn("-mx-2 inline-flex h-8 items-center gap-1 rounded-md px-2 hover:bg-muted hover:text-foreground", on && "text-foreground", className?.includes("text-right") && "flex-row-reverse")}
      >
        {label} <Icon className="h-3.5 w-3.5" aria-hidden />
      </button>
    </TableHead>
  );
}

function EmptyState({ tab }: { tab: TabId }) {
  if (tab !== "void") return <p className="px-4 py-10 text-center text-sm text-muted-foreground">No records match these filters.</p>;
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-14 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
        <Ban className="h-6 w-6 text-muted-foreground" strokeWidth={1.75} aria-hidden />
      </div>
      <h2 className="font-display text-lg font-bold">No voided records</h2>
      <p className="max-w-sm text-sm text-muted-foreground">Approved records can't be edited or deleted. If one is wrong, open it and choose Void; a second admin must confirm.</p>
    </div>
  );
}

function PaidTo({ r }: { r: FundRow }) {
  return r.payee ? (
    <span className="truncate" title={`${r.category} · ${r.payee}`}>
      {r.payee}
    </span>
  ) : (
    <span className="truncate text-muted-foreground" title="Recipient details are private to the record">
      {r.category} · private
    </span>
  );
}

export default function FundUsageIndex({ nav, kpis, tabCounts, filters, categories, rows, pagination, urls }: Props) {
  const [q, setQ] = useState(filters.q);
  const first = useRef(true);

  const visit = (patch: Partial<Filters>) => {
    const next = { ...filters, ...patch };
    const params: Record<string, unknown> = {};
    if (next.tab !== "all") params.tab = next.tab;
    if (next.q) params.q = next.q;
    if (next.status.length) params.status = next.status;
    if (next.category.length) params.category = next.category;
    if (next.sort !== "date") params.sort = next.sort;
    if (next.dir !== "desc") params.dir = next.dir;
    if (next.per !== 10) params.per = next.per;
    router.get(urls.index, params as never, { preserveState: true, preserveScroll: true, replace: true });
  };

  // Debounced search box.
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    const t = setTimeout(() => visit({ q: q.trim() }), 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  const filtered = filters.status.length + filters.category.length > 0 || filters.q.length > 0;

  return (
    <AdminLayout nav={nav} active="fund-usage" title="Fund Usage">
      <PageHeader
        title="Fund Usage"
        eyebrow={`Year ${kpis.year}`}
        description="Every ringgit spent, with documents and a second-admin approval."
        actions={
          <>
            <Button asChild variant="outline">
              <Link href={urls.createWelfare}>
                <HandHeart aria-hidden /> New welfare case
              </Link>
            </Button>
            <Button asChild>
              <Link href={urls.createExpense}>
                <FilePlus2 aria-hidden /> New expense
              </Link>
            </Button>
          </>
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiTile label="Collected" window={kpis.window.range} value={rm(kpis.collectedYtd)} hint="Paid fees" Icon={Wallet} />
        <KpiTile
          label="Spent"
          window={kpis.window.range}
          value={rm(kpis.spentYtd)}
          detail={<SpentSplit expenses={kpis.expensesYtd} welfare={kpis.welfareYtd} charges={kpis.chargesYtd} />}
          hint={`Approved records + bank charges · ${kpis.welfareCountYtd} welfare case${kpis.welfareCountYtd === 1 ? "" : "s"}`}
          Icon={TrendingDown}
        />
        <KpiTile label="Fund balance" window={`As of ${fmtDate(kpis.balanceAsOf)}`} value={rm(kpis.balance)} hint="All fees collected minus everything spent" Icon={Landmark} />
        <KpiTile
          label="Awaiting approval"
          window="Waiting now"
          value={`${kpis.awaiting} record${kpis.awaiting === 1 ? "" : "s"}`}
          hint={kpis.awaiting === 0 ? "Nothing to approve" : `${rm(kpis.awaitingSum)} · ${kpis.awaitingMine} for you`}
          Icon={Clock}
        />
      </div>

      <section className="flex min-w-0 flex-col gap-3">
        <div className="scrollbar-none -mx-1 overflow-x-auto px-1 pb-1">
          <FilterGroup aria-label="Show records">
            {tabDefs.map((d) => (
              <FilterButton key={d.id} active={filters.tab === d.id} onClick={() => visit({ tab: d.id })}>
                {d.label}
                <span className="rounded-full bg-background/70 px-1.5 font-mono text-xs">{tabCounts[d.id] ?? 0}</span>
              </FilterButton>
            ))}
          </FilterGroup>
        </div>

        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
          <div className="relative w-full sm:w-64">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filter ref, payee, description…" className="h-9 pl-9" aria-label="Filter records" />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Facet<FundStatus>
              title="Status"
              options={(Object.keys(statusMeta) as FundStatus[]).map((s) => ({ value: s, label: statusMeta[s].label }))}
              selected={filters.status}
              onChange={(v) => visit({ status: v })}
            />
            <Facet<number> title="Category" options={categories.map((c) => ({ value: c.id, label: c.name }))} selected={filters.category} onChange={(v) => visit({ category: v })} />
            {filtered && (
              <Button
                variant="ghost"
                size="sm"
                className="h-9"
                onClick={() => {
                  setQ("");
                  visit({ q: "", status: [], category: [] });
                }}
              >
                Reset <X aria-hidden />
              </Button>
            )}
          </div>
        </div>

        <div className="overflow-hidden rounded-lg border bg-card">
          {rows.length === 0 ? (
            <EmptyState tab={filters.tab} />
          ) : (
            <>
              <div className="hidden md:block">
                <Table>
                  <TableHeader className="bg-muted/50">
                    <TableRow className="hover:bg-transparent">
                      <SortHead label="Date paid" k="date" filters={filters} onSort={(sort, dir) => visit({ sort, dir })} />
                      <TableHead>Record</TableHead>
                      <TableHead>Paid to</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="text-center">
                        <Paperclip className="mx-auto h-4 w-4" aria-label="Attachments" />
                      </TableHead>
                      <TableHead>Maker / checker</TableHead>
                      <SortHead label="Amount" k="amount" filters={filters} onSort={(sort, dir) => visit({ sort, dir })} className="text-right" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {rows.map((r) => (
                      <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(r.url)}>
                        <TableCell className="whitespace-nowrap text-muted-foreground">{fmtDate(r.paidDate ?? r.invoiceDate)}</TableCell>
                        <TableCell className="whitespace-nowrap">
                          <Link href={r.url} className="font-mono text-[0.8125rem] font-medium hover:underline" onClick={(e) => e.stopPropagation()}>
                            {r.ref}
                          </Link>
                        </TableCell>
                        <TableCell className="max-w-[260px]">
                          <div className="flex min-w-0 flex-col">
                            <PaidTo r={r} />
                            <span className="truncate text-xs text-muted-foreground">{r.description}</span>
                          </div>
                        </TableCell>
                        <TableCell>
                          <FundTypeBadge type={r.type} />
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-col items-start gap-1">
                            <FundStatusBadge status={r.status} returned={r.returned} />
                            {r.voidPending && (
                              <StatusChip Icon={Ban} tone="danger">
                                Void requested
                              </StatusChip>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className={cn("text-center font-mono text-xs", r.attachmentCount === 0 && "font-bold text-danger-foreground")}>{r.attachmentCount}</TableCell>
                        <TableCell>
                          <div className="flex items-center -space-x-1.5">
                            <span title={`Recorded by ${r.recordedBy}`}>
                              <Avatar name={r.recordedBy} size={28} ring />
                            </span>
                            {r.approvedBy ? (
                              <span title={`Approved by ${r.approvedBy}`}>
                                <Avatar name={r.approvedBy} size={28} ring />
                              </span>
                            ) : (
                              <span className="inline-flex h-7 w-7 items-center justify-center rounded-full border border-dashed border-input bg-card text-xs text-muted-foreground ring-2 ring-card" title="Not approved yet">
                                ?
                              </span>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="text-right">
                          <span className={cn("money font-semibold", r.status === "void" && "text-muted-foreground line-through")}>−{rm(r.amount)}</span>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
              <ul className="divide-y md:hidden">
                {rows.map((r) => (
                  <li key={r.id}>
                    <Link href={r.url} className="flex items-start gap-3 px-3 py-3 hover:bg-muted/50">
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <span className="font-mono text-[0.8125rem] font-medium">{r.ref}</span>
                          <FundTypeBadge type={r.type} />
                        </div>
                        <div className="mt-1 line-clamp-2 text-sm">{r.description}</div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          <span>{fmtDate(r.paidDate ?? r.invoiceDate)}</span>
                          <span className="inline-flex items-center gap-0.5">
                            <Paperclip className="h-3 w-3" aria-hidden />
                            {r.attachmentCount}
                          </span>
                          <span>by {r.recordedBy.split(" ")[0]}</span>
                        </div>
                      </div>
                      <div className="flex flex-col items-end gap-1.5">
                        <span className="money text-sm font-bold">−{rm(r.amount)}</span>
                        <FundStatusBadge status={r.status} returned={r.returned} />
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
              <div className="flex flex-col gap-2 border-t px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                <span className="text-muted-foreground">
                  {pagination.total} record{pagination.total === 1 ? "" : "s"} · <Money sen={pagination.totalAmount} className="font-semibold text-foreground" />
                </span>
                <div className="flex items-center gap-3">
                  <div className="hidden items-center gap-2 sm:flex">
                    <span className="text-muted-foreground">Rows</span>
                    <Select value={String(filters.per)} onValueChange={(v) => visit({ per: Number(v) })}>
                      <SelectTrigger className="h-8 w-[68px]" aria-label="Rows per page">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {[10, 20, 50].map((n) => (
                          <SelectItem key={n} value={String(n)}>
                            {n}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <span className="font-medium">
                    Page {pagination.page} of {pagination.pages}
                  </span>
                  <div className="flex items-center gap-1">
                    <Button variant="outline" size="icon" className="h-8 w-8" disabled={!pagination.prev} aria-label="Previous page" onClick={() => pagination.prev && router.visit(pagination.prev, { preserveState: true, preserveScroll: true })}>
                      <ChevronLeft />
                    </Button>
                    <Button variant="outline" size="icon" className="h-8 w-8" disabled={!pagination.next} aria-label="Next page" onClick={() => pagination.next && router.visit(pagination.next, { preserveState: true, preserveScroll: true })}>
                      <ChevronRight />
                    </Button>
                  </div>
                </div>
              </div>
            </>
          )}
        </div>
      </section>
      <p className="text-xs text-muted-foreground">
        Only approved records count towards balances, the member card and reports. Drafts, items awaiting approval and void items are excluded.
      </p>
    </AdminLayout>
  );
}
