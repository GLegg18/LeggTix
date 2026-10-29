export type EventTypeChoice = { id: number; slug: string; name: string; is_active?: boolean };
export type ManagedEvent = {
  id: number;
  owner_id: number;
  name: string;
  description: string;
  venue: string;
  starts_at: string;
  ends_at: string | null;
  timezone: string;
  capacity: number;
  confirmed_count: number;
  remaining_capacity: number;
  status: "draft" | "published" | "cancelled" | "completed";
  has_history: boolean;
  event_type: EventTypeChoice;
};
export type CursorPage<T> = {
  data: T[];
  meta: { next_cursor: string | null; prev_cursor: string | null };
};
export type ManagedAttempt = {
  id: number;
  user_id: number;
  user: { id: number; name: string };
  status: string;
  created_at: string;
  joined_at?: string;
};
export function eventSchedule(event: ManagedEvent): string {
  return new Intl.DateTimeFormat("en-GB", {
    dateStyle: "medium", timeStyle: "short", timeZone: event.timezone,
  }).format(new Date(event.starts_at)) + ` (${event.timezone})`;
}
