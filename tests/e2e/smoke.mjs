#!/usr/bin/env node
// End-to-end smoke test for Option A (member pay flow + Fund Usage maker-checker + transfer slip verification).
// See tests/e2e/README.md for the database and server set-up. Exits non-zero on failure.
import { createRequire } from "node:module";
import { createHmac } from "node:crypto";
import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { deflateSync } from "node:zlib";

const require = createRequire(import.meta.url);
const { chromium, request: pwRequest } = require("playwright");

const BASE = (process.env.E2E_BASE_URL || "http://127.0.0.1:8000").replace(/\/$/, "");
const OUT = process.env.E2E_OUT_DIR || join(tmpdir(), "clubportal-e2e-shots");
const SECRET = process.env.E2E_WEBHOOK_SECRET || "e2e-webhook-secret-local-only-0123456789"; // >= 32 characters
const PASSWORD = process.env.E2E_PASSWORD || "E2e-Smoke-Pass-2026"; // Database\Seeders\E2eSeeder::PASSWORD
const USERS = {
  member: "e2e.member@clubportal.test",
  transferMember: "e2e.member.transfer@clubportal.test",
  payAll: "e2e.member.payall@clubportal.test",
  payAllTransfer: "e2e.member.payall.transfer@clubportal.test",
  adminA: "e2e.admin.a@clubportal.test",
  adminB: "e2e.admin.b@clubportal.test",
};
const GATEWAY_HOST = "dev.toyyibpay.com"; // TOYYIBPAY_SANDBOX=true
// Fonts, Bootstrap and Chart.js are served from public/build: a request to one of these hosts fails the run.
const THIRD_PARTY_ASSET_HOSTS = ["fonts.googleapis.com", "fonts.gstatic.com", "cdn.jsdelivr.net"];
// Hosts whose console errors and failed requests are ignored (none by default; comma-separated).
const IGNORED_HOSTS = (process.env.E2E_IGNORE_HOSTS || "").split(",").filter(Boolean);
const EXPENSE_SEN = 1234; // RM 12.34

mkdirSync(OUT, { recursive: true });
const failures = [];
const log = (...a) => console.log("•", ...a);
const fail = (msg) => {
  failures.push(msg);
  console.error("✗", msg);
};
const isIgnored = (url) => {
  try {
    return IGNORED_HOSTS.includes(new URL(url).hostname);
  } catch {
    return false;
  }
};

/* ---------- tiny fixture files (valid PNG + PDF) ---------- */
function crc32(buf) {
  let c,
    crc = 0xffffffff;
  for (const b of buf) {
    c = (crc ^ b) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function pngChunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
function makePng(w = 16, h = 16) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // RGB
  const rows = [];
  for (let y = 0; y < h; y++) {
    rows.push(0);
    for (let x = 0; x < w; x++) rows.push((x * 16) & 255, (y * 16) & 255, 160);
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    pngChunk("IHDR", ihdr),
    pngChunk("IDAT", deflateSync(Buffer.from(rows))),
    pngChunk("IEND", Buffer.alloc(0)),
  ]);
}
const PDF = `%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj
trailer<</Root 1 0 R>>
%%EOF
`;
const fixtures = join(OUT, "fixtures");
mkdirSync(fixtures, { recursive: true });
const pngPath = join(fixtures, "e2e-receipt.png");
const pdfPath = join(fixtures, "e2e-invoice.pdf");
writeFileSync(pngPath, makePng());
writeFileSync(pdfPath, PDF);

/* ---------- page guards: console errors, CSP violations, overflow ---------- */
function guard(page, label) {
  page.on("console", (msg) => {
    if (msg.type() !== "error") return;
    const url = msg.location()?.url || "";
    if (isIgnored(url) || IGNORED_HOSTS.some((h) => msg.text().includes(h))) return;
    // Inertia's external redirect (Inertia::location) is a 409 + X-Inertia-Location by design; Chrome logs it.
    if (/status of 409/.test(msg.text()) && /\/my\/(payments\/\d+\/pay|clubs\/\d+\/pay-all)$/.test(url)) return;
    fail(`[${label}] console error on ${page.url()}: ${msg.text()} ${url}`);
  });
  page.on("pageerror", (err) => fail(`[${label}] uncaught error on ${page.url()}: ${err.message}`));
  page.on("request", (req) => {
    let host = "";
    try {
      host = new URL(req.url()).hostname;
    } catch {}
    if (THIRD_PARTY_ASSET_HOSTS.includes(host)) fail(`[${label}] third-party asset request on ${page.url()}: ${req.url()}`);
  });
  page.on("requestfailed", (req) => {
    const err = req.failure()?.errorText || "";
    if (isIgnored(req.url()) || err.includes("ERR_ABORTED")) return;
    fail(`[${label}] request failed: ${req.method()} ${req.url()} ${err}`);
  });
}
const CSP_PROBE = () => {
  document.addEventListener("securitypolicyviolation", (e) => {
    const w = window;
    w.__cspViolations = w.__cspViolations || [];
    w.__cspViolations.push(`${e.violatedDirective} blocked ${e.blockedURI || "(inline)"}`);
  });
};
async function checkPage(page, label) {
  const csp = await page.evaluate(() => window.__cspViolations || []).catch(() => []);
  for (const v of csp) fail(`[${label}] CSP violation on ${page.url()}: ${v}`);
  await page.evaluate(() => (window.__cspViolations = [])).catch(() => {});
  const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
  if (o.sw > o.cw + 1) fail(`[${label}] horizontal overflow on ${page.url()}: scrollWidth ${o.sw} > ${o.cw}`);
}
async function shot(page, name) {
  for (const scheme of ["light", "dark"]) {
    await page.emulateMedia({ colorScheme: scheme });
    await page.waitForTimeout(250);
    await checkPage(page, `${name}/${scheme}`);
    await page.screenshot({ path: join(OUT, `${name}-${scheme}.png`), fullPage: true, animations: "disabled" });
  }
  await page.emulateMedia({ colorScheme: "light" });
  log("screenshot", name);
}

async function newPage(browser, label, viewport, mobile) {
  const ctx = await browser.newContext({
    viewport,
    isMobile: mobile,
    hasTouch: mobile,
    serviceWorkers: "block", // keep page.route() interception and screenshots deterministic
    colorScheme: "light",
    locale: "ms-MY",
  });
  await ctx.addInitScript(CSP_PROBE);
  const page = await ctx.newPage();
  page.setDefaultTimeout(15000);
  guard(page, label);
  return { ctx, page };
}
let logins = 0;
async function login(page, email) {
  // POST /login allows 5 a minute per account + IP (30 per IP); the run signs in often, so pace it to stay clear.
  if (++logins % 5 === 0) {
    log("waiting 61 s for the login rate limit");
    await new Promise((r) => setTimeout(r, 61000));
  }
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForURL((u) => !u.pathname.startsWith("/login")), page.click('button[type="submit"]')]);
  if (page.url().includes("/login")) throw new Error(`login failed for ${email}`);
}
const parseRm = (s) => Math.round(parseFloat(String(s).replace(/[^0-9.]/g, "")) * 100);
async function spentThisYear(page) {
  const tile = page.locator("div.rounded-lg.border", { has: page.getByText("Spent", { exact: true }) }).first();
  return parseRm(await tile.locator(".figure").innerText());
}
async function step(name, fn) {
  log(`step: ${name}`);
  try {
    await fn();
  } catch (e) {
    fail(`${name}: ${e.message.split("\n").slice(0, 3).join(" | ")}`);
    throw e;
  }
}

const MEMBER_VIEW = { width: 390, height: 844 };
const ADMIN_VIEW = { width: 1366, height: 860 };

const browser = await chromium.launch({ args: ["--no-sandbox"] });
try {
  /* (a) member pays a fee through the (faked) gateway */
  const m = await newPage(browser, "member", MEMBER_VIEW, true);
  let paymentId, billCode;
  await step("member logs in and sees the amount due", async () => {
    await login(m.page, USERS.member);
    await m.page.waitForURL(/\/my\/dashboard/);
    await m.page.getByText("Jumlah perlu dibayar").first().waitFor();
    await m.page.getByText("RM 50.00").first().waitFor();
    await shot(m.page, "a1-member-home");
  });
  await step("Review & pay", async () => {
    await m.page.getByRole("link", { name: /Bayar\s+RM 50\.00/ }).first().click();
    await m.page.waitForURL(/\/my\/payments\/\d+\/review/);
    paymentId = m.page.url().match(/payments\/(\d+)\/review/)[1];
    await shot(m.page, "a2-review-pay");
  });
  await step("POST pay redirects to the gateway", async () => {
    await m.page.route(`https://${GATEWAY_HOST}/**`, (route) =>
      route.fulfill({ contentType: "text/html", body: "<!doctype html><title>Fake gateway</title><meta name=viewport content='width=device-width'><h1>Fake ToyyibPay (e2e)</h1>" }),
    );
    await Promise.all([m.page.waitForURL(new RegExp(`^https://${GATEWAY_HOST.replace(/\./g, "\\.")}/`)), m.page.getByRole("button", { name: /Bayar\s+RM 50\.00/ }).click()]);
    billCode = decodeURIComponent(new URL(m.page.url()).pathname.slice(1));
    if (!/^e2e[a-z0-9]{8}$/.test(billCode)) throw new Error(`unexpected bill code "${billCode}" (is E2E_FAKE_GATEWAY=1 set on the server?)`);
    log("redirected to gateway with bill", billCode);
    await shot(m.page, "a3-gateway");
  });
  let reference;
  await step("PayResult shows the bill reference while pending", async () => {
    await m.page.unroute(`https://${GATEWAY_HOST}/**`);
    await m.page.goto(`${BASE}/my/payments/${paymentId}/thankyou`);
    reference = await m.page.evaluate(() => JSON.parse(document.querySelector("[data-page]").getAttribute("data-page")).props.reference);
    if (!/^PAY-\d+-\d+$/.test(reference || "")) throw new Error(`unexpected bill reference "${reference}"`);
  });
  await step("gateway webhook marks the fee paid", async () => {
    const api = await pwRequest.newContext();
    // ToyyibPay sends the bill reference as order_id; the callback token is a per-bill HMAC of it.
    const form = { billcode: billCode, order_id: reference, status_id: "1", amount: "50.00", transaction_id: "E2E-TXN-0001", msg: "ok" };
    const token = createHmac("sha256", SECRET).update(reference).digest("hex");
    const bad = await api.post(`${BASE}/webhook/toyyibpay?webhook_token=wrong-token`, { form });
    if (bad.status() !== 403) throw new Error(`webhook with a wrong token returned ${bad.status()}, expected 403`);
    const global = await api.post(`${BASE}/webhook/toyyibpay?webhook_token=${encodeURIComponent(SECRET)}`, { form });
    if (global.status() !== 403) throw new Error(`webhook with the raw secret as token returned ${global.status()}, expected 403`);
    const res = await api.post(`${BASE}/webhook/toyyibpay?webhook_token=${token}`, { form });
    const body = await res.text();
    if (res.status() !== 200 || body.trim() !== "ok") throw new Error(`webhook returned ${res.status()} ${body.slice(0, 80)}`);
    await api.dispose();
  });
  await step("PayResult shows Bayaran berjaya", async () => {
    await m.page.goto(`${BASE}/my/payments/${paymentId}/thankyou?status_id=1&billcode=${encodeURIComponent(billCode)}`);
    await m.page.getByText("Bayaran berjaya", { exact: true }).first().waitFor();
    await shot(m.page, "a4-pay-result");
    await m.page.goto(`${BASE}/my/dashboard`);
    await m.page.getByText("Semua yuran telah dibayar").first().waitFor();
    await shot(m.page, "a5-member-home-paid");
  });
  await m.ctx.close();

  /* (b) admin A records an expense with 2 documents and submits it */
  const a = await newPage(browser, "adminA", ADMIN_VIEW, false);
  let clubId, showUrl, spentBefore;
  await step("admin A opens the dashboard", async () => {
    await login(a.page, USERS.adminA);
    const html = await a.page.content();
    clubId = html.match(/\/admin\/clubs\/(\d+)\//)?.[1];
    if (!clubId) throw new Error("club id not found on /home");
    await a.page.goto(`${BASE}/admin/clubs/${clubId}/dashboard`);
    spentBefore = await spentThisYear(a.page);
    log("spent this year before:", spentBefore / 100);
    await shot(a.page, "b1-admin-dashboard-before");
  });
  await step("admin A opens Fund Usage", async () => {
    await a.page.goto(`${BASE}/admin/clubs/${clubId}/fund-usage`);
    await a.page.waitForLoadState("networkidle");
    await shot(a.page, "b2-fund-usage");
  });
  await step("admin A creates an expense with PNG + PDF and submits", async () => {
    await a.page.goto(`${BASE}/admin/clubs/${clubId}/fund-usage/create`);
    await a.page.locator("#category").click();
    await a.page.getByRole("option", { name: "Supplies" }).click();
    await a.page.fill("#payee", "Kedai E2E Sdn Bhd");
    await a.page.fill("#desc", "E2E smoke: shuttlecocks");
    await a.page.fill("#amount", (EXPENSE_SEN / 100).toFixed(2));
    await a.page.fill("#invoiceNo", "E2E-INV-1");
    await a.page.locator('input[type="file"][multiple]').setInputFiles([pngPath, pdfPath]);
    for (const [file, kind] of [["e2e-receipt.png", "Receipt"], ["e2e-invoice.pdf", "Invoice"]]) {
      await a.page.getByLabel(`Kind of ${file}`).click();
      await a.page.getByRole("option", { name: kind, exact: true }).click();
    }
    await shot(a.page, "b3-create-filled");
    await Promise.all([a.page.waitForURL(/\/admin\/fund-usage\/\d+$/), a.page.getByRole("button", { name: "Submit for approval" }).click()]);
    showUrl = a.page.url();
    await a.page.getByText("submitted for approval").first().waitFor();
    for (const f of ["e2e-receipt.png", "e2e-invoice.pdf"]) await a.page.getByText(f).first().waitFor();
    await shot(a.page, "b4-submitted");
  });
  await a.ctx.close();

  /* (c) admin B approves with the declaration; dashboard total moves */
  const b = await newPage(browser, "adminB", ADMIN_VIEW, false);
  await step("admin B approves with the declaration", async () => {
    await login(b.page, USERS.adminB);
    await b.page.goto(showUrl);
    await shot(b.page, "c1-review");
    await b.page.getByRole("button", { name: /^Approve/ }).first().click();
    const dialog = b.page.getByRole("dialog");
    await dialog.locator("#coi").click();
    await shot(b.page, "c2-approve-dialog");
    await dialog.getByRole("button", { name: /^Approve/ }).click();
    await dialog.waitFor({ state: "detached" });
    await b.page.getByText(/^Approved \d/).first().waitFor();
    await shot(b.page, "c3-approved");
  });
  await step("dashboard spent increases by the expense amount", async () => {
    await b.page.goto(`${BASE}/admin/clubs/${clubId}/dashboard`);
    const after = await spentThisYear(b.page);
    log("spent this year after:", after / 100);
    if (after - spentBefore !== EXPENSE_SEN) throw new Error(`spent went ${spentBefore / 100} → ${after / 100}, expected +${EXPENSE_SEN / 100}`);
    await shot(b.page, "c4-admin-dashboard-after");
  });
  await step("ledger closing balance matches the dashboard fund balance", async () => {
    const tile = (label) => b.page.locator("div.rounded-lg.border", { has: b.page.getByText(label, { exact: true }) }).first();
    const balance = parseRm(await tile("Fund balance").locator(".figure").innerText());
    await b.page.getByRole("link", { name: "Ledger", exact: true }).first().click();
    await b.page.getByRole("heading", { name: "Ledger", level: 1 }).waitFor();
    const closing = parseRm(await tile("Closing balance").locator(".figure").innerText());
    log("fund balance:", balance / 100, "ledger closing:", closing / 100);
    if (closing !== balance) throw new Error(`ledger closing ${closing / 100} != dashboard balance ${balance / 100}`);
    await b.page.getByRole("button", { name: "Show as chart" }).click();
    await b.page.locator(".recharts-bar-rectangle path").first().waitFor({ state: "attached" });
    await shot(b.page, "c5-ledger");
  });
  await step("billing settings open as an app page", async () => {
    await b.page.getByRole("link", { name: "Billing settings", exact: true }).first().click();
    await b.page.getByRole("heading", { name: "Billing settings", level: 1 }).waitFor();
    await b.page.getByRole("heading", { name: "Plans members can choose" }).waitFor();
    await shot(b.page, "c6-billing");
  });
  await b.ctx.close();

  /* (d) a second member pays by bank transfer and uploads a slip */
  const d = await newPage(browser, "transferMember", MEMBER_VIEW, true);
  let transferPaymentId;
  await step("transfer member opens Review & pay and picks bank transfer / DuitNow", async () => {
    await login(d.page, USERS.transferMember);
    await d.page.waitForURL(/\/my\/dashboard/);
    await d.page.getByRole("link", { name: /Bayar\s+RM 50\.00/ }).first().click();
    await d.page.waitForURL(/\/my\/payments\/\d+\/review/);
    transferPaymentId = d.page.url().match(/payments\/(\d+)\/review/)[1];
    await d.page.getByLabel(/Pindahan bank \/ DuitNow/).check();
    await d.page.getByText("Bank Contoh").first().waitFor();
    const ref = await d.page.getByTestId("transfer-ref").innerText();
    if (ref.trim() !== `YURAN-${transferPaymentId}`) throw new Error(`reference shown is "${ref}", expected YURAN-${transferPaymentId}`);
    await shot(d.page, "d1-review-transfer");
  });
  await step("transfer member uploads a slip and sees Menunggu pengesahan", async () => {
    await d.page.locator('input[type="file"][multiple]').setInputFiles([pngPath]);
    await d.page.fill("#transfer_ref", "E2E-BANK-REF-1");
    await shot(d.page, "d2-review-transfer-filled");
    await Promise.all([d.page.waitForURL(/\/my\/payments\/\d+\/invoice/), d.page.getByRole("button", { name: /Hantar slip/ }).click()]);
    await d.page.getByText("Menunggu pengesahan").first().waitFor();
    await d.page.getByTestId("invoice-verifying").waitFor();
    await shot(d.page, "d3-invoice-verifying");
    // Also the real 390×844 viewport (full-page shots can't show the sticky header over content).
    await d.page.screenshot({ path: join(OUT, "d3-invoice-verifying-viewport.png"), animations: "disabled" });
    await d.page.goto(`${BASE}/my/payments/${transferPaymentId}/review`);
    await d.page.getByTestId("verifying").waitFor();
    await shot(d.page, "d4-review-verifying");
    await d.page.goto(`${BASE}/my/dashboard`);
    await d.page.getByText("Menunggu pengesahan").first().waitFor();
    await shot(d.page, "d5-member-home-verifying");
  });

  /* (e) admin B approves the slip in Verifications */
  const e = await newPage(browser, "adminB-verify", ADMIN_VIEW, false);
  await step("admin B approves the slip in Verifications", async () => {
    await login(e.page, USERS.adminB);
    await e.page.goto(`${BASE}/admin/clubs/${clubId}/verifications`);
    await e.page.getByTestId("slip-card").first().waitFor();
    await e.page.getByText("E2E Ahli Pindahan").first().waitFor();
    await shot(e.page, "e1-verifications");
    await e.page.getByTestId("slip-card").first().getByRole("button", { name: /^Approve/ }).click();
    const dialog = e.page.getByRole("dialog");
    await dialog.getByLabel(/Bank reference/).fill("E2E-STMT-0001");
    await shot(e.page, "e2-approve-slip-dialog");
    await dialog.getByRole("button", { name: /^Approve/ }).click();
    await dialog.waitFor({ state: "detached" });
    await e.page.getByText(/^Slip approved\./).first().waitFor();
    await e.page.getByRole("button", { name: /Recently handled/ }).click();
    await e.page.getByText("Approved", { exact: true }).first().waitFor();
    await shot(e.page, "e3-verifications-handled");
  });
  await e.ctx.close();

  await step("transfer member's invoice now shows the receipt", async () => {
    await d.page.goto(`${BASE}/my/payments/${transferPaymentId}/invoice`);
    await d.page.getByText("Resit rasmi").first().waitFor();
    await d.page.getByText("Pindahan bank / DuitNow").first().waitFor();
    await shot(d.page, "d6-receipt-transfer");
  });
  await d.ctx.close();

  /* (f) "Bayar semua" by FPX: three open fees, one bill, one webhook settles all */
  const f = await newPage(browser, "payAllMember", MEMBER_VIEW, true);
  let groupResultUrl, groupBillCode;
  await step("pay-all member sees Bayar semua on Home", async () => {
    await login(f.page, USERS.payAll);
    await f.page.waitForURL(/\/my\/dashboard/);
    const btn = f.page.getByTestId("pay-all");
    await btn.waitFor();
    if (!/Bayar semua\s+RM 150\.00/.test(await btn.innerText())) throw new Error(`pay-all button says "${await btn.innerText()}"`);
    await f.page.getByTestId("pay-one").waitFor();
    await shot(f.page, "f1-home-pay-all");
  });
  await step("pay-all review lists the three fees and the total", async () => {
    await f.page.getByTestId("pay-all").click();
    await f.page.waitForURL(/\/my\/clubs\/\d+\/pay-all$/);
    const n = await f.page.getByTestId("group-fees").locator("li").count();
    if (n !== 3) throw new Error(`review lists ${n} fees, expected 3`);
    await f.page.getByTestId("review-total").getByText("RM 150.00").waitFor();
    await shot(f.page, "f2-review-pay-all-fpx");
  });
  await step("POST pay-all redirects to one gateway bill", async () => {
    await f.page.route(`https://${GATEWAY_HOST}/**`, (route) =>
      route.fulfill({ contentType: "text/html", body: "<!doctype html><title>Fake gateway</title><meta name=viewport content='width=device-width'><h1>Fake ToyyibPay (e2e)</h1>" }),
    );
    await Promise.all([f.page.waitForURL(new RegExp(`^https://${GATEWAY_HOST.replace(/\./g, "\\.")}/`)), f.page.getByRole("button", { name: /Bayar\s+RM 150\.00/ }).click()]);
    groupBillCode = decodeURIComponent(new URL(f.page.url()).pathname.slice(1));
    if (!/^e2e[a-z0-9]{8}$/.test(groupBillCode)) throw new Error(`unexpected group bill code "${groupBillCode}"`);
    await f.page.unroute(`https://${GATEWAY_HOST}/**`);
    // Back on Home, the started payment is shown as in progress (R2a): checking it is the main action,
    // "Bayar semua" is gone, and paying again is the secondary choice.
    await f.page.goto(`${BASE}/my/dashboard`);
    await f.page.getByTestId("check-status").waitFor();
    await f.page.getByTestId("processing-line").getByText("3 dalam proses").waitFor();
    if (await f.page.getByTestId("pay-all").count()) throw new Error("Bayar semua is still offered while the group bill is in progress");
    groupResultUrl = await f.page.getByTestId("check-status").getAttribute("href");
    if (!/\/my\/pay-all\/\d+$/.test(groupResultUrl || "")) throw new Error(`check-status points to "${groupResultUrl}"`);
    await shot(f.page, "f2b-home-in-progress");
  });
  await step("one webhook settles all three fees", async () => {
    await f.page.goto(groupResultUrl);
    const reference = await f.page.evaluate(() => JSON.parse(document.querySelector("[data-page]").getAttribute("data-page")).props.reference);
    if (!/^PAYG-\d+-\d+$/.test(reference || "")) throw new Error(`unexpected group reference "${reference}"`);
    await shot(f.page, "f3-pay-all-pending");
    const api = await pwRequest.newContext();
    const form = { billcode: groupBillCode, order_id: reference, status_id: "1", amount: "150.00", transaction_id: "E2E-TXN-GROUP-1", msg: "ok" };
    const bad = await api.post(`${BASE}/webhook/toyyibpay?webhook_token=wrong-token`, { form });
    if (bad.status() !== 403) throw new Error(`group webhook with a wrong token returned ${bad.status()}, expected 403`);
    const token = createHmac("sha256", SECRET).update(reference).digest("hex");
    const res = await api.post(`${BASE}/webhook/toyyibpay?webhook_token=${token}`, { form });
    if (res.status() !== 200 || (await res.text()).trim() !== "ok") throw new Error(`group webhook returned ${res.status()}`);
    await api.dispose();
    await f.page.goto(`${groupResultUrl}?status_id=1&billcode=${encodeURIComponent(groupBillCode)}`);
    await f.page.getByText("Bayaran berjaya", { exact: true }).first().waitFor();
    await f.page.getByText("3 yuran dibayar sekali gus").first().waitFor();
    const lines = await f.page.locator('article[aria-label="Resit rasmi"] tbody tr').count();
    if (lines !== 4) throw new Error(`receipt has ${lines - 1} fee lines, expected 3`);
    await shot(f.page, "f4-pay-all-receipt");
    await f.page.goto(`${BASE}/my/dashboard`);
    await f.page.getByText("Semua yuran telah dibayar").first().waitFor();
  });
  await f.ctx.close();

  /* (g) "Bayar semua" by transfer: one slip, the admin approves the whole set */
  const g = await newPage(browser, "payAllTransfer", MEMBER_VIEW, true);
  await step("pay-all transfer member sends one slip for three fees", async () => {
    await login(g.page, USERS.payAllTransfer);
    await g.page.getByTestId("pay-all").click();
    await g.page.waitForURL(/\/my\/clubs\/\d+\/pay-all$/);
    await g.page.getByLabel(/Pindahan bank \/ DuitNow/).check();
    const ref = (await g.page.getByTestId("transfer-ref").innerText()).trim();
    if (!/^YURAN-G\d+$/.test(ref)) throw new Error(`group transfer reference is "${ref}"`);
    await g.page.getByText("Pindahkan RM 150.00 ke akaun ini").first().waitFor();
    await shot(g.page, "g1-review-pay-all-transfer");
    await g.page.locator('input[type="file"][multiple]').setInputFiles([pngPath]);
    await Promise.all([g.page.waitForURL(/\/my\/pay-all\/\d+$/), g.page.getByRole("button", { name: /Hantar slip/ }).click()]);
    await g.page.getByTestId("verifying").waitFor();
    await shot(g.page, "g2-pay-all-verifying");
  });
  const h = await newPage(browser, "adminB-group", ADMIN_VIEW, false);
  await step("admin B approves the group slip as one set", async () => {
    await login(h.page, USERS.adminB);
    await h.page.goto(`${BASE}/admin/clubs/${clubId}/verifications`);
    const card = h.page.getByTestId("slip-card").filter({ has: h.page.getByTestId("slip-group") }).first();
    await card.waitFor();
    const rows = await card.getByTestId("slip-group").locator("tbody tr").count();
    if (rows !== 3) throw new Error(`group card lists ${rows} fees, expected 3`);
    await card.getByText("RM 150.00").first().waitFor();
    await shot(h.page, "h1-verifications-group");
    await card.getByRole("button", { name: /^Approve/ }).click();
    const dialog = h.page.getByRole("dialog");
    await dialog.getByRole("button", { name: /^Approve/ }).click();
    await dialog.waitFor({ state: "detached" });
    await h.page.getByText(/3 fees are now paid/).first().waitFor();
  });
  await h.ctx.close();
  await step("the group result page is now the receipt", async () => {
    await g.page.reload();
    await g.page.getByText("Bayaran berjaya", { exact: true }).first().waitFor();
    await g.page.getByText("Pindahan bank / DuitNow").first().waitFor();
    await shot(g.page, "g3-pay-all-transfer-receipt");
  });
  await g.ctx.close();
} catch {
  /* already recorded by step() */
} finally {
  await browser.close();
}

if (failures.length) {
  console.error(`\nSMOKE FAILED (${failures.length}):\n - ${failures.join("\n - ")}`);
  process.exit(1);
}
console.log(`\nSMOKE PASSED. Screenshots in ${OUT}`);
