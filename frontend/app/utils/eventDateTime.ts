import { Temporal } from "@js-temporal/polyfill";
import eventTimeZones from "~/data/eventTimeZones.json";

export type TimeOccurrence = "earlier" | "later";
export type DateTimeCandidate = {
  occurrence: TimeOccurrence;
  instant: string;
  offset: string;
  label: string;
};
export type LocalDateTimeInspection = {
  kind: "empty" | "invalid" | "gap" | "ambiguous" | "resolved";
  candidates: DateTimeCandidate[];
};
export type ScheduleValue = {
  local: string;
  occurrence: TimeOccurrence | "";
  preservedInstant: string;
};

/** Native controls contain a wall time; its meaning comes from the event zone. */
export function inspectLocalDateTime(value: string, timezone: string): LocalDateTimeInspection {
  if (!value) return { kind: "empty", candidates: [] };
  const parts = /^(\d{4,})-(\d{2}-\d{2}T\d{2}:\d{2})$/.exec(value);
  if (!parts) return { kind: "invalid", candidates: [] };
  try {
    // HTML uses unsigned variable-length years; Temporal uses signed six-digit expanded years.
    const year = Number(parts[1]);
    if (!Number.isSafeInteger(year) || year < 1) return { kind: "invalid", candidates: [] };
    const temporalYear = year > 9999 ? `+${String(year).padStart(6, "0")}` : String(year).padStart(4, "0");
    const local = Temporal.PlainDateTime.from(`${temporalYear}-${parts[2]}`, { overflow: "reject" });
    const earlier = local.toZonedDateTime(timezone, { disambiguation: "earlier" });
    const later = local.toZonedDateTime(timezone, { disambiguation: "later" });
    // Both policies shift nonexistent times. Neither shifted value may be accepted.
    if (!earlier.toPlainDateTime().equals(local) || !later.toPlainDateTime().equals(local)) {
      return { kind: "gap", candidates: [] };
    }
    const candidate = (date: typeof earlier, occurrence: TimeOccurrence): DateTimeCandidate => ({
      occurrence,
      instant: date.toInstant().toString(),
      offset: date.offset,
      label: `${occurrence === "earlier" ? "First" : "Second"} occurrence (UTC${date.offset})`,
    });
    if (Temporal.Instant.compare(earlier.toInstant(), later.toInstant()) === 0) {
      return { kind: "resolved", candidates: [candidate(earlier, "earlier")] };
    }
    return { kind: "ambiguous", candidates: [candidate(earlier, "earlier"), candidate(later, "later")] };
  } catch {
    return { kind: "invalid", candidates: [] };
  }
}

export function formatLocalDateTime(instant: string, timezone: string): string {
  const local = Temporal.Instant.from(instant).toZonedDateTimeISO(timezone).toPlainDateTime();
  const formatted = local.toString({ smallestUnit: "minute" });
  return local.year > 9999 ? `${local.year}${formatted.slice(formatted.indexOf("-"))}` : formatted;
}

/** Preserve precision which the minute-based editor deliberately does not expose. */
export function scheduleValue(instant: string | null | undefined, timezone: string): ScheduleValue {
  if (!instant) return { local: "", occurrence: "", preservedInstant: "" };
  const local = formatLocalDateTime(instant, timezone);
  const inspection = inspectLocalDateTime(local, timezone);
  const offset = Temporal.Instant.from(instant).toZonedDateTimeISO(timezone).offset;
  const occurrence = inspection.kind === "ambiguous"
    ? inspection.candidates.find(candidate => candidate.offset === offset)?.occurrence ?? ""
    : "";
  return { local, occurrence, preservedInstant: instant };
}

export function resolveLocalDateTime(
  value: string,
  timezone: string,
  occurrence: TimeOccurrence | "" = "",
  preservedInstant = "",
): { instant: string | null; error?: string } {
  const inspection = inspectLocalDateTime(value, timezone);
  if (inspection.kind === "empty") return { instant: null };
  if (inspection.kind === "invalid") return { instant: null, error: "Enter a valid date and time." };
  if (inspection.kind === "gap") {
    return { instant: null, error: "This time does not exist because the clocks move forward. Choose a time before or after the clock change." };
  }
  const candidate = inspection.kind === "ambiguous"
    ? inspection.candidates.find(item => item.occurrence === occurrence)
    : inspection.candidates[0];
  if (!candidate) return { instant: null, error: "The clocks move back at this time. Choose the first or second occurrence." };
  try {
    let instant = candidate.instant;
    if (preservedInstant && formatLocalDateTime(preservedInstant, timezone) === value
      && Temporal.Instant.from(preservedInstant).toZonedDateTimeISO(timezone).offset === candidate.offset) {
      instant = preservedInstant;
    }
    const utcYear = Temporal.Instant.from(instant).toZonedDateTimeISO("UTC").year;
    if (utcYear < 1000 || utcYear > 9999) {
      return { instant: null, error: "Choose a date between the years 1000 and 9999 after applying its time zone." };
    }
    return { instant };
  } catch {
    return { instant: null, error: "Enter a valid date and time." };
  }
}

export function scheduleTimeZones(current = "Europe/London"): { value: string; label: string }[] {
  // Match the configured PHP validator; browser zone lists can expose rejected aliases.
  const supported = eventTimeZones as string[];
  return [...new Set([...(supported.includes(current) ? [current] : []), ...supported])].map(value => {
    const parts = value.split("/");
    const city = parts.pop()!.replaceAll("_", " ");
    return { value, label: value === "UTC" ? "Coordinated Universal Time (UTC)" : `${city}${parts.length ? ` (${parts.join(" / ").replaceAll("_", " ")})` : ""}` };
  }).sort((a, b) => a.label.localeCompare(b.label));
}
