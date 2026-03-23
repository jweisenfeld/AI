#!/usr/bin/env python3
"""
Fetch Al-Baqarah (Surah 2) from alquran.cloud API,
divide into 16 thematic sections, output data.json.
"""
import json, urllib.request, sys

# Section boundaries [start_verse, end_verse] (1-indexed, inclusive)
SECTIONS = [
    (1,   7),   # 1 - The Quran and types of humanity
    (8,   20),  # 2 - The hypocrites described
    (21,  39),  # 3 - Call to worship; Adam's story in the Garden
    (40,  52),  # 4 - Covenant with Israel; Exodus miracles
    (53,  74),  # 5 - The golden calf; the slaughtered cow (surah's name)
    (75,  101), # 6 - Hearts hardened; scripture sold; Harut & Marut
    (102, 121), # 7 - Muhammad's prophethood; Jews and Christians
    (122, 135), # 8 - Abraham's legacy; Ka'bah built; Muslim identity
    (136, 157), # 9 - One faith of all prophets; change of Qibla; trials
    (158, 177), # 10 - Safa & Marwa; Ramadan; the meaning of true piety
    (178, 203), # 11 - Retaliation; fasting rules; pilgrimage rituals
    (204, 232), # 12 - Hypocrites vs. believers; wine & gambling; marriage/divorce
    (233, 252), # 13 - Divorce and waiting periods; Saul and Goliath
    (253, 260), # 14 - Prophets ranked; Throne Verse; Ibrahim vs. Nimrod
    (261, 275), # 15 - Parables of charity; prohibition of usury
    (276, 286), # 16 - Usury forbidden; debt contract; final supplication
]

def verse_to_section(verse_num):
    for i, (start, end) in enumerate(SECTIONS, 1):
        if start <= verse_num <= end:
            return i
    return None

print("Fetching Al-Baqarah from alquran.cloud...", file=sys.stderr)
url = "https://api.alquran.cloud/v1/surah/2/en.sahih"
try:
    with urllib.request.urlopen(url, timeout=30) as resp:
        data = json.load(resp)
except Exception as e:
    print(f"Error: {e}", file=sys.stderr)
    sys.exit(1)

ayahs = data["data"]["ayahs"]
print(f"Got {len(ayahs)} verses", file=sys.stderr)

out = []
for ayah in ayahs:
    vnum = ayah["numberInSurah"]
    text = ayah["text"]
    sec  = verse_to_section(vnum)
    if sec is None:
        print(f"Warning: verse {vnum} has no section", file=sys.stderr)
        continue
    out.append({"s": sec, "v": vnum, "t": text})

with open("data.json", "w", encoding="utf-8") as f:
    json.dump(out, f, ensure_ascii=False, separators=(',', ':'))

print(f"Written {len(out)} verses to data.json", file=sys.stderr)
