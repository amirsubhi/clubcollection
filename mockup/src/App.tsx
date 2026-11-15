import { useEffect, useState, type ReactNode } from "react";
import { TooltipProvider } from "@/components/ui/tooltip";
import { PhoneFrame } from "@/components/app/PhoneFrame";
import { LangContext, type Lang } from "@/i18n";
import { readPref, writePref } from "@/lib/storage";
import { ThemeProvider } from "@/lib/theme";
import { screenByHash } from "@/routes";
import { Overview } from "@/screens/Overview";
import { MemberHome } from "@/screens/member/MemberHome";
import { MemberInvoice } from "@/screens/member/MemberInvoice";
import { MemberPay } from "@/screens/member/MemberPay";
import { PayResult } from "@/screens/member/PayResult";
import { MemberHistory } from "@/screens/member/MemberHistory";
import { MemberReceipt } from "@/screens/member/MemberReceipt";
import { MemberFund } from "@/screens/member/MemberFund";
import { MemberAccount } from "@/screens/member/MemberAccount";
import { FundUsageList } from "@/screens/admin/FundUsageList";
import { AdminDashboard } from "@/screens/admin/AdminDashboard";
import { FundForm } from "@/screens/admin/FundForm";
import { FundItemDetail } from "@/screens/admin/FundItemDetail";
import { FundApproveMobile } from "@/screens/admin/FundApproveMobile";
import { AgmReport } from "@/screens/reports/AgmReport";
import { Questions } from "@/screens/reports/Questions";

function currentHash(): string {
  const h = window.location.hash.replace(/^#/, "");
  return screenByHash[h] ? h : "overview";
}

function useDesktop() {
  const q = "(min-width: 768px)";
  const [m, setM] = useState(() => window.matchMedia(q).matches);
  useEffect(() => {
    const mq = window.matchMedia(q);
    const on = () => setM(mq.matches);
    mq.addEventListener("change", on);
    return () => mq.removeEventListener("change", on);
  }, []);
  return m;
}

export default function App() {
  const [hash, setHash] = useState(currentHash);
  const [lang, setLangState] = useState<Lang>(() => (readPref("cp-lang") === "en" ? "en" : "bm"));
  const [phoneFrame, setPhoneFrameState] = useState(() => readPref("cp-phone-frame") !== "off");
  const desktop = useDesktop();

  useEffect(() => {
    const on = () => {
      setHash(currentHash());
      window.scrollTo(0, 0);
      document.getElementById("phone-scroll")?.scrollTo(0, 0);
    };
    window.addEventListener("hashchange", on);
    return () => window.removeEventListener("hashchange", on);
  }, []);

  useEffect(() => {
    const s = screenByHash[hash];
    document.title = s && hash !== "overview" ? `${s.title} · Club Portal Mockup` : "Club Portal Mockup";
  }, [hash]);

  const setLang = (l: Lang) => {
    setLangState(l);
    writePref("cp-lang", l);
  };
  const setPhoneFrame = (v: boolean) => {
    setPhoneFrameState(v);
    writePref("cp-phone-frame", v ? "on" : "off");
  };

  let view: ReactNode;
  switch (hash) {
    case "member-home": view = <MemberHome />; break;
    case "member-invoice": view = <MemberInvoice />; break;
    case "member-pay": view = <MemberPay />; break;
    case "pay-success": view = <PayResult outcome="success" />; break;
    case "pay-pending": view = <PayResult outcome="pending" />; break;
    case "pay-failed": view = <PayResult outcome="failed" />; break;
    case "member-history": view = <MemberHistory />; break;
    case "member-receipt": view = <MemberReceipt />; break;
    case "member-fund": view = <MemberFund />; break;
    case "member-account": view = <MemberAccount />; break;
    case "admin-dashboard": view = <AdminDashboard />; break;
    case "fund-usage": view = <FundUsageList />; break;
    case "fund-new": view = <FundForm key="e" type="expense" />; break;
    case "fund-new-errors": view = <FundForm key="err" type="expense" showErrors />; break;
    case "welfare-new": view = <FundForm key="w" type="welfare" />; break;
    case "fund-item": view = <FundItemDetail />; break;
    case "fund-approve": view = <FundApproveMobile />; break;
    case "agm-report": view = <AgmReport />; break;
    case "questions": view = <Questions />; break;
    default: view = <Overview phoneFrame={phoneFrame} setPhoneFrame={setPhoneFrame} />;
  }

  const area = screenByHash[hash]?.area;
  const framed = desktop && phoneFrame && (area === "member" || hash === "fund-approve");
  const shell = (
    <div className="app-shell" key={hash}>
      {view}
    </div>
  );

  return (
    <ThemeProvider>
      <LangContext.Provider value={{ lang, setLang }}>
        <TooltipProvider delayDuration={200}>
          {framed ? <PhoneFrame onExit={() => setPhoneFrame(false)}>{shell}</PhoneFrame> : shell}
        </TooltipProvider>
      </LangContext.Provider>
    </ThemeProvider>
  );
}
