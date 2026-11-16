import { Head, usePage } from "@inertiajs/react";
import { Info } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Input, Label } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { CategoryBar, CollectedSpentChart } from "@/components/app/Charts";
import { LangToggle } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { FundStatusBadge, PaymentStatusBadge } from "@/components/app/StatusBadges";
import { ThemeToggle } from "@/components/app/ThemeToggle";
import { useLang, useT } from "@/lib/i18n";
import type { CategorySlice, MonthPoint } from "@/types/domain";

/** Static sample values: this page only proves the front-end wiring (tokens, Radix, Recharts, CSP). */
const sampleMonths: MonthPoint[] = [
  { month: "Jan", monthEn: "Jan", collected: 395000, spent: 31000 },
  { month: "Feb", monthEn: "Feb", collected: 388000, spent: 28500 },
  { month: "Mac", monthEn: "Mar", collected: 402000, spent: 42000 },
  { month: "Apr", monthEn: "Apr", collected: 391000, spent: 36500 },
  { month: "Mei", monthEn: "May", collected: 384000, spent: 298000 },
  { month: "Jun", monthEn: "Jun", collected: 396000, spent: 54000 },
];

const sampleSlices: CategorySlice[] = [
  { key: "events", bm: "Majlis", en: "Events", amount: 420000, colorVar: "--chart-1", onVar: "--on-chart-1" },
  { key: "welfare", bm: "Kebajikan", en: "Welfare", amount: 300000, colorVar: "--chart-2", onVar: "--on-chart-2" },
  { key: "sports", bm: "Sukan", en: "Sports", amount: 180000, colorVar: "--chart-3", onVar: "--on-chart-3" },
  { key: "other", bm: "Lain-lain", en: "Other", amount: 90000, colorVar: "--chart-4", onVar: "--on-chart-4" },
];

export default function UiKit() {
  const { locale, club } = usePage().props;
  const { lang } = useLang();
  const t = useT();
  return (
    <>
      <Head title="UI kit" />
      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 py-8">
        <header className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="font-display text-3xl font-extrabold tracking-tight">UI kit</h1>
            <p className="text-sm text-muted-foreground">
              Inertia + React wiring check. Shared locale: <span className="font-mono">{locale}</span> · club:{" "}
              <span className="font-mono">{club ? club.name : "none"}</span>
            </p>
          </div>
          <div className="flex items-center gap-2">
            <LangToggle />
            <ThemeToggle withLabels lang={lang} />
          </div>
        </header>

        <Alert variant="info">
          <Info className="h-4 w-4" />
          <AlertTitle>Dev only</AlertTitle>
          <AlertDescription>This route exists only in the local and testing environments.</AlertDescription>
        </Alert>

        <div className="grid gap-6 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Buttons & badges</CardTitle>
              <CardDescription>Variants from components/ui.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <div className="flex flex-wrap gap-2">
                <Button>{t("payNow")}</Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="outline">Outline</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="destructive">Destructive</Button>
                <Dialog>
                  <DialogTrigger asChild>
                    <Button variant="outline">Open dialog</Button>
                  </DialogTrigger>
                  <DialogContent>
                    <DialogHeader>
                      <DialogTitle>Radix dialog</DialogTitle>
                      <DialogDescription>Opening this locks page scroll; Radix injects a nonce-tagged style for it.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                      <DialogClose asChild>
                        <Button>OK</Button>
                      </DialogClose>
                    </DialogFooter>
                  </DialogContent>
                </Dialog>
              </div>
              <div className="flex flex-wrap gap-2">
                <Badge>Default</Badge>
                <Badge variant="success">Success</Badge>
                <Badge variant="warning">Warning</Badge>
                <Badge variant="danger">Danger</Badge>
                <Badge variant="info">Info</Badge>
              </div>
              <div className="flex flex-wrap gap-2">
                <PaymentStatusBadge status="paid" label={t("paid")} />
                <PaymentStatusBadge status="due" label={t("statusDue")} />
                <PaymentStatusBadge status="processing" label={t("statusProcessing")} />
                <PaymentStatusBadge status="overdue" label={t("overdueBadge")} />
                <PaymentStatusBadge status="verifying" label={t("verifyingBadge")} />
                <FundStatusBadge status="submitted" />
              </div>
              <div className="text-2xl font-bold">
                <Money sen={123450} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Tabs & form</CardTitle>
              <CardDescription>Radix tabs and the input primitive.</CardDescription>
            </CardHeader>
            <CardContent>
              <Tabs defaultValue="one">
                <TabsList>
                  <TabsTrigger value="one">{t("navHome")}</TabsTrigger>
                  <TabsTrigger value="two">{t("navPayments")}</TabsTrigger>
                </TabsList>
                <TabsContent value="one" className="flex flex-col gap-2 pt-4">
                  <Label htmlFor="uikit-email">{t("email")}</Label>
                  <Input id="uikit-email" type="email" placeholder="nama@contoh.my" />
                </TabsContent>
                <TabsContent value="two" className="pt-4 text-sm text-muted-foreground">
                  {t("historySub")}
                </TabsContent>
              </Tabs>
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>{t("monthlyTitle")}</CardTitle>
            <CardDescription>Recharts with theme tokens (sample values).</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-6">
            <CollectedSpentChart data={sampleMonths} year={2026} lang={lang} height={240} />
            <CategoryBar slices={sampleSlices} lang={lang} wideLegend />
          </CardContent>
        </Card>
      </main>
    </>
  );
}
