"""Regenerate LeggTix's staged extension and race diagrams.

Run: python docs/diagrams/render_expansion_diagrams.py --preview
SVGs use the standard library; PNG previews use an existing rasteriser.
These are proposed designs, not an implemented schema or performance claim.
"""

from __future__ import annotations

import argparse
import importlib.util
import math
from pathlib import Path
import subprocess
import sys
import xml.dom.minidom
from xml.sax.saxutils import escape


# Loading the existing generator must not leave review-only bytecode artifacts.
sys.dont_write_bytecode = True
OUTPUT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("mvp_diagram", OUTPUT / "render_database_er.py")
assert spec and spec.loader
mvp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mvp)
WIDTH = 1200
FONT, INK, GROUND, PRIMARY, MUTED, ACCENT = (
    mvp.FONT, mvp.INK, mvp.GROUND, mvp.PRIMARY, mvp.MUTED, mvp.ACCENT
)
FONT_SIZE = 16


class Diagram:
    def __init__(self, stem: str, height: int, title: str, subtitle: str, alt: str):
        self.stem, self.height, self.alt = stem, height, alt
        self.texts: list[tuple[str, int, int, bool, int]] = []
        self.boxes: list[tuple[int, int, int, int]] = []
        self.paths: list[list[tuple[int, int]]] = []
        self.edge_labels: list[str] = []
        self.parts = [
            f'<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{height}" '
            f'viewBox="0 0 {WIDTH} {height}" role="img" aria-label="{escape(alt)}">',
            f'<title>{escape(title)}</title>', f'<desc>{escape(alt)}</desc>',
            f'<rect x="0" y="0" width="{WIDTH}" height="{height}" fill="{GROUND}"/>',
        ]
        self.label(title, 48, 64, PRIMARY, True)
        self.label(subtitle, 48, 92, MUTED)

    def label(self, value: str, x: int, y: int, color: str = INK,
              bold: bool = False, available: int | None = None) -> None:
        budget = available if available is not None else WIDTH - 48 - x
        assert mvp.fit(value, bold) <= budget, (self.stem, value, budget, mvp.fit(value, bold))
        self.texts.append((value, x, y, bold, budget))
        self.parts.append(mvp.text(value, x, y, color, bold))

    def card(self, x: int, y: int, width: int, lines: list[str],
             color: str = PRIMARY, title: str | None = None,
             dashed: bool = False) -> tuple[int, int, int, int]:
        height = 28 * (len(lines) + bool(title)) + 32
        self.boxes.append((x, y, width, height))
        dash = ' stroke-dasharray="6 4"' if dashed else ""
        self.parts.append(f'<rect x="{x}" y="{y}" width="{width}" height="{height}" '
                          f'rx="8" fill="{GROUND}" stroke="{color}" stroke-width="2"{dash}/>')
        baseline = y + 28
        if title:
            self.label(title, x + 16, baseline, color, True, width - 32)
            baseline += 28
        for line in lines:
            self.label(line, x + 16, baseline, INK, False, width - 32)
            baseline += 28
        return x, y, width, height

    def table(self, name: str, x: int, y: int, width: int,
              rows: list[tuple[str, str]], optional: bool = False) -> tuple[int, int, int, int]:
        height = 48 + 28 * len(rows)
        self.boxes.append((x, y, width, height))
        color = MUTED if optional else PRIMARY
        dash = ' stroke-dasharray="6 4"' if optional else ""
        self.parts.append(f'<rect x="{x}" y="{y}" width="{width}" height="{height}" '
                          f'rx="8" fill="{GROUND}" stroke="{color}" stroke-width="2"{dash}/>')
        self.parts.append(f'<path d="M {x} {y + 48} L {x + width} {y + 48}" '
                          f'fill="none" stroke="{color}" stroke-width="2" stroke-linecap="round"/>')
        self.label(name, x + 16, y + 32, color, True, width - 32)
        for index, (key, field) in enumerate(rows):
            baseline = y + 68 + index * 28
            if key:
                self.label(key, x + 16, baseline, ACCENT, True, 32)
            self.label(field, x + 52, baseline, available=width - 68)
        return x, y, width, height

    def arrow(self, points: list[tuple[int, int]], labels: list[str],
              label_xy: tuple[int, int], color: str = MUTED,
              dashed: bool = False) -> None:
        self.paths.append(points)
        px, py = points[-2]
        ex, ey = points[-1]
        dx, dy = ex - px, ey - py
        assert bool(dx) != bool(dy), points
        if dx:
            step = 1 if dx > 0 else -1
            base = ex - 12 * step
            end = (base + step, ey)
            head = f'M {ex} {ey} L {base} {ey - 5} L {base} {ey + 5} Z'
        else:
            step = 1 if dy > 0 else -1
            base = ey - 12 * step
            end = (ex, base + step)
            head = f'M {ex} {ey} L {ex - 5} {base} L {ex + 5} {base} Z'
        path = "M " + " L ".join(f"{x} {y}" for x, y in points[:-1] + [end])
        dash = ' stroke-dasharray="6 4"' if dashed else ""
        self.parts.extend([
            f'<path d="{path}" fill="none" stroke="{color}" stroke-width="2" '
            f'stroke-linecap="round"{dash}/>',
            f'<path d="{head}" fill="{color}" stroke="{color}" stroke-width="2"/>',
        ])
        lx, ly = label_xy
        label_width = math.ceil(max(mvp.fit(line) for line in labels)) + 8
        label_start = len(self.parts)
        self.parts.append(f'<rect x="{lx - 4}" y="{ly - 20}" width="{label_width}" '
                          f'height="{28 * len(labels)}" rx="4" fill="{GROUND}"/>')
        for i, line in enumerate(labels):
            self.label(line, lx, ly + i * 28, color)
        # Put every edge label above every connector so a later routed edge
        # cannot draw a line through an earlier label's glyphs.
        self.edge_labels.extend(self.parts[label_start:])
        del self.parts[label_start:]

    def finish(self) -> Path:
        assert self.height <= 1560
        assert FONT_SIZE >= WIDTH / 75
        for color in (INK, PRIMARY, MUTED, ACCENT):
            assert mvp.contrast(color, GROUND) >= 4.5
        for x, y, w, h in self.boxes:
            assert x >= 48 and x + w + 2 <= WIDTH - 48
            assert y >= 48 and y + h + 2 <= self.height - 48, (self.stem, (x, y, w, h))
        for value, x, y, bold, _ in self.texts:
            assert x >= 48 and x + mvp.fit(value, bold) <= WIDTH - 48
            assert y - FONT_SIZE >= 48 and y + 4 <= self.height - 48, (self.stem, value, y)
        for points in self.paths:
            assert all(x1 == x2 or y1 == y2 for (x1, y1), (x2, y2) in zip(points, points[1:]))
            assert all(48 <= x <= WIDTH - 48 and 48 <= y <= self.height - 48 for x, y in points)
        self.parts.extend(self.edge_labels)
        self.parts.append("</svg>")
        path = OUTPUT / f"{self.stem}.svg"
        path.write_text("\n".join(self.parts) + "\n", encoding="utf-8")
        dom = xml.dom.minidom.parse(str(path))
        allowed = {"svg", "title", "desc", "rect", "path", "text"}
        assert all(node.tagName in allowed for node in dom.getElementsByTagName("*"))
        assert all(node.getAttribute("font-family") == FONT for node in dom.getElementsByTagName("text"))
        assert all(float(node.getAttribute("font-size")) >= 16 for node in dom.getElementsByTagName("text"))
        assert path.stat().st_size < 100_000
        print(f"Validated {path.name}: {len(self.boxes)} boxes, {len(self.paths)} labelled relationships, "
              f"{len(self.texts)} modelled text fits; {WIDTH}x{self.height}.")
        return path


def last_place() -> Path:
    d = Diagram("leggtix-last-place-race", 1392, "MVP RACE: TWO USERS WANT THE LAST PLACE",
                "Proposed transaction sequence | MySQL's event row decides who can reserve.",
                "With one place left, A locks the event while B waits. A increments confirmed_count and inserts "
                "its reservation in one transaction. After A commits, B reads the current full count and "
                "does not insert. If A rolls back, its increment and reservation both disappear and B can win.")
    d.card(360, 140, 480, ["Illustrative starting state: capacity = 1", "confirmed_count = 0; no active reservation"])
    d.label("USER A / TRANSACTION A", 80, 248, PRIMARY, True)
    d.label("USER B / TRANSACTION B", 656, 248, PRIMARY, True)
    d.card(80, 272, 464, ["A requests a reservation", "Begin transaction"])
    d.card(656, 272, 464, ["B requests a reservation", "Begin transaction"])
    d.card(80, 408, 464, ["A locks the event row FOR UPDATE", "Recheck published/future + requesting user,", "existing reservation and waitlist priority"])
    d.card(656, 408, 464, ["B tries the same event row lock", "MySQL makes B wait for A", "B does not decide from an earlier count"])
    d.card(80, 580, 464, ["A conditionally increments confirmed_count", "Guard: confirmed_count < capacity", "Insert reservation in the SAME transaction"])
    d.card(80, 752, 464, ["A commits count = 1 + reservation", "Release the event lock", "Dispatch side effects after commit"])
    d.card(656, 752, 464, ["B acquires the event lock", "Read CURRENT confirmed_count = 1", "Recheck all rules under the lock"])
    d.card(656, 924, 464, ["B sees a full event", "Do not increment or insert a reservation", "Commit the documented full outcome"], ACCENT)
    d.arrow([(312, 360), (312, 408)], ["locks event"], (92, 392))
    d.arrow([(888, 360), (888, 408)], ["same event"], (668, 392))
    d.arrow([(312, 524), (312, 580)], ["checks pass"], (92, 560))
    d.arrow([(312, 696), (312, 752)], ["both writes succeed"], (92, 732))
    d.arrow([(888, 524), (888, 752)], ["A commits first"], (900, 632))
    d.arrow([(544, 800), (656, 800)], ["commit unlocks"], (520, 732))
    d.arrow([(888, 868), (888, 924)], ["fresh count is full"], (668, 908))
    d.label("ALTERNATIVE: A FAILS BEFORE COMMIT", 80, 1060, ACCENT, True)
    d.card(80, 1084, 464, ["MySQL rolls A's transaction back", "Undo the count increment AND inserted row", "Release the event lock; count returns to 0"], ACCENT)
    d.card(656, 1084, 464, ["B acquires the lock and reads count = 0", "B rechecks rules and can reserve", "B increments + inserts atomically, then commits"], ACCENT)
    d.arrow([(80, 636), (48, 636), (48, 1140), (80, 1140)], ["if A fails before commit"], (60, 1040), ACCENT)
    d.arrow([(312, 1200), (312, 1248), (888, 1248), (888, 1200)],
            ["rollback unlocks; B reads the restored state"], (400, 1236), ACCENT)
    d.label("Never use Redis availability or a count-then-insert check as the capacity authority.", 48, 1300, MUTED)
    d.label("This diagram explains correctness; hot-event lock wait and throughput require real MySQL tests.", 48, 1328, MUTED)
    return d.finish()


def seat_race() -> Path:
    d = Diagram("leggtix-seat-race", 1420, "FUTURE RACE: TWO USERS WANT THE SAME SEAT",
                "Proposed extension | Event lock first, then persistent seat rows in ascending ID order.",
                "A and B want the same event seat. A locks the event and seat, creates a current hold and "
                "commits. B waits, then reads the held seat and cannot claim it. Expiry and verified payment "
                "finalisation must use the same lock and current-hold token/version guards.")
    d.card(360, 140, 480, ["Illustrative seat #42: available", "UNIQUE(event_id, venue_seat_id) row exists"])
    d.label("USER A / TRANSACTION A", 80, 248, PRIMARY, True)
    d.label("USER B / TRANSACTION B", 656, 248, PRIMARY, True)
    d.card(80, 272, 464, ["A requests seat #42", "Begin transaction"])
    d.card(656, 272, 464, ["B requests seat #42", "Begin transaction"])
    d.card(80, 408, 464, ["A locks event, then seat #42 FOR UPDATE", "Recheck event rules and current availability", "For a group: lock sorted seats, all or nothing"])
    d.card(656, 408, 464, ["B waits at the same event gate", "B cannot bypass the seat-row lock", "An earlier availability response gives no claim"])
    d.card(80, 580, 464, ["A changes available -> held", "Set current_hold_id = H_A; increment version", "Hold records its configured expiry"])
    d.card(80, 752, 464, ["A commits the hold and seat transition", "Release locks; make provider calls outside txn", "Retries use the same request/hold identity"])
    d.card(656, 752, 464, ["B locks event, then seat #42", "Read CURRENT state: held by H_A", "A's live hold prevents B taking this seat"])
    d.card(656, 924, 464, ["B commits the unavailable outcome", "B may choose a different available seat", "No second hold can own seat #42"], ACCENT)
    d.arrow([(312, 360), (312, 408)], ["locks in order"], (92, 392))
    d.arrow([(888, 360), (888, 408)], ["same event + seat"], (668, 392))
    d.arrow([(312, 524), (312, 580)], ["available now"], (92, 560))
    d.arrow([(312, 696), (312, 752)], ["hold + seat succeed"], (92, 732))
    d.arrow([(888, 524), (888, 752)], ["A commits first"], (900, 632))
    d.arrow([(544, 800), (656, 800)], ["commit unlocks"], (520, 732))
    d.arrow([(888, 868), (888, 924)], ["current hold is live"], (668, 908))
    d.label("LATER TRANSITIONS KEEP THE SAME OWNERSHIP GUARD", 80, 1060, ACCENT, True)
    d.card(80, 1084, 464, ["Expiry worker locks event + seat", "Release only if hold ID AND version still match", "and that current hold is expired", "A stale expiry job cannot release B's new hold"], ACCENT, "Expiry / cancellation")
    d.card(656, 1084, 464, ["Verified provider event triggers finalisation", "Lock event + seat; check CURRENT hold token", "If eligible, held -> sold for this order item", "A late paid A cannot take B's seat: refund/review"], ACCENT, "Payment finalisation")
    d.label("Initial extension retains the event gate: different-seat throughput is a later measured redesign.", 48, 1312, MUTED)
    d.label("No hold duration, payment provider, or throughput target is fixed by this research diagram.", 48, 1340, MUTED)
    return d.finish()


def venues_seating() -> Path:
    d = Diagram("leggtix-venues-seating-er", 1440, "PROPOSED EXTENSION: VENUES + ASSIGNED SEATING",
                "Each event is one occurrence; physical seats persist and inventory belongs to that occurrence.",
                "Venues contain sections and physical seats. Events optionally reference a venue. Each event "
                "has occurrence-specific event seats referencing physical seats. Unique event and physical "
                "seat pairs protect inventory identity; current hold and sold order item links connect checkout.")
    d.table("venues", 80, 164, 464, [("PK", "id"), ("", "name"), ("", "timezone"), ("", "capacity_ceiling?")])
    d.table("events (extends MVP)", 656, 164, 464, [("PK", "id"), ("FK", "owner_id; event_type_id"), ("FK", "venue_id?"),
            ("", "capacity"), ("", "inventory_mode: GA | assigned"), ("", "sales_mode: free | paid")])
    d.table("venue_sections", 80, 520, 464, [("PK", "id"), ("FK", "venue_id"), ("", "code"), ("UK", "(venue_id, code)")])
    d.table("venue_seats", 80, 900, 464, [("PK", "id"), ("FK", "venue_section_id"), ("", "row_label; seat_label"),
            ("UK", "(venue_section_id, row_label, seat_label)")])
    d.table("event_seats", 656, 860, 464, [("PK", "id"), ("FK", "event_id; venue_seat_id"), ("UK", "(event_id, venue_seat_id)"),
            ("", "status: available | held | sold"), ("FK", "current_hold_id? -> inventory_holds"), ("", "version"),
            ("FK", "current_order_item_id? -> order_items")])
    d.arrow([(312, 164), (312, 128), (888, 128), (888, 164)],
            ["venue_id | event has 0..1 venue; venue has 0..* events"], (400, 116))
    d.arrow([(312, 324), (312, 520)], ["venue_id | 1 -> 0..* sections"], (324, 432))
    d.arrow([(312, 680), (312, 900)], ["venue_section_id | 1 -> 0..* seats"], (324, 804))
    d.arrow([(888, 380), (888, 860)], ["event_id | 1 -> 0..* event seats"], (704, 684))
    d.arrow([(312, 1060), (312, 1212), (888, 1212), (888, 1104)],
            ["venue_seat_id | physical seat 1 -> 0..* occurrence inventory rows"], (352, 1200))
    d.label("PK primary key | FK foreign key | UK unique key | ? nullable | Key field subsets shown.", 48, 1292, MUTED)
    d.label("GA = general_admission; assigned = assigned_seating. Modes remain separate from free/paid.", 48, 1320, MUTED)
    d.label("Seat allocation locks the event first; current_hold_id + version guard expiry and payment retries.", 48, 1348, MUTED)
    d.label("Venue changes after sale require a separate policy; do not silently remap sold seat inventory.", 48, 1376, MUTED)
    return d.finish()


def teams_roles() -> Path:
    d = Diagram("leggtix-teams-roles-er", 1560, "PROPOSED EXTENSION: TEAMS + MULTIPLE USER ROLES",
                "Customer abilities stay shared; event access comes from ownership or scoped team grants.",
                "Users own events and create teams. Team memberships connect users to teams; event team "
                "grants connect teams to individual event capabilities. An optional later roles and user_roles "
                "pair replaces users.role after backfill; it permits customer and organiser roles together.")
    d.table("users (MVP)", 80, 164, 464, [("PK", "id"), ("", "role (current single-role field)")])
    d.table("teams", 656, 164, 464, [("PK", "id"), ("", "name"), ("FK", "created_by -> users.id")])
    d.table("team_memberships", 360, 440, 480, [("PK", "(team_id, user_id)"), ("FK", "team_id; user_id"),
            ("", "team_role: manager | member")])
    d.table("events (MVP)", 80, 764, 464, [("PK", "id"), ("FK", "owner_id -> users.id")])
    d.table("event_team_grants", 656, 764, 464, [("PK", "id"), ("FK", "event_id; team_id"),
            ("", "capability: edit_event | view_attendees"), ("", "or check_in (one capability per row)"),
            ("UK", "(event_id, team_id, capability)")])
    d.arrow([(312, 164), (312, 128), (888, 128), (888, 164)],
            ["created_by | user 1 -> 0..* created teams"], (444, 116))
    d.arrow([(432, 268), (432, 360), (480, 360), (480, 440)],
            ["user_id | user 1 -> 0..* memberships"], (84, 348))
    d.arrow([(888, 296), (888, 388), (720, 388), (720, 440)],
            ["team_id | team 1 -> 0..* memberships"], (772, 376))
    d.arrow([(80, 216), (48, 216), (48, 816), (80, 816)],
            ["owner_id | user 1 -> 0..* owned events"], (60, 704))
    d.arrow([(1040, 296), (1040, 764)], ["team_id | team 1 -> 0..* event grants"], (772, 660))
    d.arrow([(312, 868), (312, 1028), (888, 1028), (888, 952)],
            ["event_id | event 1 -> 0..* team grants"], (408, 1016))
    d.label("OPTIONAL LATER ROLE REPLACEMENT (dashed)", 80, 1112, MUTED, True)
    d.table("roles", 80, 1140, 464, [("PK", "id"), ("UK", "slug")], optional=True)
    d.table("user_roles", 656, 1140, 464, [("PK", "(user_id, role_id)"), ("FK", "user_id; role_id"),
            ("FK", "assigned_by? -> users.id")], optional=True)
    d.arrow([(312, 1244), (312, 1312), (832, 1312), (832, 1272)],
            ["role_id | role 1 -> 0..* user assignments"], (416, 1300), dashed=True)
    d.arrow([(208, 268), (208, 324), (1152, 324), (1152, 1356), (992, 1356), (992, 1272)],
            ["user_id | user 1 -> 0..* role assignments"], (760, 1344), dashed=True)
    d.label("PK primary key | FK foreign key | UK unique key | ? nullable | Key field subsets shown.", 48, 1416, MUTED)
    d.label("Team membership alone grants no event rights; membership + the specific event grant authorize access.", 48, 1444, MUTED)
    d.label("Owner/admin controls event grants. Optional user_roles replaces users.role after a deliberate backfill.", 48, 1472, MUTED)
    d.label("assigned_by is an optional audit FK to users; audit link omitted from relationship lines for readability.", 48, 1500, MUTED)
    return d.finish()


def paid_checkout() -> Path:
    d = Diagram("leggtix-paid-checkout-er", 1560, "PROPOSED EXTENSION: PAID CHECKOUT + TICKET ISSUANCE",
                "One item = one admission | Inventory holds, provider money state, and admission tickets are distinct.",
                "Ticket types classify held inventory. Holds contain inventory hold items and each hold has at "
                "most one order. Orders contain copied-price order items and payment attempts; sold items issue "
                "at most one ticket. Attempts receive provider events and can have refunds. Outbox messages "
                "support durable recovery without becoming the inventory or money authority.")
    d.table("ticket_types", 80, 176, 304, [("PK", "id"), ("FK", "event_id"), ("", "unit_amount_minor"), ("", "currency")])
    d.table("inventory_holds", 448, 176, 304, [("PK", "id"), ("FK", "event_id; user_id"), ("", "status; expires_at"), ("UK", "request_key (scoped)")])
    d.table("orders", 816, 176, 304, [("PK", "id"), ("FK", "event_id; user_id"), ("FK", "hold_id (UNIQUE)"),
            ("", "status; total_minor"), ("", "currency; financial_status")])
    d.table("tickets", 80, 592, 304, [("PK", "id"), ("FK", "order_item_id (UNIQUE)"), ("FK", "attendee_user_id?"),
            ("UK", "credential_hash"), ("", "status")])
    d.table("inventory_hold_items", 448, 592, 304, [("PK", "id"), ("FK", "hold_id"), ("FK", "ticket_type_id"), ("FK", "event_seat_id?")])
    d.table("order_items", 816, 592, 304, [("PK", "id"), ("FK", "order_id"), ("FK", "hold_item_id (UNIQUE)"),
            ("FK", "ticket_type_id"), ("FK", "event_seat_id?"), ("", "unit_amount_minor"), ("", "currency (snapshot)")])
    d.table("refunds", 80, 1036, 304, [("PK", "id"), ("FK", "payment_attempt_id"), ("", "provider; provider_refund_id?"),
            ("UK", "provider + idempotency_key"), ("", "amount_minor; status")])
    d.table("payment_provider_events", 448, 1036, 304, [("PK", "id"), ("FK", "payment_attempt_id?"), ("", "provider; status"),
            ("UK", "provider + event ID")])
    d.table("payment_attempts", 816, 1036, 304, [("PK", "id"), ("FK", "order_id"), ("", "provider_object_id?"),
            ("UK", "provider + idempotency_key"), ("UK", "open_order_id? (generated)")])
    d.arrow([(600, 336), (600, 464), (968, 464), (968, 364)],
            ["hold_id UNIQUE | hold 1 -> 0..1 order"], (640, 452))
    d.arrow([(600, 336), (600, 592)], ["hold_id | 1 -> 0..* items"], (464, 536))
    d.arrow([(232, 336), (232, 416), (520, 416), (520, 592)],
            ["ticket_type_id | 1 -> 0..* held items"], (244, 404))
    d.arrow([(80, 256), (48, 256), (48, 884), (1080, 884), (1080, 836)],
            ["ticket_type_id | 1 -> 0..* sold items"], (80, 872))
    d.arrow([(1064, 364), (1064, 592)], ["order_id | 1 -> 0..* items"], (856, 556))
    d.arrow([(600, 752), (600, 936), (968, 936), (968, 836)],
            ["hold_item_id UNIQUE | 1 -> 0..1 sold item"], (640, 924))
    d.arrow([(984, 592), (984, 572), (232, 572), (232, 592)],
            ["order_item_id UNIQUE | 1 -> 0..1 ticket"], (92, 560))
    d.arrow([(1120, 284), (1152, 284), (1152, 980), (1032, 980), (1032, 1036)],
            ["order_id | 1 -> 0..* payment attempts"], (840, 1008))
    d.arrow([(984, 1224), (984, 1288), (232, 1288), (232, 1224)],
            ["payment_attempt_id | 1 -> 0..* refunds"], (408, 1276))
    d.arrow([(900, 1224), (900, 1328), (600, 1328), (600, 1196)],
            ["payment_attempt_id? | 0..1 attempt per provider event"], (676, 1316))
    d.table("outbox_messages (recovery)", 80, 1320, 464, [("PK", "id"), ("", "topic; aggregate_id; status"), ("UK", "deduplication_key")])
    d.label("PK primary | FK foreign | UK unique | ? nullable | External user/event/seat FKs shown as fields.", 48, 1476, MUTED)
    d.label("Verified provider events drive guarded transitions; no provider calls inside MySQL transactions.", 48, 1504, MUTED)
    return d.finish()


def render_preview(svg_path: Path) -> None:
    """Use the existing rasteriser fallback without changing its output globals."""
    png_path = svg_path.with_suffix(".png")
    runtime_root = Path(sys.executable).resolve().parents[1]
    node = runtime_root / "node" / "bin" / "node.exe"
    sharp = runtime_root / "node" / "node_modules" / "sharp"
    if not node.is_file() or not sharp.is_dir():
        print(f"Preview {svg_path.name}: bundled Node sharp unavailable; SVG retained.")
        return
    js = ('const sharp = require(process.argv[1]); '
          'sharp(process.argv[2]).png().toFile(process.argv[3])'
          '.catch(error => { process.stderr.write(error.message); process.exitCode = 1; });')
    result = subprocess.run([str(node), "-e", js, str(sharp), str(svg_path), str(png_path)],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45)
    assert result.returncode == 0 and png_path.is_file(), result.stderr.decode(errors="replace")
    print(f"Rendered {png_path.name} with bundled Node sharp.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--preview", action="store_true", help="Render PNGs with bundled Node sharp.")
    args = parser.parse_args()
    paths = [last_place(), seat_race(), venues_seating(), teams_roles(), paid_checkout()]
    print("Contrast against white: " + ", ".join(
        f"{color} {mvp.contrast(color, GROUND):.2f}:1" for color in (INK, PRIMARY, MUTED, ACCENT)))
    if args.preview:
        for path in paths:
            render_preview(path)
