import { expect, test, type Page, type Route } from "@playwright/test";

const organiser = { id: 17, name: "Jamie Organiser", email: "organiser@example.test", role: "organiser" };
const fixture = () => ({
  id: 51, owner_id: 17, name: "Riverside Session", description: "An evening by the river.",
  venue: "Riverside Studio", starts_at: "2036-06-18T17:30:00.000000Z", ends_at: null as string | null,
  timezone: "Europe/London", capacity: 12, confirmed_count: 0, remaining_capacity: 12,
  status: "draft", has_history: false, event_type: { id: 2, name: "Concert", slug: "concert", is_active: true },
});
const json = (route: Route, body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
const cursorPage = (data: unknown[], next: string | null = null, prev: string | null = null) => ({ data, meta: { next_cursor: next, prev_cursor: prev } });

async function setup(page: Page, role: string | null = "organiser") {
  const state = { event: fixture(), role, writes: [] as { method: string; body: Record<string, unknown>; csrf?: string }[], failSave: false, validationErrors: null as Record<string, string[]> | null, lastQuery: "" };
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
      if (state.validationErrors) return json(route, { message: "The given data was invalid.", errors: state.validationErrors }, 422);
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
  await page.getByLabel("Start date and time", { exact: true }).fill("2036-07-01T18:00");
  await page.getByLabel("Description", { exact: true }).fill("A new evening.");
  await page.getByLabel("Capacity", { exact: true }).fill("24");
  await page.getByRole("button", { name: "Create draft" }).click();
  await expect(page).toHaveURL(/\/manage\/events\/51$/);
  await expect(page.getByRole("heading", { name: "New Session", exact: true })).toBeVisible();
  expect(state.writes[0]?.body).toMatchObject({ capacity: 24, event_type_id: 2, timezone: "Europe/London", ends_at: null });
  expect(String(state.writes[0]?.body.starts_at)).toMatch(/Z$/);
  expect(new Date(String(state.writes[0]?.body.starts_at)).toISOString()).toBe("2036-07-01T17:00:00.000Z");
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
  state.event.starts_at = "2036-06-18T17:30:42.123456Z";
  state.event.ends_at = "2036-06-18T19:45:12.654321Z";
  await page.goto("/manage/events/51");
  await expect(page.getByLabel("Start date and time", { exact: true })).toBeDisabled();
  await expect(page.getByLabel("End date and time (optional)", { exact: true })).toBeDisabled();
  await expect(page.getByLabel("Time zone", { exact: true })).toBeDisabled();
  await expect(page.getByLabel("Event type", { exact: true })).toBeDisabled();
  await page.getByLabel("Capacity", { exact: true }).fill("2");
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByRole("alert")).toContainText("Capacity cannot be lower");
  await expect(page.getByRole("alert")).toBeFocused();
  expect(state.writes[0]?.body).toEqual({ capacity: 2 });
  await expect(page.getByText("Alex Attendee", { exact: true })).toBeVisible();
  await page.getByRole("navigation", { name: "reservations pages" }).getByRole("button", { name: "Next records" }).click();
  await expect(page.getByRole("navigation", { name: "reservations pages" }).getByRole("button", { name: "Next records" })).toBeDisabled();
  state.failSave = false;
  await page.getByLabel("Capacity", { exact: true }).fill("12");
  await page.getByLabel("Venue", { exact: true }).fill("Corrected history venue");
  await page.getByRole("button", { name: "Save changes", exact: true }).click();
  await expect(page.getByRole("status").filter({ hasText: "Changes saved." })).toBeVisible();
  expect(state.writes[1]?.body).toEqual({ venue: "Corrected history venue" });
  expect(state.event.starts_at).toBe("2036-06-18T17:30:42.123456Z");
  expect(state.event.ends_at).toBe("2036-06-18T19:45:12.654321Z");
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

async function fillDraft(page: Page, start: string) {
  await page.getByLabel("Event name", { exact: true }).fill("Calendar Session");
  await page.getByLabel("Venue", { exact: true }).fill("Calendar Studio");
  await page.getByLabel("Description", { exact: true }).fill("An event entered using its local time.");
  await page.getByLabel("Start date and time", { exact: true }).fill(start);
}

type PickerCall = { id: string; disabled: boolean; userActivation: boolean };

async function observeNativePicker(page: Page, mode: "native" | "unavailable" | "throws" = "native") {
  await page.addInitScript(({ mode }) => {
    const original = HTMLInputElement.prototype.showPicker;
    const calls: { id: string; disabled: boolean; userActivation: boolean }[] = [];
    Object.defineProperty(window, "__eventPickerCalls", { value: calls, configurable: true });
    Object.defineProperty(HTMLInputElement.prototype, "showPicker", {
      configurable: true,
      writable: true,
      value: mode === "unavailable" ? undefined : function (this: HTMLInputElement) {
        calls.push({ id: this.id, disabled: this.matches(":disabled"), userActivation: navigator.userActivation.isActive });
        if (mode === "throws") throw new DOMException("Picker unavailable in this browser context.", "NotAllowedError");
        // Observe the native method rather than replacing the actual popup.
        if (typeof original === "function") return original.call(this);
      },
    });
  }, { mode });
}

const pickerCalls = (page: Page) => page.evaluate(() => (window as Window & { __eventPickerCalls: PickerCall[] }).__eventPickerCalls);

test.describe("date field pointer and keyboard interaction", () => {
  test("left and middle clicks on either field open its native picker during the pointer gesture", async ({ page }, testInfo) => {
    const state = await setup(page);
    const errors: string[] = [];
    page.on("pageerror", error => errors.push(error.message));
    await observeNativePicker(page);
    await page.goto("/manage/events/new");
    await fillDraft(page, "2036-07-01T18:00");
    await page.getByLabel("End date and time (optional)", { exact: true }).fill("2036-07-01T20:00");
    const expected: string[] = [];
    for (const [label, id] of [["Start date and time", "event-starts_at"], ["End date and time (optional)", "event-ends_at"]]) {
      const input = page.getByLabel(label!, { exact: true });
      for (const position of ["left", "middle"]) {
        await input.scrollIntoViewIfNeeded();
        const bounds = await input.boundingBox();
        if (!bounds) throw new Error("The date field must be visible for its pointer interaction.");
        await page.mouse.click(bounds.x + (position === "left" ? 18 : bounds.width / 2), bounds.y + bounds.height / 2);
        expected.push(id!);
        await expect.poll(async () => (await pickerCalls(page)).map(call => call.id)).toEqual(expected);
        if (testInfo.project.name === "desktop-chromium" && position === "left") {
          await page.screenshot({ path: testInfo.outputPath(`${id}-body-picker-open.png`) });
        }
        await page.keyboard.press("Escape");
      }
    }
    expect((await pickerCalls(page)).every(call => call.userActivation && !call.disabled)).toBe(true);
    expect(errors).toEqual([]);
    expect(state.writes).toEqual([]);
  });

  test("Tab and focus leave the picker closed while date entry remains editable", async ({ page }, testInfo) => {
    const state = await setup(page);
    await observeNativePicker(page);
    await page.goto("/manage/events/new");
    await fillDraft(page, "2036-07-01T18:00");
    const start = page.getByLabel("Start date and time", { exact: true });
    await page.getByLabel("Venue", { exact: true }).focus();
    await page.keyboard.press("Tab");
    await expect(start).toBeFocused();
    expect(await pickerCalls(page)).toEqual([]);
    if (testInfo.project.name === "desktop-chromium") {
      // Desktop segmented inputs support arrow editing; mobile native controls
      // use their platform editor and do not apply this desktop key behavior.
      await page.keyboard.press("ArrowUp");
      await expect(start).not.toHaveValue("2036-07-01T18:00");
    }
    await start.fill("2036-07-01T19:15");
    await expect(start).toHaveValue("2036-07-01T19:15");
    await page.getByLabel("End date and time (optional)", { exact: true }).focus();
    expect(await pickerCalls(page)).toEqual([]);
    expect(state.writes).toEqual([]);
  });

  test("history-disabled start and end fields ignore pointer attempts", async ({ page }) => {
    const state = await setup(page);
    state.event.has_history = true;
    await observeNativePicker(page);
    await page.goto("/manage/events/51");
    for (const label of ["Start date and time", "End date and time (optional)"]) {
      const input = page.getByLabel(label, { exact: true });
      await expect(input).toBeDisabled();
      await input.scrollIntoViewIfNeeded();
      const bounds = await input.boundingBox();
      if (!bounds) throw new Error("The disabled date field must remain visible.");
      // A real pointer gesture bypasses Playwright's disabled-element click guard.
      await page.mouse.click(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
    }
    expect(await pickerCalls(page)).toEqual([]);
    expect(state.writes).toEqual([]);
  });

  test("a pending publish also prevents picker opening through its inherited disabled fieldset", async ({ page }) => {
    await setup(page);
    await observeNativePicker(page);
    let release!: () => void;
    const publishing = new Promise<void>(resolve => { release = resolve; });
    await page.route("**/api/spa/manage/events/51", async route => {
      if (route.request().method() === "PATCH") await publishing;
      return route.fallback();
    });
    await page.goto("/manage/events/51");
    await page.getByRole("button", { name: "Publish event", exact: true }).click();
    try {
      for (const label of ["Start date and time", "End date and time (optional)"]) {
        const input = page.getByLabel(label, { exact: true });
        await expect(input).toBeDisabled();
        // Its own disabled property is false; the enclosing operation disables it.
        await expect(input).not.toHaveAttribute("disabled");
        await input.scrollIntoViewIfNeeded();
        const bounds = await input.boundingBox();
        if (!bounds) throw new Error("The pending form's date field must remain visible.");
        await page.mouse.click(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
      }
      expect(await pickerCalls(page)).toEqual([]);
    } finally {
      release();
    }
    await expect(page.getByRole("status").filter({ hasText: "Event published." })).toBeVisible();
  });

  for (const mode of ["unavailable", "throws"] as const) {
    test(`an ${mode} picker keeps both fields editable without browser errors`, async ({ page }) => {
      const state = await setup(page);
      const errors: string[] = [];
      page.on("pageerror", error => errors.push(error.message));
      await observeNativePicker(page, mode);
      await page.goto("/manage/events/new");
      await fillDraft(page, "2036-07-01T18:00");
      for (const [label, value] of [["Start date and time", "2036-07-01T19:00"], ["End date and time (optional)", "2036-07-01T21:00"]]) {
        const input = page.getByLabel(label!, { exact: true });
        await input.click({ position: { x: 18, y: 12 } });
        await input.fill(value!);
        await expect(input).toHaveValue(value!);
      }
      await page.getByRole("button", { name: "Create draft", exact: true }).click();
      await expect(page).toHaveURL(/\/manage\/events\/51$/);
      expect(new Date(String(state.writes[0]?.body.starts_at)).toISOString()).toBe("2036-07-01T18:00:00.000Z");
      expect(new Date(String(state.writes[0]?.body.ends_at)).toISOString()).toBe("2036-07-01T20:00:00.000Z");
      expect(errors).toEqual([]);
      if (mode === "throws") expect((await pickerCalls(page)).map(call => call.id)).toEqual(["event-starts_at", "event-ends_at"]);
    });
  }
});

test.describe("event schedule calendar and time zones", () => {
  // A browser on another continent must still use the selected event time zone.
  test.use({ timezoneId: "America/Los_Angeles" });

  test("native controls support entered values in both themes without mobile overflow", async ({ page }, testInfo) => {
    const state = await setup(page);
    await page.goto("/manage/events/new");
    const start = page.getByLabel("Start date and time", { exact: true });
    const end = page.getByLabel("End date and time (optional)", { exact: true });
    await expect(start).toHaveAttribute("type", "datetime-local");
    await expect(end).toHaveAttribute("type", "datetime-local");
    await expect(start).not.toHaveAttribute("readonly");
    await expect(page.getByLabel("Time zone", { exact: true })).toHaveValue("Europe/London");
    await fillDraft(page, "2036-07-01T18:00");
    await end.fill("2036-07-01T20:30");
    await expect(start).toHaveValue("2036-07-01T18:00");
    await expect(end).toHaveValue("2036-07-01T20:30");
    await start.focus();
    await expect(start).toBeFocused();
    for (const scheme of ["light", "dark"]) {
      const theme = page.getByRole("switch", { name: "Dark mode", exact: true });
      if (await theme.getAttribute("aria-checked") !== String(scheme === "dark")) await theme.click();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      await page.screenshot({ path: testInfo.outputPath(`schedule-${scheme}.png`), fullPage: true });
    }
    if (testInfo.project.name === "desktop-chromium") {
      // Chromium's calendar indicator is a native control, outside application DOM.
      // Capture the opening smoke for visual review without brittle popup selectors.
      await start.scrollIntoViewIfNeeded();
      const bounds = await start.boundingBox();
      if (!bounds) throw new Error("The native calendar input must have visible bounds.");
      await page.mouse.click(bounds.x + bounds.width - 18, bounds.y + bounds.height / 2);
      await page.screenshot({ path: testInfo.outputPath("native-calendar-open.png") });
      await page.keyboard.press("Escape");
    }
    expect(state.writes).toEqual([]);
  });

  test("a spring clock-change gap explains the invalid local time and makes no request", async ({ page }) => {
    const state = await setup(page);
    await page.goto("/manage/events/new");
    await fillDraft(page, "2036-03-30T01:30");
    await page.getByRole("button", { name: "Create draft", exact: true }).click();
    await expect(page.getByLabel("Start date and time", { exact: true })).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByRole("alert")).toContainText(/does not exist|clock.*forward|skipped/i);
    await expect(page).toHaveURL(/\/manage\/events\/new$/);
    expect(state.writes).toEqual([]);
    // The error must be recoverable by entering a time that exists that day.
    await page.getByLabel("Start date and time", { exact: true }).fill("2036-03-30T02:30");
    await page.getByRole("button", { name: "Create draft", exact: true }).click();
    await expect(page).toHaveURL(/\/manage\/events\/51$/);
    expect(new Date(String(state.writes[0]?.body.starts_at)).toISOString()).toBe("2036-03-30T01:30:00.000Z");
  });

  test("an optional end in the spring gap also blocks submission instead of silently shifting it", async ({ page }) => {
    const state = await setup(page);
    await page.goto("/manage/events/new");
    await fillDraft(page, "2036-03-30T00:30");
    const end = page.getByLabel("End date and time (optional)", { exact: true });
    await end.fill("2036-03-30T01:30");
    await page.getByRole("button", { name: "Create draft", exact: true }).click();
    await expect(end).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByRole("alert")).toContainText(/does not exist|clock.*forward|skipped/i);
    expect(state.writes).toEqual([]);
  });

  for (const [occurrence, expected] of [
    ["earlier", "2036-10-26T00:30:00.000Z"],
    ["later", "2036-10-26T01:30:00.000Z"],
  ] as const) {
    test(`a repeated autumn time requires the explicit ${occurrence} occurrence`, async ({ page }) => {
      const state = await setup(page);
      await page.goto("/manage/events/new");
      await fillDraft(page, "2036-10-26T01:30");
      const choice = page.getByLabel("Start date and time occurrence", { exact: true });
      await expect(choice).toBeVisible();
      await expect(choice).toHaveValue("");
      await expect(choice).toContainText(/first/i);
      await expect(choice).toContainText(/second/i);
      await expect(choice).toContainText("+01:00");
      await expect(choice).toContainText("+00:00");
      await page.getByRole("button", { name: "Create draft", exact: true }).click();
      await expect(page.getByRole("alert")).toBeVisible();
      expect(state.writes).toEqual([]);
      await choice.selectOption(occurrence);
      await page.getByRole("button", { name: "Create draft", exact: true }).click();
      await expect(page).toHaveURL(/\/manage\/events\/51$/);
      expect(new Date(String(state.writes[0]?.body.starts_at)).toISOString()).toBe(expected);
      expect(state.writes[0]?.body.timezone).toBe("Europe/London");
    });
  }

  test("existing repeated-time occurrence and exact precision survive noop and metadata edits", async ({ page }) => {
    const state = await setup(page);
    state.event.starts_at = "2036-10-26T01:30:42.123456Z";
    state.event.ends_at = "2036-10-26T02:45:12.654321Z";
    await page.goto("/manage/events/51");
    await expect(page.getByLabel("Start date and time", { exact: true })).toHaveValue("2036-10-26T01:30");
    await expect(page.getByLabel("Start date and time occurrence", { exact: true })).toHaveValue("later");
    await page.getByRole("button", { name: "Save changes", exact: true }).click();
    await expect(page.getByRole("status").filter({ hasText: "Changes saved." })).toBeVisible();
    expect(state.writes).toEqual([]);
    await page.getByLabel("Event name", { exact: true }).fill("Precisely the same instant");
    await page.getByRole("button", { name: "Save changes", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Precisely the same instant", exact: true })).toBeVisible();
    expect(state.writes[0]?.body).toEqual({ name: "Precisely the same instant" });
    expect(state.event.starts_at).toBe("2036-10-26T01:30:42.123456Z");
    expect(state.event.ends_at).toBe("2036-10-26T02:45:12.654321Z");
  });

  test("changing the display time zone redraws both wall times and preserves exact stored instants", async ({ page }) => {
    const state = await setup(page);
    state.event.starts_at = "2036-06-18T17:30:42.123456Z";
    state.event.ends_at = "2036-06-18T19:45:12.654321Z";
    await page.goto("/manage/events/51");
    const start = page.getByLabel("Start date and time", { exact: true });
    const end = page.getByLabel("End date and time (optional)", { exact: true });
    await expect(start).toHaveValue("2036-06-18T18:30");
    await expect(end).toHaveValue("2036-06-18T20:45");
    await page.getByLabel("Time zone", { exact: true }).selectOption("America/New_York");
    await expect(start).toHaveValue("2036-06-18T13:30");
    await expect(end).toHaveValue("2036-06-18T15:45");
    await page.getByRole("button", { name: "Save changes", exact: true }).click();
    await expect(page.getByRole("status").filter({ hasText: "Changes saved." })).toBeVisible();
    expect(state.writes[0]?.body).toEqual({ timezone: "America/New_York" });
    expect(state.event.starts_at).toBe("2036-06-18T17:30:42.123456Z");
    expect(state.event.ends_at).toBe("2036-06-18T19:45:12.654321Z");
  });

  test("new event time-zone changes preserve the selected instant independently of browser zone", async ({ page }) => {
    const state = await setup(page);
    await page.goto("/manage/events/new");
    await fillDraft(page, "2036-07-01T18:00");
    await page.getByLabel("Time zone", { exact: true }).selectOption("America/New_York");
    await expect(page.getByLabel("Start date and time", { exact: true })).toHaveValue("2036-07-01T13:00");
    await page.getByRole("button", { name: "Create draft", exact: true }).click();
    await expect(page).toHaveURL(/\/manage\/events\/51$/);
    expect(state.writes[0]?.body.timezone).toBe("America/New_York");
    expect(new Date(String(state.writes[0]?.body.starts_at)).toISOString()).toBe("2036-07-01T17:00:00.000Z");
  });

  test("canonical Kolkata choice uses its half-hour offset and omits the incompatible browser alias", async ({ page }) => {
    const state = await setup(page);
    await page.goto("/manage/events/new");
    const zone = page.getByLabel("Time zone", { exact: true });
    await expect(zone.locator('option[value="Asia/Kolkata"]')).toHaveCount(1);
    await expect(zone.locator('option[value="Asia/Calcutta"]')).toHaveCount(0);
    await zone.selectOption("Asia/Kolkata");
    await fillDraft(page, "2036-07-01T18:00");
    await page.getByRole("button", { name: "Create draft", exact: true }).click();
    await expect(page).toHaveURL(/\/manage\/events\/51$/);
    expect(state.writes[0]?.body.timezone).toBe("Asia/Kolkata");
    expect(new Date(String(state.writes[0]?.body.starts_at)).toISOString()).toBe("2036-07-01T12:30:00.000Z");
  });

  test("clearing optional end sends null without rounding the existing start", async ({ page }) => {
    const state = await setup(page);
    state.event.starts_at = "2036-06-18T17:30:42.123456Z";
    state.event.ends_at = "2036-06-18T19:45:12.654321Z";
    await page.goto("/manage/events/51");
    await page.getByLabel("End date and time (optional)", { exact: true }).fill("");
    await page.getByRole("button", { name: "Save changes", exact: true }).click();
    await expect(page.getByRole("status").filter({ hasText: "Changes saved." })).toBeVisible();
    expect(state.writes[0]?.body).toEqual({ ends_at: null });
    expect(state.event.starts_at).toBe("2036-06-18T17:30:42.123456Z");
    expect(state.event.ends_at).toBeNull();
  });

  for (const boundary of [
    { name: "UTC minimum", instant: "1000-01-01T00:00:00.123456Z", timezone: "America/New_York", localYear: /^0999-/ },
    { name: "UTC maximum", instant: "9999-12-31T23:59:59.654321Z", timezone: "Asia/Tokyo", localYear: /^10000-/ },
  ]) {
    test(`${boundary.name} remains editable when its valid local year crosses the UTC storage boundary`, async ({ page }) => {
      const state = await setup(page);
      state.event.starts_at = boundary.instant;
      state.event.timezone = boundary.timezone;
      await page.goto("/manage/events/51");
      await expect(page.getByLabel("Start date and time", { exact: true })).toHaveValue(boundary.localYear);
      await page.getByLabel("Event name", { exact: true }).fill("Boundary metadata correction");
      await page.getByRole("button", { name: "Save changes", exact: true }).click();
      await expect(page.getByRole("heading", { name: "Boundary metadata correction", exact: true })).toBeVisible();
      expect(state.writes[0]?.body).toEqual({ name: "Boundary metadata correction" });
      expect(state.event.starts_at).toBe(boundary.instant);
      expect(state.event.timezone).toBe(boundary.timezone);
    });
  }

  test("server end-order rejection stays on the entered end field without losing the start", async ({ page }) => {
    const state = await setup(page);
    state.event.starts_at = "2036-06-18T17:30:42.123456Z";
    state.validationErrors = { ends_at: ["The end must be later than the start."] };
    await page.goto("/manage/events/51");
    await page.getByLabel("End date and time (optional)", { exact: true }).fill("2036-06-18T18:00");
    await page.getByRole("button", { name: "Save changes", exact: true }).click();
    await expect(page.getByRole("alert")).toContainText("The end must be later than the start.");
    await expect(page.getByLabel("End date and time (optional)", { exact: true })).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByLabel("Start date and time", { exact: true })).toHaveValue("2036-06-18T18:30");
    expect(state.writes[0]?.body).not.toHaveProperty("starts_at");
    expect(new Date(String(state.writes[0]?.body.ends_at)).toISOString()).toBe("2036-06-18T17:00:00.000Z");
  });
});
