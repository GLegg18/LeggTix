"""Regenerate the proposed LeggTix MVP ER diagram using only Python's standard library.

Run: python docs/diagrams/render_database_er.py
Add --preview to try available SVG rasterisers in the Diagram Builder fallback order.
The SVG is the source deliverable; the optional PNG is only a review preview.
"""

from __future__ import annotations

import argparse
import importlib.util
import math
from pathlib import Path
import shutil
import subprocess
import sys
import xml.dom.minidom
from xml.sax.saxutils import escape


OUTPUT = Path(__file__).resolve().parent
SVG_PATH = OUTPUT / "leggtix-database-er.svg"
PNG_PATH = OUTPUT / "leggtix-database-er.png"
WIDTH, HEIGHT = 1200, 1512
FONT = "Helvetica, Arial, 'Liberation Sans', sans-serif"
INK, GROUND, PRIMARY, MUTED, ACCENT = (
    "#0F172A", "#FFFFFF", "#1D4ED8", "#475569", "#0F766E"
)
FONT_SIZE = 16

# Adobe Helvetica/Arial AFM widths, with the Diagram Builder safety budget.
REG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,
       556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,
       722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,
       278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584]
BLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,
       556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,
       722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,
       333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584]


def fit(value: str, bold: bool = False) -> float:
    widths = BLD if bold else REG
    afm = sum(widths[ord(ch) - 32] if 32 <= ord(ch) <= 126 else 556 for ch in value)
    return afm * FONT_SIZE / 1000 * 1.03 + 4


def luminance(color: str) -> float:
    def channel(value: int) -> float:
        c = value / 255
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    rgb = [channel(int(color[i:i + 2], 16)) for i in (1, 3, 5)]
    return sum(v * weight for v, weight in zip(rgb, (0.2126, 0.7152, 0.0722)))


def contrast(a: str, b: str) -> float:
    low, high = sorted((luminance(a), luminance(b)))
    return (high + 0.05) / (low + 0.05)


ENTITIES = [
    dict(name="users", x=80, y=164, w=464, rows=[
        ("PK", "id"), ("", "name"), ("UK", "email"), ("", "password"),
        ("", "role: regular_user | organiser | admin"), ("", "created_at"), ("", "updated_at"),
    ]),
    dict(name="event_types", x=656, y=164, w=464, rows=[
        ("PK", "id"), ("UK", "slug"), ("", "name"), ("", "is_active"),
    ]),
    dict(name="events", x=360, y=520, w=480, rows=[
        ("PK", "id"), ("FK", "owner_id -> users.id"), ("FK", "event_type_id -> event_types.id"),
        ("", "name"), ("", "description"), ("", "venue"), ("", "starts_at"),
        ("", "ends_at?"), ("", "timezone"), ("", "capacity"),
        ("", "confirmed_count: INT UNSIGNED = 0"), ("", "status"),
        ("", "created_at"), ("", "updated_at"),
    ]),
    dict(name="reservations", x=80, y=1052, w=464, rows=[
        ("PK", "id"), ("FK", "event_id -> events.id"), ("FK", "user_id -> users.id"),
        ("", "status: confirmed | cancelled"), ("", "cancelled_at?"),
        ("", "active_user_id? (generated)"), ("UK", "(event_id, active_user_id)"),
        ("", "created_at, updated_at"),
    ]),
    dict(name="waitlist_entries", x=656, y=1052, w=464, rows=[
        ("PK", "id"), ("FK", "event_id -> events.id"), ("FK", "user_id -> users.id"),
        ("", "status: waiting | promoted | cancelled"), ("", "joined_at"),
        ("", "promoted_at?"), ("", "cancelled_at?"), ("", "waiting_user_id? (generated)"),
        ("UK", "(event_id, waiting_user_id)"), ("", "created_at, updated_at"),
    ]),
]

# A directed line denotes a FK relationship, not a workflow. Cardinalities are
# explicit: the parent has zero or more children; each child references one parent.
RELATIONSHIPS = [
    dict(label="owns", points=[(432,408),(432,464),(480,464),(480,520)],
         label_xy=(440,448), one=(412,428), many=(492,508)),
    dict(label="classifies", points=[(888,324),(888,456),(720,456),(720,520)],
         label_xy=(776,440), one=(900,344), many=(732,508)),
    dict(label="books", points=[(80,284),(48,284),(48,1188),(80,1188)],
         label_xy=(60,748), one=(56,268), many=(52,1172)),
    dict(label="queues", points=[(312,164),(312,124),(1152,124),(1152,1216),(1120,1216)],
         label_xy=(964,112), one=(324,152), many=(1120,1200)),
    dict(label="books", points=[(480,960),(480,1000),(312,1000),(312,1052)],
         label_xy=(376,988), one=(492,980), many=(324,1040)),
    dict(label="queues", points=[(720,960),(720,984),(888,984),(888,1052)],
         label_xy=(776,972), one=(732,980), many=(900,1040)),
]

TITLE = "PROPOSED MVP SCHEMA"
ALT = (
    "Proposed LeggTix MVP database: users own events, event types classify events, "
    "and users book reservations or queue on waitlist entries for events. "
    "Each foreign key references one parent; each parent can have zero or more child rows."
)


def text(value: str, x: int, y: int, color: str = INK, bold: bool = False) -> str:
    return (f'<text x="{x}" y="{y}" fill="{color}" font-family="{FONT}" '
            f'font-size="16" font-weight="{700 if bold else 400}">{escape(value)}</text>')


def relationship(relation: dict) -> list[str]:
    points = relation["points"]
    px, py = points[-2]
    ex, ey = points[-1]
    dx, dy = ex - px, ey - py
    # Explicit 12 x 10 arrowhead; end the line one unit within its base.
    if dx:
        step = 1 if dx > 0 else -1
        base = ex - 12 * step
        line_end = (base + step, ey)
        head = f"M {ex} {ey} L {base} {ey - 5} L {base} {ey + 5} Z"
    else:
        step = 1 if dy > 0 else -1
        base = ey - 12 * step
        line_end = (ex, base + step)
        head = f"M {ex} {ey} L {ex - 5} {base} L {ex + 5} {base} Z"
    path_points = points[:-1] + [line_end]
    path = "M " + " L ".join(f"{x} {y}" for x, y in path_points)
    lx, ly = relation["label_xy"]
    label_w = math.ceil(fit(relation["label"])) + 8
    return [
        f'<path d="{path}" fill="none" stroke="{MUTED}" stroke-width="2" stroke-linecap="round"/>',
        f'<path d="{head}" fill="{MUTED}" stroke="{MUTED}" stroke-width="2" stroke-linecap="round"/>',
        f'<rect x="{lx - 4}" y="{ly - 20}" width="{label_w}" height="28" rx="4" fill="{GROUND}"/>',
        text(relation["label"], lx, ly, MUTED),
        text("1", *relation["one"], MUTED),
        text("0..*", *relation["many"], MUTED),
    ]


def build() -> None:
    assert FONT_SIZE >= WIDTH / 75
    for entity in ENTITIES:
        entity["h"] = 48 + 28 * len(entity["rows"])
        assert entity["x"] >= 48 and entity["x"] + entity["w"] + 2 <= WIDTH - 48
        assert entity["y"] >= 48 and entity["y"] + entity["h"] + 2 <= HEIGHT - 48
        assert fit(entity["name"], bold=True) + 28 <= entity["w"]
        for key, field in entity["rows"]:
            assert fit(key, bold=True) <= 32
            assert fit(field) + 28 <= entity["w"] - 52, (entity["name"], field)
    for color in (INK, PRIMARY, MUTED, ACCENT):
        assert contrast(color, GROUND) >= 4.5
    assert contrast(GROUND, PRIMARY) >= 4.5

    parts = [
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{HEIGHT}" '
        f'viewBox="0 0 {WIDTH} {HEIGHT}" role="img" aria-label="{escape(ALT)}">',
        f'<title>{TITLE}</title>', f'<desc>{escape(ALT)}</desc>',
        f'<rect x="0" y="0" width="{WIDTH}" height="{HEIGHT}" fill="{GROUND}"/>',
        text(TITLE, 48, 64, PRIMARY, True),
        text("LeggTix | One event owner; all user roles may reserve or join a waitlist.", 48, 92, MUTED),
    ]
    for relation in RELATIONSHIPS:
        parts.extend(relationship(relation))
    for entity in ENTITIES:
        x, y, width, height = (entity[key] for key in ("x", "y", "w", "h"))
        parts.extend([
            f'<rect x="{x}" y="{y}" width="{width}" height="{height}" rx="8" '
            f'fill="{GROUND}" stroke="{PRIMARY}" stroke-width="2"/>',
            f'<path d="M {x} {y + 48} L {x + width} {y + 48}" fill="none" '
            f'stroke="{PRIMARY}" stroke-width="2" stroke-linecap="round"/>',
            text(entity["name"], x + 16, y + 32, PRIMARY, True),
        ])
        for index, (key, field) in enumerate(entity["rows"]):
            baseline = y + 68 + index * 28
            if key:
                parts.append(text(key, x + 16, baseline, ACCENT, True))
            parts.append(text(field, x + 52, baseline))
    parts.extend([
        text("PK primary key  |  FK foreign key  |  UK unique key  |  ? nullable", 48, 1432, MUTED),
        text("Every FK row references one parent; each parent has zero or more child rows.", 48, 1460, MUTED),
        "</svg>",
    ])
    SVG_PATH.write_text("\n".join(parts) + "\n", encoding="utf-8")
    dom = xml.dom.minidom.parse(str(SVG_PATH))
    allowed = {"svg", "title", "desc", "rect", "path", "text"}
    assert all(node.tagName in allowed for node in dom.getElementsByTagName("*"))
    assert SVG_PATH.stat().st_size < 100_000
    texts = dom.getElementsByTagName("text")
    assert all(float(node.getAttribute("font-size")) >= 16 for node in texts)
    assert all(node.getAttribute("font-family") == FONT for node in texts)
    for node in texts:
        value = "".join(child.data for child in node.childNodes if child.nodeType == child.TEXT_NODE)
        x, y = int(node.getAttribute("x")), int(node.getAttribute("y"))
        assert x >= 48 and x + fit(value, node.getAttribute("font-weight") == "700") <= WIDTH - 48
        assert y - FONT_SIZE >= 48 and y + 4 <= HEIGHT - 48
    for relation in RELATIONSHIPS:
        points = relation["points"]
        assert all(x1 == x2 or y1 == y2 for (x1, y1), (x2, y2) in zip(points, points[1:]))
        assert all(48 <= x <= WIDTH - 48 and 48 <= y <= HEIGHT - 48 for x, y in points)
    print(f"Validated {len(ENTITIES)} entities, {len(RELATIONSHIPS)} labelled FK relationships, "
          f"{len(texts)} text elements; {WIDTH}x{HEIGHT}; {SVG_PATH.stat().st_size} bytes.")
    print("Text fit model passed for every field/header (Helvetica/Arial approximation).")
    print("Contrast against white: " + ", ".join(f"{c} {contrast(c, GROUND):.2f}:1" for c in (INK, PRIMARY, MUTED, ACCENT)))


def run_attempt(label: str, command: list[str]) -> bool:
    try:
        PNG_PATH.unlink(missing_ok=True)
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45)
        success = result.returncode == 0 and PNG_PATH.exists() and PNG_PATH.stat().st_size > 0
        print(f"Preview {label}: {'created' if success else 'unavailable/failed'}.")
        if not success:
            diagnostic = next((line for line in result.stderr.decode(errors="replace").splitlines() if line.strip()), "")
            if diagnostic:
                print(f"Preview diagnostic: {diagnostic[:200]}")
            PNG_PATH.unlink(missing_ok=True)
        return success
    except (OSError, subprocess.TimeoutExpired):
        print(f"Preview {label}: unavailable/failed.")
        return False


def preview() -> None:
    rsvg = shutil.which("rsvg-convert")
    if rsvg and run_attempt("rsvg-convert", [rsvg, "-o", str(PNG_PATH), str(SVG_PATH)]):
        return
    if not rsvg:
        print("Preview rsvg-convert: unavailable.")
    if importlib.util.find_spec("cairosvg"):
        try:
            PNG_PATH.unlink(missing_ok=True)
            import cairosvg
            cairosvg.svg2png(url=str(SVG_PATH), write_to=str(PNG_PATH))
            print("Preview cairosvg: created.")
            return
        except (OSError, ImportError, ValueError):
            print("Preview cairosvg: unavailable/failed.")
            PNG_PATH.unlink(missing_ok=True)
    else:
        print("Preview cairosvg: unavailable.")
    inkscape = shutil.which("inkscape")
    if inkscape and run_attempt("inkscape", [inkscape, str(SVG_PATH), "--export-type=png", f"--export-filename={PNG_PATH}"]):
        return
    if not inkscape:
        print("Preview inkscape: unavailable.")
    chrome_candidates = [
        shutil.which("chrome"), shutil.which("chromium"),
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    ]
    chrome = next((candidate for candidate in chrome_candidates if candidate and Path(candidate).is_file()), None)
    if chrome and run_attempt("headless Chrome", [chrome, "--headless", "--disable-gpu", "--hide-scrollbars",
                                                f"--screenshot={PNG_PATH}", f"--window-size={WIDTH},{HEIGHT}",
                                                SVG_PATH.as_uri()]):
        return
    if not chrome:
        print("Preview headless Chrome: unavailable.")
    # A file-only fallback for bundled/installed sharp. This does not launch a UI,
    # install anything, or require the development application to use Node.
    runtime_root = Path(sys.executable).resolve().parents[1]
    node_candidates = [shutil.which("node"), runtime_root / "node" / "bin" / "node.exe"]
    node = next((str(candidate) for candidate in node_candidates if candidate and Path(candidate).is_file()), None)
    sharp_module = runtime_root / "node" / "node_modules" / "sharp"
    if node:
        sharp_js = ('const sharp = require(process.argv[1]); '
                    'sharp(process.argv[2]).png().toFile(process.argv[3])'
                    '.catch(error => { process.stderr.write(error.message); process.exitCode = 1; });')
        if run_attempt("Node sharp", [node, "-e", sharp_js,
                                      str(sharp_module) if sharp_module.exists() else "sharp",
                                      str(SVG_PATH), str(PNG_PATH)]):
            return
    else:
        print("Preview Node sharp: unavailable.")
    print("No PNG preview rendered. Validated SVG remains available.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--preview", action="store_true", help="Attempt a PNG preview using an existing rasteriser.")
    args = parser.parse_args()
    build()
    if args.preview:
        preview()
