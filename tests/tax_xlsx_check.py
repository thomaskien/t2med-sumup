"""Prüft die Excel-Ausgabe von tests/tax.php mit openpyxl (Werte, Formate, Summen)."""
import sys
from datetime import datetime

import openpyxl

year, named = sys.argv[1], sys.argv[2]
wb = openpyxl.load_workbook(year)
assert wb.sheetnames == ["Rechnungen", "Positionen", "Monatssummen"], wb.sheetnames
ws = wb["Rechnungen"]
head = [c.value for c in ws[1]]
assert head[:5] == ["Rechnungsdatum", "Rechnungsnummer", "Leistungsdatum", "Art", "Brutto"], head
assert "Patient" not in head, "Namen ohne Wunsch exportiert"
assert "Netto 19 %" in head and "MwSt. 7 %" in head and "Ohne MwSt." in head, head
rows = list(ws.iter_rows(min_row=2, values_only=True))
data, total = rows[:-1], rows[-1]
assert len(data) == 3 and total[0] == "Summe", rows
first = dict(zip(head, data[0]))
assert first["Rechnungsdatum"] == datetime(2026, 1, 15), first
assert abs(first["Brutto"] - 84.5) < 1e-9 and abs(first["Netto 19 %"] - 50) < 1e-9 and abs(first["MwSt. 19 %"] - 9.5) < 1e-9 and abs(first["Ohne MwSt."] - 25) < 1e-9, first
assert ws.cell(row=2, column=5).number_format.startswith("#,##0.00"), ws.cell(row=2, column=5).number_format
assert ws.cell(row=2, column=1).number_format == "dd\\.mm\\.yyyy", ws.cell(row=2, column=1).number_format
brutto = head.index("Brutto") + 1
assert ws.cell(row=5, column=brutto).value.startswith("=SUM("), ws.cell(row=5, column=brutto).value
third = dict(zip(head, data[2]))
assert third["Umsatzsteuer-Hinweis"] == "Kleinunternehmer § 19 UStG" and third["Ohne MwSt."] == 59.5, third
pos = list(wb["Positionen"].iter_rows(min_row=2, values_only=True))
assert len(pos) == 1 + 5, pos  # 5 Positionen + Summe
assert any(r[3] == "Untersuchung" and r[2] == "5" and r[4] == "2,3" for r in pos[:-1]), pos
months = list(wb["Monatssummen"].iter_rows(min_row=2, values_only=True))
assert len(months) == 13 and months[0][0] == "Januar 2026" and months[2][1] == 2 and months[-1][0] == "Summe", months
m = openpyxl.load_workbook(named)
assert m.sheetnames == ["Rechnungen", "Positionen", "Summe"], m.sheetnames
h = [c.value for c in m["Rechnungen"][1]]
assert h[3] == "Patient" and m["Rechnungen"].cell(row=2, column=4).value == "Erika Musterfrau", h
assert m["Rechnungen"].freeze_panes == "A2" and m["Rechnungen"].auto_filter.ref.startswith("A1:"), "Kopfzeile nicht fixiert/gefiltert"
print("ok")
