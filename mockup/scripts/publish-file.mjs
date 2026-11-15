// Converts dist/index.html (single file from vite-plugin-singlefile) into a body fragment for
// hosts that wrap content in their own <!doctype html><head><body> skeleton.
// Output order: <title>, viewport-fit meta, Google Fonts <link>, <style> blocks, root div, module script.
import { readFileSync, writeFileSync, statSync } from "node:fs";
import { fileURLToPath } from "node:url";

const dist = (p) => fileURLToPath(new URL(`../dist/${p}`, import.meta.url));
const html = readFileSync(dist("index.html"), "utf8");

const fontLink = html.match(/<link[^>]+href="https:\/\/fonts\.googleapis\.com\/css2[^"]*"[^>]*>/)?.[0];
if (!fontLink) throw new Error("Google Fonts <link> not found in dist/index.html");

const styles = [];
let i = 0;
for (;;) {
  const start = html.indexOf("<style", i);
  if (start === -1) break;
  const end = html.indexOf("</style>", start);
  if (end === -1) throw new Error("Unclosed <style>");
  styles.push(html.slice(start, end + "</style>".length));
  i = end + 1;
}
if (styles.length === 0) throw new Error("No <style> blocks found");

const sStart = html.indexOf('<script type="module"');
if (sStart === -1) throw new Error('No <script type="module"> found');
const sEnd = html.indexOf("</script>", sStart);
if (sEnd === -1) throw new Error("Unclosed module script");
if (html.indexOf('<script type="module"', sEnd) !== -1) throw new Error("Expected exactly one module script");
const script = html.slice(sStart, sEnd + "</script>".length).replace(/\s+crossorigin(?=[\s>])/, "");

const out = [
  "<title>Club Portal Mockup</title>",
  '<meta name="claude:viewport-fit-sides" content="cover">',
  fontLink,
  ...styles,
  '<div id="root"></div>',
  script,
  "",
].join("\n");

for (const bad of [/<!doctype/i, /<html[\s>]/i, /<\/?head[\s>]/i, /<\/?body[\s>]/i]) {
  const outside = out.replace(script, "").replace(styles.join("\n"), "");
  if (bad.test(outside)) throw new Error(`Output still contains ${bad}`);
}

const target = dist("club-portal-mockup.html");
writeFileSync(target, out);
const kb = (statSync(target).size / 1024).toFixed(1);
console.log(`Wrote dist/club-portal-mockup.html (${kb} KB)`);
if (statSync(target).size > 2.5 * 1024 * 1024) {
  console.error("Warning: file is larger than 2.5 MB");
  process.exitCode = 1;
}
