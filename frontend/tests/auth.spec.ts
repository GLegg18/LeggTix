import { expect, test, type Page, type Route } from "@playwright/test";

const person = {
  id: 17,
  name: "Jamie Example",
  email: "jamie@example.test",
  role: "regular_user",
};
const challenge = "a".repeat(64);
const expires = () => new Date(Date.now() + 600000).toISOString();
const json = (route: Route, status: number, body: unknown) =>
  route.fulfill({
    status,
    contentType: "application/json",
    body: JSON.stringify(body),
  });

async function setup(page: Page) {
  const state = {
    authenticated: false,
    csrfCalls: 0,
    writes: [] as {
      path: string;
      body: Record<string, unknown>;
      csrf?: string;
    }[],
  };
  await page.route("**/sanctum/csrf-cookie", async (route) => {
    state.csrfCalls++;
    await route.fulfill({
      status: 204,
      headers: {
        "Set-Cookie": "XSRF-TOKEN=csrf%3Dvalue; Path=/; SameSite=Lax",
      },
    });
  });
  await page.route("**/api/spa/**", async (route) => {
    const path = new URL(route.request().url()).pathname.replace(
      "/api/spa/",
      "",
    );
    if (route.request().method() === "GET") {
      await json(
        route,
        state.authenticated ? 200 : 401,
        state.authenticated
          ? { data: person }
          : { message: "Unauthenticated." },
      );
      return;
    }
    const body = route.request().postDataJSON() ?? {};
    state.writes.push({
      path,
      body,
      csrf: route.request().headers()["x-xsrf-token"],
    });
    if (path === "logout") {
      state.authenticated = false;
      await route.fulfill({ status: 204 });
      return;
    }
    if (path === "registration/email-verifications" || path === "login") {
      await json(route, 202, {
        challenge_id: challenge,
        expires_at: expires(),
      });
      return;
    }
    if (path.startsWith("registration/email-verifications/")) {
      await json(route, 200, {
        registration_token: "b".repeat(64),
        expires_at: expires(),
      });
      return;
    }
    if (path === "register" || path.startsWith("login/email-verifications/")) {
      state.authenticated = true;
      await json(route, path === "register" ? 201 : 200, { user: person });
      return;
    }
    await route.fulfill({ status: 204 });
  });
  return state;
}

test("registration verifies email first, preserves a zero-prefixed code, and keeps credentials out of storage", async ({
  page,
}) => {
  const state = await setup(page);
  await page.goto("/register");
  await page.getByLabel("Email address", { exact: true }).fill(person.email);
  await page.getByRole("button", { name: "Send verification code" }).click();
  await expect(
    page.getByRole("heading", { name: "Check your inbox." }),
  ).toBeVisible();
  await page.getByLabel("Six-digit code").fill("012345");
  await page.getByRole("button", { name: "Verify email", exact: true }).click();
  await page.getByLabel("Your name", { exact: true }).fill(person.name);
  await page
    .getByLabel("Password", { exact: true })
    .fill("a long memorable password");
  await page
    .getByLabel("Confirm password", { exact: true })
    .fill("a long memorable password");
  await page
    .getByRole("button", { name: "Create account", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: `Hello, ${person.name}.` }),
  ).toBeVisible();
  await expect(page).toHaveURL(/\/account\?welcome=1$/);
  expect(state.csrfCalls).toBe(1);
  expect(state.writes.every((write) => write.csrf === "csrf=value")).toBe(true);
  expect(state.writes[1]?.body.code).toBe("012345");
  expect(state.writes[2]?.body.registration_token).toBe("b".repeat(64));
  const storage = await page.evaluate(() =>
    JSON.stringify({
      local: { ...localStorage },
      session: { ...sessionStorage },
    }),
  );
  expect(storage).not.toContain("registration_token");
  expect(storage).not.toContain("memorable password");
  expect(storage).not.toContain("access_token");
});

test("email-factor login restores the cookie session on reload and logout clears the account", async ({
  page,
}) => {
  const state = await setup(page);
  await page.goto("/login");
  await page.getByLabel("Email address", { exact: true }).fill(person.email);
  await page.getByLabel("Password", { exact: true }).fill("password-for-login");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByLabel("Six-digit code").fill("012345");
  await page.getByLabel("Remember this browser for 7 days").check();
  await page.getByRole("button", { name: "Verify and sign in" }).click();
  await expect(
    page.getByRole("heading", { name: `Hello, ${person.name}.` }),
  ).toBeVisible();
  expect(state.writes[1]?.body).toEqual({
    code: "012345",
    remember_device: true,
  });
  await page.reload();
  await expect(page.getByText(person.email, { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(
    page.getByRole("heading", { name: "Good to see you." }),
  ).toBeVisible();
  expect(state.authenticated).toBe(false);
});

test("server field errors are announced and focused; rate limits show a disabled retry countdown", async ({
  page,
}) => {
  await setup(page);
  let attempt = 0;
  await page.route("**/api/spa/login", async (route) => {
    attempt++;
    if (attempt === 1) {
      await json(route, 422, {
        message: "The provided credentials are incorrect.",
        errors: { email: ["The provided credentials are incorrect."] },
      });
      return;
    }
    await route.fulfill({
      status: 429,
      contentType: "application/json",
      headers: { "Retry-After": "5" },
      body: JSON.stringify({ message: "Too many attempts." }),
    });
  });
  await page.goto("/login");
  await page.getByLabel("Email address", { exact: true }).fill(person.email);
  await page.getByLabel("Password", { exact: true }).fill("incorrect-password");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator("[data-error-summary]")).toBeFocused();
  await expect(
    page.getByLabel("Email address", { exact: true }),
  ).toHaveAttribute("aria-invalid", "true");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("button", { name: /Try again in \ds/ }),
  ).toBeDisabled();
  await expect(page.getByRole("alert")).toContainText("wait 5 seconds");
});

test("expired CSRF is refreshed once before safely retrying the rejected write", async ({
  page,
}) => {
  const state = await setup(page);
  let calls = 0;
  await page.route("**/api/spa/login", async (route) => {
    calls++;
    if (calls === 1) {
      await json(route, 419, { message: "CSRF token mismatch." });
      return;
    }
    await json(route, 202, { challenge_id: challenge, expires_at: expires() });
  });
  await page.goto("/login");
  await page.getByLabel("Email address", { exact: true }).fill(person.email);
  await page.getByLabel("Password", { exact: true }).fill("password-for-login");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.getByLabel("Six-digit code")).toBeVisible();
  expect(calls).toBe(2);
  expect(state.csrfCalls).toBe(2);
});

test("theme persists, layouts fit the viewport, and unknown URLs offer a useful recovery", async ({
  page,
}, testInfo) => {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  await setup(page);
  await page.emulateMedia({ colorScheme: "dark" });
  await page.goto("/");
  const themeSwitch = page.getByRole("switch", { name: "Dark mode" });
  await expect(themeSwitch).toHaveAttribute("aria-checked", "true");
  await page.keyboard.press("Tab");
  await expect(page.getByRole("link", { name: "Skip to content" })).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page.locator("main")).toBeFocused();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await page.emulateMedia({ colorScheme: "light" });
  await expect(themeSwitch).toHaveAttribute("aria-checked", "false");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await page.emulateMedia({ colorScheme: "dark" });
  await expect(themeSwitch).toHaveAttribute("aria-checked", "true");

  await themeSwitch.click();
  await expect(themeSwitch).toHaveAttribute("aria-checked", "false");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  expect(await page.evaluate(() => localStorage.getItem("leggtix-theme"))).toBe("light");
  await page.emulateMedia({ colorScheme: "light" });
  await page.emulateMedia({ colorScheme: "dark" });
  await expect(themeSwitch).toHaveAttribute("aria-checked", "false");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await page.locator("header").screenshot({
    path: `test-results/previews/theme-header-light-${testInfo.project.name}.png`,
  });

  await themeSwitch.focus();
  await page.keyboard.press("Space");
  await expect(themeSwitch).toHaveAttribute("aria-checked", "true");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await page.keyboard.press("Enter");
  await expect(themeSwitch).toHaveAttribute("aria-checked", "false");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await themeSwitch.click();
  await page.reload();
  await expect(themeSwitch).toHaveAttribute("aria-checked", "true");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  expect(await page.evaluate(() => localStorage.getItem("leggtix-theme"))).toBe("dark");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  const missing = await page.goto("/somewhere-else");
  expect(missing?.status()).toBe(404);
  await expect(
    page.getByRole("heading", { name: /This isn’t the place/ }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Back to home" }).click();
  await expect(page.getByRole("heading", { name: /Make room/ })).toBeVisible();
  await expect(themeSwitch).toHaveAttribute("aria-checked", "true");
  await page.locator("header").screenshot({
    path: `test-results/previews/theme-header-dark-${testInfo.project.name}.png`,
  });
  expect(pageErrors).toEqual([]);
});

test("a slow anonymous session check cannot overwrite a completed sign-in", async ({ page }) => {
  await setup(page);
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => { release = resolve; });
  await page.route("**/api/spa/user", async (route) => {
    await gate;
    await json(route, 401, { message: "Unauthenticated." });
  });
  await page.route("**/api/spa/login", (route) => json(route, 200, { user: person }));
  await page.goto("/login");
  await page.getByLabel("Email address", { exact: true }).fill(person.email);
  await page.getByLabel("Password", { exact: true }).fill("password-for-login");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.getByRole("heading", { name: `Hello, ${person.name}.` })).toBeVisible();
  const completed = page.waitForResponse("**/api/spa/user");
  release();
  await completed;
  await expect(page.getByText(person.email, { exact: true })).toBeVisible();
});
