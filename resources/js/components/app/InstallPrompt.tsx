import { useEffect, useState, useSyncExternalStore } from "react";
import { Share, SquarePlus, X } from "lucide-react";
import { ClubMark } from "@/components/app/MemberShell";
import { useT } from "@/lib/i18n";
import { getInstallPrompt, isIosSafari, isStandalone, promptInstall, subscribeInstallPrompt } from "@/lib/pwa";
import { readPref, writePref } from "@/lib/storage";

const DISMISS_KEY = "cp-install-dismissed";

/**
 * Small, dismissible "Pasang di skrin utama" card for the member home.
 * Chrome/Android: uses the captured beforeinstallprompt event. iOS Safari: short Share → Add to Home Screen steps.
 * Hidden when already running as an installed app, or once dismissed (remembered in localStorage).
 */
export function InstallPrompt() {
  const t = useT();
  const deferred = useSyncExternalStore(subscribeInstallPrompt, getInstallPrompt, () => null);
  const [ios, setIos] = useState(false);
  const [hidden, setHidden] = useState(true);

  useEffect(() => {
    setHidden(isStandalone() || readPref(DISMISS_KEY) === "1");
    setIos(isIosSafari());
  }, []);

  if (hidden || (!deferred && !ios)) return null;

  const dismiss = () => {
    writePref(DISMISS_KEY, "1");
    setHidden(true);
  };
  const install = async () => {
    if (await promptInstall()) setHidden(true);
  };

  return (
    <section aria-labelledby="install-h" className="relative rounded-[20px] border bg-card p-4">
      <button
        type="button"
        onClick={dismiss}
        aria-label={t("installClose")}
        className="absolute right-1.5 top-1.5 inline-flex h-11 w-11 items-center justify-center rounded-full text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <X className="h-5 w-5" aria-hidden />
      </button>
      <div className="flex items-start gap-3 pr-10">
        <ClubMark />
        <div className="min-w-0">
          <h2 id="install-h" className="font-display text-lg font-bold leading-tight tracking-tight">
            {t("installTitle")}
          </h2>
          <p className="mt-1 text-base text-muted-foreground">{t("installBody")}</p>
        </div>
      </div>
      {deferred ? (
        <div className="mt-3 flex flex-wrap gap-2">
          <button
            type="button"
            onClick={install}
            className="inline-flex h-11 items-center justify-center gap-2 rounded-full bg-primary px-5 text-base font-bold text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
          >
            <SquarePlus className="h-5 w-5" aria-hidden /> {t("installAction")}
          </button>
          <button
            type="button"
            onClick={dismiss}
            className="inline-flex h-11 items-center justify-center rounded-full px-4 text-base font-semibold text-primary hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {t("installLater")}
          </button>
        </div>
      ) : (
        <ol className="mt-3 flex flex-col gap-2 text-base">
          <li className="flex items-center gap-2">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
              <Share className="h-4 w-4" aria-hidden />
            </span>
            {t("installIosStep1")}
          </li>
          <li className="flex items-center gap-2">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
              <SquarePlus className="h-4 w-4" aria-hidden />
            </span>
            {t("installIosStep2")}
          </li>
        </ol>
      )}
    </section>
  );
}
