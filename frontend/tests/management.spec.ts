import { expect, test, type Page, type Route } from "@playwright/test";

const organiser = { id: 17, name: "Jamie Organiser", email: "organiser@example.test", role: "organiser" };
const fixture = () => ({
  id: 51, owner_id: 17, name: "Riverside Session", description: "An evening by the river.",
  venue: "Riverside Studio", starts_at: "2036-06-18T17:30:00.000000Z", ends_at: null,
  timezone: "Europe/London", capacity: 12, confirmed_count: 0, remaining_capacity: 12,
  status: "draft", has_history: false, event_type: { id: 2, name: "Concert", slug: "concert", is_active: true },
});
const json = (route: Route, body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
const cursorPage = (data: unknown[], next: string | null = null, prev: string | null = null) => ({ data, meta: { next_cursor: next, prev_cursor: prev } });

async function setup(page: Page, role: string | null = "organiser") {
  const state = { event: fixture(), role, writes: [] as { method: string; body: Record<string, unknown>; csrf?: string }[], failSave: false, lastQuery: "" };
  await page.route("**/sanctum/csrf-cookie", route => route.fulfill({ status: 204, headers: { "Set-Cookie": "XSRF-TOKEN=csrf%3Dvalue; Path=/; SameSite=Lax" } }));
  await page.route("**/api/spa/**", async route => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace("/api/spa/", "");
    if (path === "user") return json(route, state.role ? { data: { ...organiser, role: state.role } } : { message: "Unauthenticated." }, state.role ? 200 : 401);
    if (path === "logout") { state.role = null; return route.fulfill({ status: 204 }); }
    if (request.method() !== "GET") {
      const body = request.postData() ? request.postDataJSON() : {};
      state.writes.push({ method: request.method(), body, csrf: request.headers()["x-xsrf-token"] });
      if (state.failSave) return json(route, { message: "The given data was invalid.", errors: { capacity: ["Capacity cannot be lower than current confirmed occupancy."] } }, 422);
      state.event = { ...state.event, ...body, ...(request.method() === "DELETE" ? { status: "cancelled", confirmed_count: 0 } : {}) };
      return json(route, { data: state.event }, request.method() === "POST" ? 201 : 200);
    }
    if (path === "manage/event-types") return json(route, cursorPage([{ id: 2, name: "Concert", slug: "concert" }]));
    if (path === "manage/events") { state.lastQuery = url.search; return json(route, cursorPage([state.event], url.searchParams.has("cursor") ? null : "next-page", url.searchParams.has("cursor") ? "previous-page" : null)); }
    if (path === "manage/events/51") return json(route, { data: state.event });
    if (path.endsWith("/reservations")) return json(route, cursorPage([{ id: 71, user_id: 9, user: { id: 9, name: "Alex Attendee" }, status: "confirmed", created_at: "2036-01-01T10:00:00Z" }], url.searchParams.has("cursor") ? null : "attendees-next"));
    if (path.endsWith("/waitlist")) return json(route, cursorPage([]));
    return json(route, { message: "Not found." }, 404);
  });
  return state;
}

test("organiser lists events and follows opaque pagination", async ({ page }) => {
  const state = await setup(page);
  await page.goto("/manage/events");
  await expect(page.getByRole("heading", { name: "My events." })).toBeVisible();
  await expect(page.getByRole("link", { name: "Riverside Session" })).toBeVisible();
  await page.getByRole("button", { name: "Next events" }).click();
  await expect(page.getByRole("button", { name: "Previous events" })).toBeEnabled();
  expect(state.lastQuery).toContain("cursor=next-page");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test("create draft, publish and confirm cancellation with CSRF", async ({ page }) => {
  const state = await setup(page);
  await page.goto("/manage/events/new");
  await page.getByLabel("Event name", { exact: true }).fill("New Session");
  await page.getByLabel("Venue", { exact: true }).fill("Studio Two");
  await page.getByLabel("Start instant (UTC or offset)").fill("2036-07-01T18:00:00+01:00");
  await page.getByLabel("Description", { exact: true }).fill("A new evening.");
  await page.getByLabel("Capacity", { exact: true }).fill("24");
  await page.getByRole("button", { name: "Create draft" }).click();
  await expect(page).toHaveURL(/\/manage\/events\/51$/);
  await expect(page.getByRole("heading", { name: "New Session", exact: true })).toBeVisible();
  expect(state.writes[0]?.body).toMatchObject({ capacity: 24, event_type_id: 2, starts_at: "2036-07-01T18:00:00+01:00", ends_at: null });
  expect(state.writes[0]?.body).not.toHaveProperty("owner_id");
  await page.getByRole("button", { name: "Publish event", exact: true }).click();
  await expect(page.getByRole("status").filter({ hasText: "Event published." })).toBeVisible();
  await page.getByRole("button", { name: "Cancel event", exact: true }).click();
  expect(state.event.status).toBe("published");
  await page.getByRole("button", { name: "Keep event" }).click();
  await expect(page.getByRole("button", { name: "Confirm cancellation" })).not.toBeVisible();
  await page.getByRole("button", { name: "Cancel event", exact: true }).click();
  await page.getByRole("button", { name: "Confirm cancellation" }).click();
  await expect(page.getByRole("status").filter({ hasText: "Event cancelled." })).toBeVisible();
  expect(state.writes.every(write => write.csrf === "csrf=value")).toBe(true);
  expect(state.writes.map(write => write.method)).toEqual(["POST", "PATCH", "DELETE"]);
});

test("history freezes schedule and type; server capacity rejection stays actionable", async ({ page }) => {
  const state = await setup(page);
  state.event.has_history = true; state.event.status = "published"; state.event.confirmed_count = 5; state.failSave = true;
  await page.goto("/manage/events/51");
  await expect(page.getByLabel("Start instant (UTC or offset)")).toBeDisabled();
  await expect(page.getByLabel("Event type", { exact: true })).toBeDisabled();
  await page.getByLabel("Capacity", { exact: true }).fill("2");
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByRole("alert")).toContainText("Capacity cannot be lower");
  await expect(page.getByRole("alert")).toBeFocused();
  expect(state.writes[0]?.body).toEqual({ capacity: 2 });
  await expect(page.getByText("Alex Attendee", { exact: true })).toBeVisible();
  await page.getByRole("navigation", { name: "reservations pages" }).getByRole("button", { name: "Next records" }).click();
  await expect(page.getByRole("navigation", { name: "reservations pages" }).getByRole("button", { name: "Next records" })).toBeDisabled();
});

test("ordinary customers and guests see access recovery without private requests", async ({ page }) => {
  const state = await setup(page, "regular_user");
  const privateRequests: string[] = [];
  page.on("request", request => { if (request.url().includes("/api/spa/manage/")) privateRequests.push(request.url()); });
  await page.goto("/manage/events");
  await expect(page.getByRole("heading", { name: "Organiser access required." })).toBeVisible();
  expect(privateRequests).toEqual([]);
  state.role = null;
  await page.reload();
  await expect(page.getByRole("heading", { name: "Sign in to manage events." })).toBeVisible();
  expect(privateRequests).toEqual([]);
});

test("admin sees all-events context and management deep links return successful HTML", async ({ page, request }) => {
  await setup(page, "admin");
  await page.goto("/manage/events");
  await expect(page.getByRole("heading", { name: "Manage events." })).toBeVisible();
  for (const path of ["/manage/events", "/manage/events/new", "/manage/events/51"]) {
    const response = await request.get(path, { headers: { Accept: "text/html" } });
    expect(response.status()).toBe(200);
  }
  expect((await request.get("/manage/events/not-real", { headers: { Accept: "text/html" } })).status()).toBe(404);
});

test("closed events permit metadata corrections and keep inventory fixed", async ({ page }) => {
  const state = await setup(page); state.event.status = "cancelled";
  await page.goto("/manage/events/51");
  await expect(page.getByLabel("Capacity", { exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Publish event" })).not.toBeVisible();
  await page.getByLabel("Event name", { exact: true }).fill("Corrected Session");
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByRole("heading", { name: "Corrected Session", exact: true })).toBeVisible();
  expect(state.writes[0]?.body).toEqual({ name: "Corrected Session" });
  const stored = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
  expect(stored).not.toContain("Corrected Session");
});

test("completion remains available for a past published event", async ({ page }) => {
  const state = await setup(page);
  state.event.status = "published";
  state.event.starts_at = "2020-01-01T18:00:00.000000Z";
  await page.goto("/manage/events/51");
  await page.getByRole("button", { name: "Mark completed" }).click();
  await expect(page.getByRole("status").filter({ hasText: "Event completed." })).toBeVisible();
  expect(state.writes[0]?.body).toEqual({ status: "completed" });
  await expect(page.getByLabel("Capacity", { exact: true })).toBeDisabled();
});

test("an expired session clears private records and offers sign-in", async ({ page }) => {
  const state = await setup(page);
  await page.goto("/manage/events/51");
  await expect(page.getByText("Alex Attendee", { exact: true })).toBeVisible();
  await page.route("**/api/spa/manage/events/51", route => {
    if (route.request().method() === "PATCH") {
      state.role = null;
      return json(route, { message: "Unauthenticated." }, 401);
    }
    return route.fallback();
  });
  await page.getByRole("button", { name: "Publish event", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Sign in to manage events." })).toBeVisible();
  await expect(page.getByText("Alex Attendee", { exact: true })).not.toBeVisible();
  await expect(page.getByLabel("Event name", { exact: true })).not.toBeVisible();
});
