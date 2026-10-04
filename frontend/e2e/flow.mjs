// End-to-end smoke test of the user and admin flows in a real browser.
// Run with: docker compose --profile e2e run --rm e2e   (see README)
import { chromium } from "playwright";
import fs from "node:fs";

const BASE = process.env.E2E_BASE_URL ?? "http://localhost:3000";
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? "admin@krl.test";
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? "Admin12345";
const SHOTS = process.env.E2E_SCREENSHOTS ?? "/tmp/e2e";
// Station that has synced schedules (KCI_SYNC_STATIONS), e.g. THB = Tanah Abang.
const STATION = (process.env.E2E_STATION ?? "THB").toUpperCase();
const FAVORITE_2 = (process.env.E2E_FAVORITE_2 ?? "SUD").toUpperCase();
fs.mkdirSync(SHOTS, { recursive: true });

const email = `e2e${Date.now()}@test.id`;
const password = "Rahasia123";
const step = (msg) => console.log(`✓ ${msg}`);
// Forms keep their submit button disabled until hydrated; typing earlier would be lost.
const formReady = (page, button) => page.getByRole("button", { name: button, exact: true, disabled: false }).waitFor();

// Local CAs (e.g. Caddy `tls internal`) are not trusted inside the test container.
const IGNORE_HTTPS = process.env.E2E_IGNORE_HTTPS_ERRORS === "1";
const ctx = (options = {}) => browser.newContext({ ignoreHTTPSErrors: IGNORE_HTTPS, ...options });

const browser = await chromium.launch();
try {
  // --- User: register -> logged in --------------------------------------------
  let context = await ctx({ viewport: { width: 1280, height: 900 } });
  let page = await context.newPage();
  page.on("pageerror", (e) => console.error("pageerror:", e.message));
  if (process.env.E2E_DEBUG) {
    page.on("response", (r) => {
      if (r.url().includes("/auth/") || r.url().includes("csrf-cookie")) console.log(`  ${r.request().method()} ${r.url()} -> ${r.status()}`);
    });
    page.on("framenavigated", (f) => f === page.mainFrame() && console.log(`  NAV ${f.url()}`));
    page.on("console", (m) => /reload|hmr|fast refresh|error|websocket/i.test(m.text()) && console.log(`  CONSOLE ${m.type()}: ${m.text().slice(0, 200)}`));
  }

  await page.goto(`${BASE}/`);
  const upcoming = page.locator("section", { has: page.getByRole("heading", { name: /^Kereta terdekat/ }) });
  // After the last train of the day the list is empty and says so.
  const noMoreTrains = upcoming.getByText(/^Tidak ada lagi kereta hari ini/);
  const upcomingReady = () => upcoming.locator("ol li").first().or(noMoreTrains).waitFor();
  await upcomingReady();
  const firstUpcoming = (await noMoreTrains.count())
    ? "no more trains today"
    : (await upcoming.locator("ol li").first().innerText()).replace(/\s+/g, " ").trim();
  await upcoming.getByRole("link", { name: "Daftar" }).waitFor();
  if (await page.getByRole("dialog").isVisible()) throw new Error("guests must not get a favourites dialog");
  await page.screenshot({ path: `${SHOTS}/home-guest.png` });
  step(`guest homepage shows the soonest departures ("${firstUpcoming}") and a sign-up invitation`);

  // Departure station picker is the first thing in the section, and is searchable.
  const fromBox = page.getByRole("combobox", { name: "Stasiun keberangkatan" });
  const boxTop = (await fromBox.boundingBox()).y;
  const titleTop = (await page.getByRole("heading", { name: /^Kereta terdekat/ }).boundingBox()).y;
  if (boxTop > titleTop) throw new Error("departure station picker should be above the 'Kereta terdekat' title");

  // Search by typing part of the code, pick with the mouse.
  await fromBox.click();
  await fromBox.fill(STATION.toLowerCase());
  const listbox = page.getByRole("listbox", { name: "Stasiun keberangkatan" });
  const optionCount = await listbox.getByRole("option").count();
  await listbox.getByRole("option").filter({ hasText: STATION }).first().click();
  await page.waitForURL(new RegExp(`\\?dari=${STATION}$`));
  await page.getByRole("heading", { name: /^Kereta terdekat dari / }).waitFor();
  await upcomingReady();
  const fromRows = await upcoming.locator("ol li").count();
  await page.reload();
  if (!(await fromBox.inputValue()).includes(`(${STATION})`)) throw new Error("departure station not kept after reload");

  // Search by name with the keyboard: type, Enter picks the best match.
  await fromBox.click();
  await fromBox.fill("sudir");
  await page.keyboard.press("Enter");
  await page.waitForURL(new RegExp(`\\?dari=${FAVORITE_2}$`));
  await upcoming.getByText(/^Belum ada jadwal dari /).or(upcoming.locator("ol li").first()).or(noMoreTrains).waitFor();
  const secondState = (await upcoming.getByText(/^Belum ada jadwal dari /).count()) > 0 ? "no schedules yet" : "has schedules";

  // Unknown text: no options; clear button returns to all stations.
  await fromBox.click();
  await fromBox.fill("zzzz");
  await listbox.getByText("Stasiun tidak ditemukan").waitFor();
  await page.keyboard.press("Escape");
  await page.getByRole("button", { name: "Hapus pilihan stasiun" }).click();
  await page.waitForURL(`${BASE}/`);
  step(`guest searches departure station: "${STATION.toLowerCase()}" → ${optionCount} option(s), ${fromRows} trains (kept after reload); "sudir"+Enter → ${FAVORITE_2} (${secondState}); cleared`);

  await page.goto(`${BASE}/register`);
  await formReady(page, "Buat akun");
  await page.getByLabel("Nama").fill("E2E User");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Kata sandi", { exact: true }).fill(password);
  await page.getByLabel("Ulangi kata sandi").fill(password);
  await page.getByRole("button", { name: "Buat akun" }).click();
  await page.waitForURL(`${BASE}/`);
  // Right after signing up a mandatory dialog asks for the first favourite route.
  const routeDialog = page.getByRole("dialog", { name: "Pilih rute favorit" });
  await routeDialog.waitFor();
  await page.keyboard.press("Escape");
  if (!(await routeDialog.isVisible())) throw new Error("route dialog must not close with Esc while no route is chosen");
  await routeDialog.getByRole("button", { name: "Simpan rute favorit" }).click();
  await routeDialog.getByText("Pilih stasiun asal dan tujuan untuk setiap rute.").waitFor(); // a route is required
  await routeDialog.getByLabel("Dari stasiun").selectOption(STATION);
  await routeDialog.getByLabel("Ke stasiun").selectOption(FAVORITE_2);
  await routeDialog.getByRole("button", { name: "+ Tambah rute (1/4)" }).click();
  await routeDialog.getByRole("button", { name: "Hapus rute 2" }).click();
  await routeDialog.getByRole("button", { name: "Simpan rute favorit" }).click();
  await routeDialog.waitFor({ state: "hidden" });
  step(`register → dialog → favourite route ${STATION} → ${FAVORITE_2}`);

  await page.goto(`${BASE}/account`);
  await page.getByText(email).first().waitFor();
  step("account page");

  // Logout, then login again
  await page.getByRole("button", { name: "Keluar" }).click();
  await page.waitForURL(`${BASE}/`);
  await page.getByRole("link", { name: "Masuk" }).waitFor();
  step("logout");

  await page.goto(`${BASE}/login`);
  await formReady(page, "Masuk");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Kata sandi").fill("wrong-password1");
  await page.getByRole("button", { name: "Masuk" }).click();
  // Not just any role=alert: Next.js' route announcer also has that role.
  await page.getByText("These credentials do not match our records.").waitFor();
  step("invalid login shows error");

  await page.getByLabel("Kata sandi").fill(password);
  await page.getByRole("button", { name: "Masuk" }).click();
  await page.waitForURL(`${BASE}/`);
  step("login → homepage");

  await page.goto(`${BASE}/account`);

  await page.reload();
  await page.getByText(email).first().waitFor();
  step("still logged in after refresh");

  // "Close the browser": keep only persistent cookies (drop session cookies), new context.
  const state = await context.storageState();
  const persistent = state.cookies.filter((c) => c.expires > 0 && !c.name.endsWith("session"));
  await context.close();
  context = await ctx({ viewport: { width: 1280, height: 900 }, storageState: { cookies: persistent, origins: [] } });
  page = await context.newPage();
  await page.goto(`${BASE}/account`);
  await page.getByText(email).first().waitFor();
  await page.getByRole("link", { name: "Akun" }).waitFor();
  step(`still logged in after browser restart (kept cookies: ${persistent.map((c) => c.name.split("_")[0]).join(", ")})`);

  // --- Favourite routes on the homepage (signed in) ---------------------------
  await page.goto(`${BASE}/`);
  const favSection = page.locator("section", { has: page.getByRole("heading", { name: "Rute Favorit" }) });
  await favSection.getByRole("heading", { level: 3 }).first().waitFor();
  const favCards = await favSection.getByRole("heading", { level: 3 }).allInnerTexts();
  if (favCards.length !== 1) throw new Error(`expected 1 favourite route card, got ${favCards.length}`);
  if (await page.getByRole("dialog").isVisible()) throw new Error("no dialog expected for a user with a favourite route");
  const nextTrains = await favSection.locator("ol li").count();
  await page.screenshot({ path: `${SHOTS}/home-favorites.png` });
  step(`homepage shows favourite route (${favCards.join(" + ")}), ${nextTrains} upcoming departures`);

  // Change the routes later via the (optional) dialog.
  await favSection.getByRole("button", { name: "Ubah rute" }).click();
  const dialog = page.getByRole("dialog", { name: "Rute favorit" });
  await dialog.waitFor();
  await page.keyboard.press("Escape");
  await dialog.waitFor({ state: "hidden" });
  step("favourite routes dialog is optional once a route exists (Esc closes it)");

  // --- Schedule search --------------------------------------------------------
  await page.getByLabel("Stasiun", { exact: true }).selectOption(STATION);
  await page.getByRole("button", { name: "Cari Jadwal" }).click();
  await page.waitForURL(`**/schedule?station=${STATION}*`);
  await page.getByRole("heading", { level: 2, name: /^Stasiun / }).waitFor();
  await page.getByRole("button", { name: "Semua arah" }).waitFor();
  // Show all trains of the day (independent of the current time)
  const hide = page.getByLabel("Sembunyikan yang sudah berangkat");
  if (await hide.isVisible()) await hide.uncheck();
  const rows = await page.locator("table tbody tr").count();
  if (rows < 10) throw new Error(`expected schedule rows, got ${rows}`);
  await page.screenshot({ path: `${SHOTS}/schedule-desktop.png`, fullPage: false });
  step(`schedule for ${STATION} shows ${rows} trains`);

  await page.getByRole("button", { name: /^→ / }).first().click();
  const filtered = await page.locator("table tbody tr").count();
  if (!(filtered > 0 && filtered < rows)) throw new Error("destination filter did not filter");
  step(`destination filter → ${filtered} trains`);

  // Destination station ("Ke stasiun"): only trains stopping there, with arrival time.
  await page.goto(`${BASE}/stations/${STATION}`);
  const toSelect = page.getByLabel("Ke stasiun");
  await toSelect.locator("option:not([value=''])").first().waitFor({ state: "attached", timeout: 60_000 });
  const toOption = await toSelect.locator("option:not([value=''])").first();
  const toCode = await toOption.getAttribute("value");
  const toLabel = (await toOption.innerText()).trim();
  await toSelect.selectOption(toCode);
  await page.waitForURL(new RegExp(`to=${toCode}`));
  await page.getByRole("heading", { name: /→/ }).waitFor();
  // Show the whole day first (late at night every train has already left).
  const hideTrip = page.getByLabel("Sembunyikan yang sudah berangkat");
  if (await hideTrip.isVisible()) await hideTrip.uncheck();
  await page.getByRole("columnheader", { name: /^Tiba di / }).waitFor();
  const tripRows = await page.locator("table tbody tr").count();
  if (tripRows < 1) throw new Error(`no trains to ${toCode}`);
  await page.screenshot({ path: `${SHOTS}/station-to.png` });
  step(`to station ${toLabel} → ${tripRows} trains`);

  // Mobile layout
  const mobile = await ctx({ viewport: { width: 390, height: 844 }, isMobile: true });
  const m = await mobile.newPage();
  await m.goto(`${BASE}/stations/${STATION}`);
  await m.getByRole("heading", { level: 1, name: /^Stasiun / }).waitFor();
  const hideMobile = m.getByLabel("Sembunyikan yang sudah berangkat");
  await hideMobile.or(m.locator("ul li").first()).first().waitFor();
  if (await hideMobile.isVisible()) await hideMobile.uncheck(); // late at night every train has left
  await m.locator("ul li").first().waitFor();
  await m.screenshot({ path: `${SHOTS}/station-mobile.png` });
  await mobile.close();
  step("station page renders on mobile");

  // --- Normal user cannot use admin -----------------------------------------
  await page.goto(`${BASE}/admin/dashboard`);
  await page.waitForURL("**/admin/login**");
  await formReady(page, "Masuk");
  step("normal user redirected to /admin/login");

  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Kata sandi").fill(password);
  await page.getByRole("button", { name: "Masuk" }).click();
  await page.getByText("tidak memiliki akses admin").waitFor();
  step("normal user rejected by admin login");

  // --- Admin --------------------------------------------------------------------
  await page.getByLabel("Email").fill(ADMIN_EMAIL);
  await page.getByLabel("Kata sandi").fill(ADMIN_PASSWORD);
  await page.getByRole("button", { name: "Masuk" }).click();
  await page.waitForURL("**/admin/dashboard");
  await page.getByText("Total Pengguna").waitFor();
  await page.getByText(/^(Berhasil|Sebagian|Gagal|Berjalan|Antre)$/).first().waitFor(); // status of the last sync, whatever it is
  await page.screenshot({ path: `${SHOTS}/admin-dashboard.png` });
  step("admin login → dashboard");

  await page.getByRole("link", { name: "Pengguna" }).click();
  await page.waitForURL("**/admin/users");
  await page.getByLabel("Cari").fill(email);
  await page.getByRole("cell", { name: email }).waitFor();
  step("admin user search");

  await page.getByRole("link", { name: "Stasiun" }).click();
  await page.waitForURL("**/admin/stations");
  await page.getByLabel("Cari").fill("TGS");
  await page.getByRole("cell", { name: "Tigaraksa", exact: true }).waitFor();
  step("admin station search");

  await page.getByLabel("Cari").fill(STATION);
  await page.getByRole("cell", { name: STATION, exact: true }).waitFor();
  await page.locator("table tbody tr").first().getByRole("link", { name: "Detail" }).click();
  await page.waitForURL(/\/admin\/stations\/\d+$/);
  await page.getByRole("heading", { name: "Line yang melayani" }).waitFor();
  await page.getByRole("heading", { name: "Jadwal tersimpan per tanggal" }).waitFor();
  const days = await page.locator("table tbody tr").count();
  if (days < 1) throw new Error("station detail shows no schedule dates");
  await page.screenshot({ path: `${SHOTS}/admin-station-detail.png`, fullPage: true });
  step(`admin station detail (${days} service dates)`);

  await page.getByRole("link", { name: "← Semua stasiun" }).click();
  await page.waitForURL("**/admin/stations");

  // Stations API URL: open the editor and dry-run the current URL (no save).
  await page.getByRole("button", { name: "Ubah URL" }).click();
  await page.getByRole("button", { name: "Tes URL" }).click();
  await page.getByText(/URL dapat dibaca|URL tidak dapat dipakai/).waitFor({ timeout: 60_000 });
  const testResult = (await page.getByText(/URL dapat dibaca|URL tidak dapat dipakai/).innerText()).trim();
  await page.getByRole("button", { name: "Batal" }).click();
  step(`stations API URL test → "${testResult}"`);

  await page.getByRole("button", { name: "Sync Stasiun Sekarang" }).click();
  await page.getByText("Sinkronisasi data stasiun dimulai").waitFor();
  await page.getByRole("button", { name: "Sinkronisasi berjalan…" }).waitFor();
  await page.getByRole("button", { name: "Sync Stasiun Sekarang", disabled: false }).waitFor({ timeout: 60_000 });
  const panel = page.locator("section", { has: page.getByRole("heading", { name: "Terakhir sync" }) });
  await panel.getByText("Berhasil", { exact: true }).waitFor();
  await panel.getByText("Manual oleh").waitFor();
  const lastSync = (await panel.locator("time").innerText()).trim();
  if (!lastSync.endsWith("WIB")) throw new Error(`last sync time not shown: "${lastSync}"`);
  await page.screenshot({ path: `${SHOTS}/admin-stations.png` });
  step(`manual station sync → terakhir sync ${lastSync}`);

  await page.getByRole("link", { name: "Jadwal" }).click();
  await page.waitForURL("**/admin/schedules");
  const trainNumber = (await page.locator("table tbody tr").first().locator("td").nth(2).innerText()).trim();
  await page.getByLabel("Nomor kereta").fill(trainNumber);
  await page.getByRole("cell", { name: trainNumber, exact: true }).first().waitFor();
  step("admin schedule filter");

  await page.getByRole("link", { name: "Sinkronisasi" }).click();
  await page.waitForURL("**/admin/sync");

  // Schedules API URL and Train Stops API URL: dry-run each (no save).
  for (const title of ["Sumber data jadwal", "Pemberhentian kereta"]) {
    const card = page.locator("section", { has: page.getByRole("heading", { name: new RegExp(`^${title}`) }) });
    await card.getByRole("button", { name: "Ubah URL" }).click();
    await card.getByRole("button", { name: "Tes URL" }).click();
    const result = card.getByText(/URL dapat dibaca|URL tidak dapat dipakai/);
    await result.waitFor({ timeout: 60_000 });
    const text = (await result.innerText()).trim();
    if (title === "Sumber data jadwal") await page.screenshot({ path: `${SHOTS}/admin-schedules-api-test.png` });
    await card.getByRole("button", { name: "Batal" }).click();
    step(`${title} URL test → "${text}"`);
  }
  await page.getByRole("button", { name: "Sync KCI Data Now" }).click();
  await page.getByText("Sinkronisasi dimulai").waitFor();
  await page.getByRole("button", { name: "Sinkronisasi berjalan…" }).waitFor();
  // Finished when the button is enabled again and the newest history row is a successful manual run.
  await page.getByRole("button", { name: "Sync KCI Data Now", disabled: false }).waitFor({ timeout: 180_000 });
  const firstRow = page.locator("table tbody tr").first();
  await firstRow.getByText("Manual").waitFor();
  await firstRow.getByText("Berhasil").waitFor();
  await page.screenshot({ path: `${SHOTS}/admin-sync.png` });
  step("manual sync triggered and finished");

  // Admin session is independent from the website session
  await page.goto(`${BASE}/account`);
  await page.getByText(email).first().waitFor();
  step("website session unaffected by admin login");

  console.log("\nE2E OK");
} catch (error) {
  // Screenshot every open page to see what the user would have seen.
  let n = 0;
  for (const context of browser.contexts()) {
    for (const p of context.pages()) await p.screenshot({ path: `${SHOTS}/failure-${++n}.png`, fullPage: true }).catch(() => {});
  }
  throw error;
} finally {
  await browser.close();
}
