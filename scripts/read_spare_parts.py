# -*- coding: utf-8 -*-
"""Read the spare-parts workbook into the JSON shape the catalog command expects."""
import json
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta

from pyxlsb import open_workbook

UNITS = {
    "EA": "EA",
    "M": "M",
    "MT": "MT",
    "FT": "FT",
    "KG": "KG",
    "G": "G",
    "SET": "SET",
    "GL": "GL",
    "ROLL": "ROLL",
    "IN": "IN",
    "LB": "LB",
    "SQM": "SQM",
    "SQ_M": "SQM",
    "PAIR": "PAIR",
    "BOX": "BOX",
}


def norm(value):
    if value is None:
        return None
    if isinstance(value, str):
        text = " ".join(value.replace("\xa0", " ").split())
        return text or None
    if isinstance(value, float) and value.is_integer():
        return int(value)
    if isinstance(value, float):
        return round(value, 4)
    return value


def text(value):
    value = norm(value)
    if value is None:
        return None
    if isinstance(value, float):
        return str(int(value)) if value.is_integer() else str(value)
    return str(value).strip() or None


def unit_code(value):
    raw = (text(value) or "").upper().replace(" ", "")
    if not raw or raw in {"N/A", "NA"}:
        return None
    return UNITS.get(raw, raw[:10])


def quantity(value):
    value = norm(value)
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return None
    return int(value)


def excel_date(value):
    value = norm(value)
    if isinstance(value, (int, float)) and 30000 < float(value) < 80000:
        return (datetime(1899, 12, 30) + timedelta(days=float(value))).date().isoformat()
    if isinstance(value, str):
        cleaned = value.replace(".", "/").replace("-", "/")
        parts = cleaned.split("/")
        if len(parts) == 3 and all(part.isdigit() for part in parts):
            day, month, year = parts
            if len(year) == 2:
                year = "20" + year
            if 1 <= int(month) <= 12 and 1 <= int(day) <= 31:
                return f"{year}-{month.zfill(2)}-{day.zfill(2)}"
    return None


def load(path, name):
    rows = []
    width = 0
    with open_workbook(path) as workbook:
        with workbook.get_sheet(name) as sheet:
            for row in sheet.rows():
                values = {}
                for cell in row:
                    if cell.v is not None:
                        values[cell.c] = norm(cell.v)
                        width = max(width, cell.c + 1)
                rows.append(values)
    header = next((index for index, row in enumerate(rows) if row), None)
    if header is None:
        return []
    headers = [str(rows[header].get(col) or f"col_{col}") for col in range(width)]
    return [{headers[col]: row.get(col) for col in range(width)} for row in rows[header + 1 :] if row]


def blank_item():
    return {
        "name": None,
        "part_number": None,
        "units": [],
        "stock_status": None,
        "min_level": 0,
        "family": None,
        "mins": [],
        "statuses": [],
        "types": [],
    }


def remember(items, code, name=None, part=None, unit=None):
    if not code or code in {"`", "N/A"}:
        return None
    row = items[code]
    if name and not row["name"]:
        row["name"] = name
    if part and part.upper() not in {"N/A", "NA"} and not row["part_number"]:
        row["part_number"] = part
    if unit and unit not in row["units"]:
        row["units"].append(unit)
    return row


def main():
    path = sys.argv[1]
    items = defaultdict(blank_item)
    catalog = load(path, "DB")
    totals = load(path, "Total QTY")
    received = load(path, "Received")
    used = load(path, "used")
    future = load(path, "Future Arrival")
    shipments = load(path, "Shipments")

    for row in catalog:
        remember(items, text(row.get("1CCode")), text(row.get("Description")), text(row.get("PartNumber")), unit_code(row.get("UOM")))

    for row in totals:
        item = remember(items, text(row.get("item Code")), text(row.get("Description")), text(row.get("PN")))
        if not item:
            continue
        status = (text(row.get("status")) or "").lower()
        if status in {"rop", "sufficient"}:
            item["statuses"].append(status)
        minimum = quantity(row.get("Min"))
        if minimum is not None and minimum > 0:
            item["mins"].append(minimum)

    positions = []
    for row in received:
        code = text(row.get("item Code"))
        if not code or code in {"`", "N/A"}:
            continue
        unit = unit_code(row.get("UOM"))
        remember(items, code, text(row.get("Description")), text(row.get("Part Num")), unit)
        qty = quantity(row.get("Quantity"))
        line_type = text(row.get("type"))
        if code and line_type:
            items[code]["types"].append(line_type[:40])
        if not code or qty is None or qty <= 0:
            continue
        positions.append({
            "item": code,
            "location": (text(row.get("location")) or "")[:100].upper() or None,
            "qty": qty,
            "condition": (text(row.get("Condition")) or "").lower() or None,
            "reference": (text(row.get("Reference")) or "")[:80] or None,
            "type": line_type[:40] if line_type else None,
            "recorded_on": excel_date(row.get("Last Update Date")),
        })

    for row in used:
        item = remember(items, text(row.get("Item Code")), text(row.get("Description")), text(row.get("P/N")))
        family = text(row.get("Type"))
        if item and family and not item["family"]:
            item["family"] = family[:80]

    future_orders = {}
    for row in future:
        code = text(row.get("1CCode"))
        rfq = text(row.get("RFQ"))
        if not rfq:
            continue
        number = f"RFQ-{rfq}"[:30]
        order = future_orders.setdefault(number, {
            "number": number,
            "ordered_on": excel_date(row.get("RFQDate")),
            "exw_on": excel_date(row.get("EXW")),
            "sheet_status": None,
            "stage": text(row.get("Status2")),
            "lines": defaultdict(int),
            "names": {},
            "units": {},
        })
        status = text(row.get("Status"))
        if status and not order["sheet_status"]:
            order["sheet_status"] = status
        if code:
            remember(items, code, text(row.get("Description")), text(row.get("PartNumber")), unit_code(row.get("UOM")))
            order["lines"][code] += quantity(row.get("Quantity")) or 0
            order["names"][code] = items[code]["name"] or text(row.get("Description")) or code
            order["units"][code] = unit_code(row.get("UOM")) or "EA"

    receipts = {}
    for row in shipments:
        code = text(row.get("Item Code"))
        ci = text(row.get("CI number"))
        if not ci or ci.upper() == "N/A":
            continue
        number = f"CI-{ci}"[:30]
        order = receipts.setdefault(number, {
            "number": number,
            "ci": ci[:40],
            "ordered_on": None,
            "places": [],
            "lists": [],
            "lines": defaultdict(int),
            "names": {},
            "units": {},
        })
        received_on = excel_date(row.get("Date of receipt"))
        if received_on and (order["ordered_on"] is None or received_on < order["ordered_on"]):
            order["ordered_on"] = received_on
        place = text(row.get("Location"))
        packing = text(row.get("P/L Number"))
        if place and place not in order["places"] and len(order["places"]) < 8:
            order["places"].append(place)
        if packing and packing not in order["lists"] and len(order["lists"]) < 4:
            order["lists"].append(packing)
        if code:
            remember(items, code, text(row.get("Description")), text(row.get("P/N")), unit_code(row.get("UOM")))
            order["lines"][code] += quantity(row.get("Quantity")) or 0
            order["names"][code] = items[code]["name"] or text(row.get("Description")) or code
            order["units"][code] = unit_code(row.get("UOM")) or "EA"

    payload_items = []
    for code, row in items.items():
        if not code:
            continue
        types = Counter(row["types"])
        family = row["family"] or (types.most_common(1)[0][0] if types else None)
        status = "rop" if "rop" in row["statuses"] else ("sufficient" if row["statuses"] else None)
        payload_items.append({
            "code": code[:40],
            "name": (row["name"] or code)[:255],
            "part_number": (row["part_number"] or "")[:80] or None,
            "units": row["units"] or ["EA"],
            "stock_status": status,
            "min_level": max(row["mins"], default=0),
            "family": family,
        })

    def lines_of(order):
        return [
            {"item": code[:40], "name": (order["names"].get(code) or code)[:255], "unit": order["units"].get(code) or "EA", "qty": qty}
            for code, qty in order["lines"].items()
            if qty > 0
        ]

    payload_future = []
    for order in future_orders.values():
        lines = lines_of(order)
        if not lines:
            continue
        payload_future.append({
            "number": order["number"],
            "ordered_on": order["ordered_on"] or "2022-01-01",
            "exw_on": order["exw_on"],
            "sheet_status": order["sheet_status"],
            "stage": order["stage"],
            "lines": lines,
        })

    payload_receipts = []
    for order in receipts.values():
        lines = lines_of(order)
        if not lines:
            continue
        note = "PL " + ", ".join(order["lists"]) if order["lists"] else "Store receipt"
        if order["places"]:
            note += " @ " + ", ".join(order["places"])
        payload_receipts.append({
            "number": order["number"],
            "ci": order["ci"],
            "ordered_on": order["ordered_on"] or "2026-01-01",
            "packing_note": note[:240],
            "lines": lines,
        })

    json.dump({
        "items": payload_items,
        "positions": positions,
        "future": payload_future,
        "receipts": payload_receipts,
    }, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
