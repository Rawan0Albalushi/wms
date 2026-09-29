"""Normalize the store workbook into the WMS catalog shape.

Current on-hand quantity comes from Total QTY. Received rows supply bin
locations and are scaled when their sum does not match that quantity.
Issue, shipment, and RFQ sheets are history and do not change on-hand.
"""

import hashlib
import json
import re
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path

from pyxlsb import open_workbook

ROOT = Path(__file__).resolve().parents[3]
SOURCE = ROOT / "public" / "storage" / "doc" / "STORE SPARE PARTS 16.9.26.xlsb"
OUTPUT = Path(__file__).with_name("spare-parts.json")
EPOCH = datetime(1899, 12, 30)
FALLBACK = "2026-09-16"

UNITS = {
    "ea": ("EA", "حبة", "Each"),
    "each": ("EA", "حبة", "Each"),
    "m": ("M", "متر", "Meter"),
    "mt": ("MT", "طن متري", "Metric ton"),
    "ft": ("FT", "قدم", "Foot"),
    "kg": ("KG", "كيلوغرام", "Kilogram"),
    "g": ("G", "غرام", "Gram"),
    "set": ("SET", "طقم", "Set"),
    "gl": ("GL", "جالون", "Gallon"),
    "roll": ("ROLL", "رول", "Roll"),
    "in": ("IN", "بوصة", "Inch"),
    "lb": ("LB", "رطل", "Pound"),
    "sq_m": ("SQM", "متر مربع", "Square meter"),
    "pair": ("PAIR", "زوج", "Pair"),
    "box": ("BOX", "صندوق", "Box"),
}


def cell(value):
    if value is None:
        return None
    if isinstance(value, str):
        text = value.strip()
        return text or None
    return value


def text(value):
    if value is None:
        return None
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    if isinstance(value, int):
        return str(value)
    cleaned = str(value).strip()
    return cleaned or None


def code(value):
    cleaned = text(value)
    if cleaned is None:
        return None
    return cleaned.upper()


def part_number(value):
    cleaned = text(value)
    if cleaned is None:
        return None
    if cleaned.lower() in {"n/a", "na", "none", "-", "--"}:
        return None
    if len(cleaned) > 40 or " " in cleaned:
        return None
    return cleaned


def unit_code(value):
    cleaned = text(value)
    if cleaned is None:
        return "EA"
    mapped = UNITS.get(cleaned.lower())
    return mapped[0] if mapped else "EA"


def excel_date(value):
    if isinstance(value, bool) or value is None:
        return None
    number = None
    if isinstance(value, int):
        number = value
    elif isinstance(value, float) and value.is_integer():
        number = int(value)
    elif isinstance(value, str) and value.strip().isdigit():
        number = int(value.strip())
    if number is None or number < 20000 or number > 80000:
        return None
    return (EPOCH + timedelta(days=number)).date().isoformat()


def quantity(value):
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, float):
        return int(round(value))
    raw = str(value).strip()
    if not raw:
        return None
    if re.fullmatch(r"\d+(?:\s*\+\s*\d+)+", raw):
        return sum(int(piece) for piece in re.split(r"\s*\+\s*", raw))
    match = re.match(r"(\d+)", raw)
    if match:
        return int(match.group(1))
    return None


def clip(value, limit):
    if value is None:
        return None
    value = re.sub(r"\s+", " ", value).strip()
    if not value:
        return None
    return value[:limit]


def load_sheet(book, name):
    rows = []
    header = True
    with book.get_sheet(name) as sheet:
        for row in sheet.rows():
            values = {cell_item.c: cell(cell_item.v) for cell_item in row if cell(cell_item.v) is not None}
            if not values:
                continue
            if header:
                header = False
                continue
            rows.append(values)
    return rows


def allocate(total, weights):
    if total <= 0 or not weights:
        return [0] * len(weights)
    whole = sum(weights)
    if whole <= 0:
        return [0] * len(weights)
    exact = [total * weight / whole for weight in weights]
    shares = [int(value) for value in exact]
    remainder = total - sum(shares)
    order = sorted(range(len(exact)), key=lambda index: exact[index] - shares[index], reverse=True)
    for index in order:
        if remainder <= 0:
            break
        shares[index] += 1
        remainder -= 1
    return shares


def main():
    with open_workbook(SOURCE) as book:
        sheets = {name: load_sheet(book, name) for name in book.sheets}

    items = {}
    unit_votes = defaultdict(Counter)
    min_levels = {}
    control = {}
    received = defaultdict(list)
    used = {}
    stats = Counter()

    def ensure(item_code, name, unit, number):
        if item_code is None or len(item_code) > 40:
            stats["item_code_rejected"] += 1
            return None
        row = items.get(item_code)
        if row is None:
            items[item_code] = {
                "code": item_code,
                "part_number": part_number(number),
                "name": clip(name, 120) or item_code,
                "unit": unit_code(unit),
            }
        else:
            if row["name"] == row["code"] and name:
                row["name"] = clip(name, 120) or row["name"]
            if row["part_number"] is None:
                row["part_number"] = part_number(number)
        if unit:
            unit_votes[item_code][unit_code(unit)] += 1
        return item_code

    for row in sheets["DB"]:
        item_code = ensure(code(row.get(0)), text(row.get(2)), row.get(3), row.get(1))
        if item_code and text(row.get(2)):
            items[item_code]["name"] = clip(text(row.get(2)), 120)

    for row in sheets["Total QTY"]:
        item_code = ensure(code(row.get(0)), text(row.get(2)), None, row.get(1))
        amount = quantity(row.get(3))
        minimum = quantity(row.get(5))
        if item_code is None or amount is None:
            continue
        if item_code in control:
            stats["total_duplicate_rows"] += 1
            control[item_code] = max(control[item_code], max(amount, 0))
        else:
            control[item_code] = max(amount, 0)
        if minimum is not None and minimum > 0:
            min_levels[item_code] = max(min_levels.get(item_code, 0), minimum)

    for row in sheets["Received"]:
        item_code = ensure(code(row.get(0)), text(row.get(2)), row.get(3), row.get(1))
        amount = quantity(row.get(4))
        if item_code is None or amount is None or amount <= 0:
            stats["received_skipped"] += 1
            continue
        reference = clip(text(row.get(7)), 80)
        if reference is None:
            kind = text(row.get(10))
            if kind and excel_date(kind) is None and not kind.isdigit():
                reference = clip(kind, 80)
        received[item_code].append({
            "location": text(row.get(8)),
            "qty": amount,
            "received_on": excel_date(row.get(9)) or FALLBACK,
            "reference": reference,
        })

    for row in sheets["used"]:
        item_code = ensure(code(row.get(0)), text(row.get(2)), None, row.get(1))
        amount = quantity(row.get(3))
        if item_code is None or amount is None or amount <= 0:
            stats["used_skipped"] += 1
            continue
        note = " ".join(piece for piece in (text(row.get(6)), text(row.get(4))) if piece)
        used[item_code] = {
            "qty": used.get(item_code, {}).get("qty", 0) + amount,
            "received_on": excel_date(row.get(5)) or FALLBACK,
            "reference": clip(note or "Used", 80),
        }

    for row in sheets["Shipments"]:
        ensure(code(row.get(0)), text(row.get(2)), row.get(3), row.get(1))
    for row in sheets["Future Arrival"]:
        ensure(code(row.get(5)), text(row.get(7)), row.get(8), row.get(6))
    for row in sheets["Issued"]:
        ensure(code(row.get(1)), text(row.get(4)), row.get(5), row.get(2))
    for row in sheets["Pending items"]:
        number = part_number(row.get(1)) or text(row.get(1))
        if number is None:
            continue
        pending_code = number.upper() if number.upper() not in items else f"PEND-{number.upper()}"[:40]
        ensure(pending_code, text(row.get(2)), row.get(3), number)
        row["_code"] = pending_code

    for item_code, votes in unit_votes.items():
        if votes:
            items[item_code]["unit"] = votes.most_common(1)[0][0]

    location_index = {}
    location_codes = {}

    def location_code(raw):
        if raw is None:
            return None
        original = re.sub(r"\s+", " ", raw).strip()
        if not original:
            return None
        if original in location_index:
            return location_index[original]
        base = original.upper()
        if len(base) <= 20 and base not in location_codes:
            chosen = base
        else:
            digest = hashlib.sha1(original.encode()).hexdigest()[:4].upper()
            prefix = re.sub(r"[^A-Z0-9]+", "-", base).strip("-")[:14].strip("-") or "LOC"
            chosen = f"{prefix}-{digest}"[:20]
            serial = 2
            while chosen in location_codes and location_codes[chosen] != original:
                chosen = f"{prefix[:12]}-{digest}{serial}"[:20]
                serial += 1
        location_index[original] = chosen
        location_codes[chosen] = original
        return chosen

    def place(original, chosen):
        compact = re.sub(r"\s+", "", original.upper())
        matched = re.fullmatch(r"([A-Z]{1,4}\d{0,3})([A-Z])(\d{1,2})", compact)
        if matched and len(matched.group(1)) <= 20:
            return {
                "code": chosen,
                "zone": matched.group(1)[:20],
                "rack": matched.group(2),
                "shelf": matched.group(3),
                "kind": "storage",
            }
        zone = re.split(r"[\s,/;]+", original.upper())[0][:20] or chosen[:20]
        folded = original.upper()
        kind = "storage"
        if "QUAR" in folded:
            kind = "quarantine"
        elif "DOCK" in folded:
            kind = "dock"
        return {"code": chosen, "zone": zone, "rack": None, "shelf": None, "kind": kind}

    stock = []
    for item_code, rows in received.items():
        target = control.get(item_code)
        used_row = used.get(item_code)
        used_qty = used_row["qty"] if used_row else 0
        if target is None:
            new_rows = rows
            taken_used = used_qty
            stats["stock_from_received_only"] += 1
        else:
            taken_used = min(used_qty, target)
            shares = allocate(target - taken_used, [row["qty"] for row in rows])
            new_rows = []
            if sum(row["qty"] for row in rows) != target - taken_used:
                stats["stock_scaled_to_total"] += 1
            for row, share in zip(rows, shares):
                if share > 0:
                    new_rows.append({**row, "qty": share})
        for row in new_rows:
            chosen = location_code(row["location"])
            stock.append({
                "item": item_code,
                "location": chosen,
                "qty": row["qty"],
                "condition": "new",
                "received_on": row["received_on"],
                "reference": row["reference"],
            })
        if taken_used > 0:
            stock.append({
                "item": item_code,
                "location": None,
                "qty": taken_used,
                "condition": "used",
                "received_on": used_row["received_on"],
                "reference": used_row["reference"],
            })
            stats["used_positions"] += 1

    for item_code, target in control.items():
        if target <= 0 or item_code in received:
            continue
        used_row = used.get(item_code)
        used_qty = min(used_row["qty"], target) if used_row else 0
        if used_qty > 0:
            stock.append({
                "item": item_code,
                "location": None,
                "qty": used_qty,
                "condition": "used",
                "received_on": used_row["received_on"],
                "reference": used_row["reference"],
            })
        if target - used_qty > 0:
            stock.append({
                "item": item_code,
                "location": None,
                "qty": target - used_qty,
                "condition": "new",
                "received_on": FALLBACK,
                "reference": None,
            })
            stats["stock_without_location"] += 1

    for item_code, used_row in used.items():
        if item_code in received or item_code in control:
            continue
        stock.append({
            "item": item_code,
            "location": None,
            "qty": used_row["qty"],
            "condition": "used",
            "received_on": used_row["received_on"],
            "reference": used_row["reference"],
        })
        stats["used_positions"] += 1

    for row in sheets["Pending items"]:
        item_code = row.get("_code")
        amount = quantity(row.get(4))
        if item_code is None or amount is None or amount <= 0:
            continue
        location_code("Sohar")
        note = " | ".join(piece for piece in (text(row.get(10)), text(row.get(11))) if piece)
        stock.append({
            "item": item_code,
            "location": location_index.get("Sohar"),
            "qty": amount,
            "condition": "new",
            "received_on": FALLBACK,
            "reference": clip(note, 80),
        })

    locations = [place(original, chosen) for original, chosen in location_index.items()]

    issues = []
    months = {"JANUARY JO": "2026-01-31", "FEB": "2026-02-28", "MARCH": "2026-03-31"}
    for row in sheets["Issued"]:
        item_code = code(row.get(1))
        if item_code not in items:
            stats["issues_without_item"] += 1
            continue
        amount = quantity(row.get(6))
        if amount is None or amount == 0:
            stats["issues_without_qty"] += 1
            continue
        comment = text(row.get(11)) or ""
        issues.append({
            "item": item_code,
            "qty": abs(amount),
            "direction": "out" if amount > 0 else "in",
            "reason": "job_order" if "JO" in comment.upper() else ("other" if amount > 0 else "return"),
            "reference": clip(comment or "Issued", 80),
            "happened_at": months.get(comment, "2026-03-31") + " 08:00:00",
        })

    orders = []
    grouped = defaultdict(list)
    for row in sheets["Shipments"]:
        item_code = code(row.get(0))
        amount = quantity(row.get(4))
        if item_code not in items or amount is None or amount <= 0:
            stats["shipment_lines_skipped"] += 1
            continue
        ci = text(row.get(7))
        packing = text(row.get(8))
        key = ci or (f"PL-{packing}" if packing else "PL-NA")
        grouped[key].append(row)

    for key, rows in grouped.items():
        lines = Counter()
        dates = []
        packs = []
        places = []
        for row in rows:
            lines[code(row.get(0))] += quantity(row.get(4))
            dated = excel_date(row.get(9))
            if dated:
                dates.append(dated)
            packing = text(row.get(8))
            if packing and packing not in packs:
                packs.append(packing)
            place_name = text(row.get(6))
            if place_name and place_name not in places and len(places) < 8:
                places.append(place_name)
        ordered_on = min(dates) if dates else FALLBACK
        note = "PL " + ", ".join(packs[:12])
        if places:
            note += " @ " + ", ".join(places)
        orders.append({
            "number": f"CI-{key}"[:30] if key.isdigit() else key[:30],
            "ordered_on": ordered_on,
            "status": "received",
            "notes": clip(f"Store receipt. {note}", 255),
            "lines": [{"item": item, "qty": qty} for item, qty in lines.items() if qty > 0],
            "shipment": {
                "ci": key[:40],
                "expected_on": max(dates) if dates else ordered_on,
                "status": "received",
                "packing_note": clip(note, 255),
            },
        })

    future_groups = defaultdict(list)
    for row in sheets["Future Arrival"]:
        future_groups[text(row.get(2)) or "NA"].append(row)

    existing_ci = {order["shipment"]["ci"] for order in orders}
    for rfq, rows in future_groups.items():
        lines = Counter()
        dates = []
        exw = []
        statuses = Counter()
        stages = Counter()
        ci_ref = None
        for row in rows:
            item_code = code(row.get(5))
            amount = quantity(row.get(9))
            if item_code in items and amount is not None and amount > 0:
                lines[item_code] += amount
            dated = excel_date(row.get(1))
            if dated:
                dates.append(dated)
            exw_date = excel_date(row.get(4))
            if exw_date:
                exw.append(exw_date)
            if text(row.get(3)):
                statuses[text(row.get(3))] += 1
            if text(row.get(0)):
                stages[text(row.get(0))] += 1
            reference = text(row.get(10))
            if reference and reference.upper() != "TBA":
                ci_ref = reference
        if not lines:
            continue
        status_note = ", ".join(f"{name} {count}" for name, count in statuses.most_common())
        stage_note = ", ".join(f"{name} {count}" for name, count in stages.most_common())
        shipment = None
        if ci_ref:
            ci_number = ci_ref[:40]
            if ci_number in existing_ci:
                ci_number = f"F-{rfq}-{ci_ref}"[:40]
            serial = 2
            while ci_number in existing_ci:
                ci_number = f"F-{rfq}-{serial}-{ci_ref}"[:40]
                serial += 1
            existing_ci.add(ci_number)
            shipment = {
                "ci": ci_number,
                "expected_on": min(exw) if exw else (min(dates) if dates else FALLBACK),
                "status": "received",
                "packing_note": clip(f"EXW {min(exw) if exw else ''} {stage_note}", 255),
            }
        orders.append({
            "number": f"RFQ-{rfq}"[:30],
            "ordered_on": min(dates) if dates else FALLBACK,
            "status": "received",
            "notes": clip(f"Future arrival archive. {status_note}. {stage_note}", 255),
            "lines": [{"item": item, "qty": qty} for item, qty in lines.items()],
            "shipment": shipment,
        })

    payload = {
        "units": [{"code": value[0], "name_ar": value[1], "name_en": value[2]} for value in UNITS.values()],
        "items": [
            {**row, "min_level": min_levels.get(row["code"], 0)}
            for row in items.values()
        ],
        "locations": locations,
        "stock": [row for row in stock if row["qty"] > 0],
        "issues": issues,
        "orders": [order for order in orders if order["lines"]],
        "stats": dict(stats),
    }
    seen_units = {row["code"] for row in payload["units"]}
    payload["units"] = [row for row in payload["units"] if row["code"] in seen_units]
    # UNITS values are not unique after aliasing EA.
    unique_units = {}
    for row in payload["units"]:
        unique_units[row["code"]] = row
    payload["units"] = list(unique_units.values())

    OUTPUT.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(json.dumps({
        "items": len(payload["items"]),
        "locations": len(payload["locations"]),
        "stock": len(payload["stock"]),
        "stock_qty": sum(row["qty"] for row in payload["stock"]),
        "issues": len(payload["issues"]),
        "orders": len(payload["orders"]),
        "shipments": sum(1 for order in payload["orders"] if order["shipment"]),
        "stats": payload["stats"],
        "output": str(OUTPUT),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
