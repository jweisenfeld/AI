#!/usr/bin/env python3
"""
coenrollment_map.py — PowerSchool "Student Schedule Matrix" HTML  ->  per-teacher Math/ELA co-enrollment pages.

Built for Orion High School (PSD1) 9th grade, Q1 2026-27; written to be re-run every quarter.

Usage
  python3 coenrollment_map.py MATRIX.html [--term Q2] [--teachers "Weisenfeld, John C" "Arambul, Jessie L" ...]
                              [--all] [--out /mnt/user-data/outputs] [--pulled "September 9"] [--tidy]

  --term      Course term to analyse (default: the most common quarter-style term in the file, e.g. Q1/Q2).
  --teachers  Focus teachers, as PowerSchool prints them ("Last, First M"). Default: every teacher who has
              sections in the term and is NOT a Math/ELA teacher (their own subject would just mirror the diagram).
  --all       Focus pages for every teacher with sections in the term, Math/ELA teachers included.
  --out       Output directory for q<term><lastname>.html pages (default /mnt/user-data/outputs).
  --pulled    Free text for the caption, e.g. "September 9" (date the report was pulled).
  --tidy      Also write enrollments.csv / students.csv (contain student names & IDs — keep these OUT of outputs).

Course classification (by PowerSchool course code prefix, printed at run time so it can be checked):
  Math core       code starts with OMT                     (Algebra 1, Geometry 1, Algebra 3 ...)
  ELA core        OEN or OSD code AND "ENGLISH" in name    (English 9, Inclusion English 9)
  ELA elective    OEN code without "ENGLISH" in name        (Podcasting) — counted for the teacher, not as an ELA class
  Math support    OSD code without "ENGLISH" in name        (Inclusion Algebra 1) — support section, not drawn separately
  Non-academic    LCH (lunch), GEN (counselor), SED (SPED case mgmt), ADVISORY — excluded from the flow

Privacy: student names/IDs are used only to count overlaps. Nothing student-identifying is written to the pages.
"""
import argparse, html as H, itertools, os, re, sys
from collections import Counter, defaultdict
import pandas as pd
from bs4 import BeautifulSoup

# --------------------------------------------------------------------------- parsing
def parse_matrix(path):
    raw = open(path, encoding='utf-8', errors='ignore').read()
    soup = BeautifulSoup(raw, 'html.parser')
    for s in soup(['style', 'script', 'link']):
        s.decompose()
    school = None
    m = re.search(r'Student Schedules[^\n]*?\n\s*([^\n]+?)\n\s*Student Name', soup.get_text('\n', strip=True))
    if m: school = m.group(1).strip()
    records, students = [], []
    for lab in soup.find_all(string=re.compile(r'Student Name:')):
        tbl = lab.find_next('table', id='schedMatrixTable') or lab.find_next('table')
        hdr, node = [], lab
        while node is not None and node != tbl:
            if isinstance(node, str) and node.strip(): hdr.append(node.strip())
            node = node.next_element
        hdr = ' | '.join(hdr)
        def grab(k):
            mm = re.search(k + r':\s*\|\s*([^|]+)', hdr); return mm.group(1).strip() if mm else None
        sid = grab('Student ID'); name = grab('Student Name')
        students.append(dict(sid=sid, name=name, homeroom=grab('Homeroom'), grade=grab('Grade'), yog=grab('Year of Graduation')))
        if tbl is None: continue
        for td in tbl.find_all('td'):
            cls = td.get('class') or []
            if not (any(c.startswith('matrix_') for c in cls) or 'section-conflict' in cls): continue
            cur = None
            for p in td.find_all('p'):
                pc = (p.get('class') or [''])[0]; txt = p.get_text(' ', strip=True)
                if pc == 'sched-course-name':
                    cur = dict(sid=sid, name=name, course=txt, code='', section='', teacher='', room='', expr='', term='')
                    records.append(cur)
                elif cur is not None:
                    if pc == 'sched-section-number':
                        mm = re.match(r'(\S+)\s*\.\s*(\S+)', txt); cur['code'], cur['section'] = (mm.group(1), mm.group(2)) if mm else (txt, '')
                    elif pc == 'sched-teacher-name': cur['teacher'] = txt
                    elif pc == 'sched-room': cur['room'] = txt.replace('Room:', '').strip()
                    elif pc == 'sched-term':
                        mm = re.match(r'(\S+)\s+(\S+)', txt); cur['expr'], cur['term'] = (mm.group(1), mm.group(2)) if mm else (txt, '')
    return pd.DataFrame(records), pd.DataFrame(students), school

# --------------------------------------------------------------------------- classification
def classify(row):
    code, name = str(row.code), str(row.course).upper()
    if code.startswith('LCH') or code.startswith('GEN') or code.startswith('SED') or 'ADVISORY' in name: return 'nonacademic'
    if code.startswith('OMT'): return 'math'
    if code.startswith('OSD'): return 'ela' if 'ENGLISH' in name else 'math_support'
    if code.startswith('OEN'): return 'ela' if 'ENGLISH' in name else 'ela_elective'
    return 'other'

def short_name(course):
    c = course.replace(' - ORION', '').replace(' - ORI', '').strip()
    fixes = {'INC ': 'Inclusion ', 'ENGLISH 9TH 1': 'English 9', 'INTRO TO ENGINEERING': 'Intro Engineering',
             'INTRO HLTH SCI CAREERS': 'Health Sci Careers', 'MS OFFICE SPECIALIST': 'MS Office Specialist',
             'COMPUTER GAMING AND DESIGN': 'Gaming & Design', 'NW HISTORY': 'NW History'}
    for k, v in fixes.items(): c = c.replace(k, v)
    out = ' '.join(w if (w.isupper() and len(w) <= 3) or w in ('&',) else w.title() for w in c.split())
    out = out.replace('Ms Office', 'MS Office').replace('Nw ', 'NW ')
    return re.sub(r'^(Inclusion )?English (\d+)(?:Th)? 1$', r'\1English \2', out)

def last(t): return t.split(',')[0].strip()
def first_last(t):
    if ',' not in t: return t
    l, rest = t.split(',', 1); rest = rest.strip().split()
    return f"{rest[0]} {l}" if rest else l
def plural(n, w='student'): return f"{n} {w}{'' if n == 1 else 's'}"
def periods_phrase(ps):
    ps = sorted(ps, key=lambda x: (len(x), x))
    if len(ps) == 1: return f"period {ps[0]}"
    if len(ps) == 2: return f"periods {ps[0]} and {ps[1]}"
    return 'periods ' + ', '.join(ps[:-1]) + f', and {ps[-1]}'
def word(n): return {1: 'one', 2: 'two', 3: 'three', 4: 'four', 5: 'five', 6: 'six'}.get(n, str(n))

COLORS = ['#0E7C7B', '#3A57C9', '#C98A0C', '#7A3E9D', '#B23A48', '#2E8B57']

# --------------------------------------------------------------------------- main build
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('html'); ap.add_argument('--term'); ap.add_argument('--teachers', nargs='*'); ap.add_argument('--all', action='store_true')
    ap.add_argument('--out', default='/mnt/user-data/outputs'); ap.add_argument('--pulled', default=''); ap.add_argument('--tidy', action='store_true')
    a = ap.parse_args()

    df, st, school = parse_matrix(a.html)
    school = school or 'the school'
    df['cat'] = df.apply(classify, axis=1)
    df['short'] = df.course.map(short_name)
    df['period'] = df.expr.str.extract(r'^(\d+)')[0].fillna(df.expr.str.replace(r'\(.*', '', regex=True))
    nstud = st.sid.nunique()
    grade = st.grade.mode().iloc[0] if len(st) else '?'
    ordinal = {'9': '9th', '10': '10th', '11': '11th', '12': '12th'}.get(str(grade), f'grade {grade}')

    quarterish = df[df.term.str.match(r'^[QT]\d$', na=False)].term
    term = a.term or (quarterish.mode().iloc[0] if len(quarterish) else df.term.mode().iloc[0])
    yearlab = df[~df.term.str.match(r'^[QT]\d$', na=False)].term.mode()
    yearlab = yearlab.iloc[0] if len(yearlab) else ''
    year_pretty = re.sub(r'^(\d\d)-(\d\d)$', r'20\1–\2', yearlab) if yearlab else ''
    tq = df[(df.term == term) & (df.cat != 'nonacademic')].copy()
    if tq.empty: sys.exit(f'No academic sections found for term {term}. Terms present: {df.term.value_counts().to_dict()}')

    print(f'{school} · {ordinal} grade · term {term} · {nstud} students on roster · {len(tq)} section-seats')
    print('\nCourse classification (check this):')
    print(tq.groupby(['cat', 'course', 'code']).size().reset_index(name='seats').sort_values(['cat', 'seats'], ascending=[True, False]).to_string(index=False))
    if a.tidy: df.to_csv('enrollments.csv', index=False); st.to_csv('students.csv', index=False)

    def key(r): return (last(r.teacher), r.short, 'P' + str(r.period))
    def nm(k): return f'{k[1]} {k[2]}' if k[0] == '' else f'{k[0]}, {k[1]} ({k[2]})'
    mathmap = {r.sid: key(r) for r in tq[tq.cat == 'math'].itertuples()}
    elamap = {r.sid: key(r) for r in tq[tq.cat == 'ela'].itertuples()}
    NOM = ('', 'No Math class', 'this quarter'); NOE = ('', 'No ELA class', 'this quarter')
    tset = tq.groupby('teacher').sid.apply(set)
    teachers_all = sorted(tset.index, key=lambda t: -len(tset[t]))
    courses_by_t = tq.groupby('teacher')['short'].apply(lambda s: ', '.join(sorted(set(s))))
    pairmax = max(len(tset[x] & tset[y]) for x, y in itertools.combinations(teachers_all, 2)) if len(teachers_all) > 1 else 1
    core_teachers = sorted(set(tq[tq.cat.isin(['math', 'ela', 'math_support', 'ela_elective'])].teacher))
    single = [(c, ts.pop()) for c, ts in tq[tq.cat.isin(['math', 'ela'])].groupby('short').teacher.apply(set).items() if len(ts) == 1]

    # ---- grade-wide facts
    blank_hr = st[~st.sid.isin(df.sid)].homeroom.fillna('none listed').map(last).tolist()
    miss = {}
    for lab_, pat in (('lunch', r'^LCH'), ('advisory', None), ('a counselor', r'^GEN')):
        sub = df[df.course.str.contains('ADVISORY', case=False)] if pat is None else df[df.code.str.match(pat, na=False)]
        miss[lab_] = nstud - sub.sid.nunique()
    yog_mode = st.yog.mode().iloc[0] if len(st) else ''
    yog_other = st[st.yog != yog_mode].yog.replace({'0': 'blank/0'}).value_counts().to_dict()
    neither_all = len(set(st.sid) - set(mathmap) - set(elamap))
    # conflicts: 2+ courses in the same period for the same student
    conf = []
    dup = tq.groupby(['sid', 'period']).size(); dup = dup[dup > 1].reset_index()[['sid', 'period']]
    if len(dup):
        mg = dup.merge(tq, on=['sid', 'period'])
        pairs = mg.groupby(['sid', 'period']).apply(lambda g: tuple(sorted(f"{s} ({last(t)})" for s, t in zip(g.short, g.teacher)))).reset_index(name='pair')
        for (pair, period), g in pairs.groupby(['pair', 'period']):
            sids = set(g.sid); names = [p_.split(' (')[0] for p_ in pair]
            inc = [n for n in names if n.startswith('Inclusion')]; note = ''
            if inc:
                base = inc[0].replace('Inclusion ', '')
                if any(n != inc[0] and n.split()[0] == base.split()[0] for n in names): note = ' This looks like intentional co-teaching.'
                else:
                    subj = base.split()[0]
                    gen = tq[(tq.short.str.split().str[0] == subj) & (~tq.short.str.startswith('Inclusion')) & (tq.sid.isin(sids))].sid.nunique()
                    note = f" None of these students are in a general {base} section — verify this is the intended support model." if gen == 0 else ''
            conf.append(f"{plural(len(sids))} {'is' if len(sids) == 1 else 'are'} enrolled in both {' and '.join(pair)} in period {period}; PowerSchool flags these cells as section conflicts.{note}")

    # ---- focus teachers
    if a.teachers: focus = a.teachers
    elif a.all: focus = teachers_all
    else: focus = [t for t in teachers_all if t not in core_teachers]
    missing = [t for t in focus if t not in tset.index]
    if missing: print('\nWARNING: not found in term', term, '->', missing, '\nTeachers present:', teachers_all)
    focus = [t for t in focus if t in tset.index]
    os.makedirs(a.out, exist_ok=True)

    for ME in focus:
        me = tq[tq.teacher == ME]; mine = set(me.sid); nuniq = len(mine)
        dupn = sum(1 for s in mine if (me.sid == s).sum() > 1)
        seats = me.groupby('short').size()
        left = sorted({key(r) for r in me.itertuples()}, key=lambda k: (-seats[k[1]], k[1], k[2]))
        ORIGIN = {k: (f'o{i}', COLORS[i % len(COLORS)]) for i, k in enumerate(left)}
        triples = defaultdict(int)
        for r in me.itertuples(): triples[(key(r), mathmap.get(r.sid, NOM), elamap.get(r.sid, NOE))] += 1
        mid = sorted({t[1] for t in triples}, key=lambda k: (k == NOM, k)); right = sorted({t[2] for t in triples}, key=lambda k: (k == NOE, k))
        li = {k: i for i, k in enumerate(left)}; mi = {k: i for i, k in enumerate(mid)}; ri = {k: i for i, k in enumerate(right)}
        sizes = {}
        for pos, tag, col in ((0, 'L', left), (1, 'M', mid), (2, 'R', right)):
            for k in col: sizes[(tag, k)] = sum(v for t, v in triples.items() if t[pos] == k)
        N = sum(sizes[('L', k)] for k in left)

        W, HT = 1180, 660; TOP, BOT = 58, 18; avail = HT - TOP - BOT; NW = 16
        X = {'L': 222, 'M': 592, 'R': 948}; MINGAP = 14; LABEL_MIN = 34
        def place(col, tag, scale):
            hs = [sizes[(tag, k)] * scale for k in col]
            gaps = [max(MINGAP, LABEL_MIN - (hs[i - 1] / 2 + hs[i] / 2)) for i in range(1, len(col))]
            used = sum(hs) + sum(gaps)
            if len(col) > 1 and used < avail:
                extra = (avail - used) / (len(col) - 1); gaps = [g + extra for g in gaps]
            y = TOP if len(col) > 1 else TOP + (avail - hs[0]) / 2
            pos = {}
            for i, k in enumerate(col): pos[k] = (y, y + hs[i]); y += hs[i] + (gaps[i] if i < len(gaps) else 0)
            return pos, used
        scale = min((avail - MINGAP * (len(col) - 1)) / N for col in (left, mid, right))
        for _ in range(40):
            worst = max(place(col, tag, scale)[1] for col, tag in ((left, 'L'), (mid, 'M'), (right, 'R')))
            if worst <= avail + 0.5: break
            scale *= avail / worst
        PL, PM, PR = place(left, 'L', scale)[0], place(mid, 'M', scale)[0], place(right, 'R', scale)[0]
        def alloc(order, node_pos, idx):
            used = defaultdict(float); res = {}
            for t in order:
                v = triples[t] * scale; n = t[idx]; y = node_pos[n][0] + used[n]; used[n] += v; res[t] = (y, y + v)
            return res
        s1o = alloc(sorted(triples, key=lambda t: (li[t[0]], mi[t[1]], ri[t[2]])), PL, 0)
        s1i = alloc(sorted(triples, key=lambda t: (mi[t[1]], li[t[0]], ri[t[2]])), PM, 1)
        s2o = alloc(sorted(triples, key=lambda t: (mi[t[1]], ri[t[2]], li[t[0]])), PM, 1)
        s2i = alloc(sorted(triples, key=lambda t: (ri[t[2]], mi[t[1]], li[t[0]])), PR, 2)
        def ribbon(x0, x1, ya, yb, yc, yd):
            xm = (x0 + x1) / 2
            return f'M{x0:.1f},{ya:.1f} C{xm:.1f},{ya:.1f} {xm:.1f},{yc:.1f} {x1:.1f},{yc:.1f} L{x1:.1f},{yd:.1f} C{xm:.1f},{yd:.1f} {xm:.1f},{yb:.1f} {x0:.1f},{yb:.1f} Z'
        svg = [f'<svg viewBox="0 0 {W} {HT}" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="ttl" font-family="inherit">',
               f"<title id=\"ttl\">Where {H.escape(first_last(ME))}'s {term} students sit for Math and ELA</title>",
               '<defs><pattern id="hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="#E3E8EC"/><line x1="0" y1="0" x2="0" y2="6" stroke="#B9C2CB" stroke-width="2"/></pattern></defs>']
        for tag, txt, anchor in (('L', 'Your sections', 'end'), ('M', 'Their Math class', 'middle'), ('R', 'Their ELA class', 'start')):
            x = {'L': X['L'] + NW, 'M': X['M'] + NW / 2, 'R': X['R']}[tag]
            svg.append(f'<text class="colhead" x="{x}" y="30" text-anchor="{anchor}">{txt}</text>')
        for t in triples:
            o, m, e = t; cls, col = ORIGIN[o]; v = triples[t]
            tip = H.escape(f"You, {o[1]} ({o[2]}) → {nm(m)} → {nm(e)}: {plural(v)}")
            (ya, yb), (yc, yd) = s1o[t], s1i[t]
            svg.append(f'<path class="rib {cls}" fill="{col}" d="{ribbon(X["L"] + NW, X["M"], ya, yb, yc, yd)}"><title>{tip}</title></path>')
            (ya, yb), (yc, yd) = s2o[t], s2i[t]
            svg.append(f'<path class="rib {cls}" fill="{col}" d="{ribbon(X["M"] + NW, X["R"], ya, yb, yc, yd)}"><title>{tip}</title></path>')
        def node(tag, k, pos):
            y0, y1 = pos; h = y1 - y0; n = sizes[(tag, k)]; x = X[tag]
            if k[0] == '': fill, extra = 'url(#hatch)', ' stroke="#8E99A4" stroke-dasharray="3 3" stroke-width="1"'
            elif tag == 'L': fill, extra = ORIGIN[k][1], ''
            else: fill, extra = '#2A3644', ''
            dat = f' data-o="{ORIGIN[k][0]}"' if tag == 'L' else ''
            s = f'<g class="node"{dat}><rect x="{x}" y="{y0:.1f}" width="{NW}" height="{max(h, 2):.1f}" rx="2" fill="{fill}"{extra}/>'
            tx, anc = (x - 12, 'end') if tag == 'L' else (x + NW + 10, 'start'); cy = y0 + h / 2
            if k[0] == '':
                s += f'<text class="lab" x="{tx}" y="{cy - 4:.1f}" text-anchor="{anc}">{H.escape(k[1])}</text>'
                s += f'<text class="lab sub" x="{tx}" y="{cy + 12:.1f}" text-anchor="{anc}">{H.escape(k[2])} · <tspan class="num">{n}</tspan></text>'
            else:
                who = 'You' if tag == 'L' else k[0]
                s += f'<text class="lab who" x="{tx}" y="{cy - 4:.1f}" text-anchor="{anc}">{H.escape(who)}<tspan class="sub">, {H.escape(k[1])}</tspan></text>'
                s += f'<text class="lab sub" x="{tx}" y="{cy + 12:.1f}" text-anchor="{anc}">Period {k[2][1:]} · <tspan class="num">{n}</tspan> student{"s" if n != 1 else ""}</text>'
            return s + '</g>'
        for k in left: svg.append(node('L', k, PL[k]))
        for k in mid: svg.append(node('M', k, PM[k]))
        for k in right: svg.append(node('R', k, PR[k]))
        svg.append('</svg>'); SVG = '\n'.join(svg)

        # ---- partners: Math/ELA teachers ranked by shared students, prose from the data
        def seclab(k): return f"period-{k[2][1:]} {k[1]}"
        partners = []
        for T in sorted(core_teachers, key=lambda t: -len(mine & tset[t])):
            if T == ME: continue
            shared = len(mine & tset[T])
            if shared == 0: continue
            tsecs = tq[tq.teacher == T]
            course_line = ' · '.join(f"{c} — {periods_phrase(sorted({str(p) for p in g.period}))}" for c, g in tsecs.groupby('short'))
            core = tsecs[tsecs.cat.isin(['math', 'ela'])]
            cl = []
            mykeys = me.apply(key, axis=1); tkeys = core.apply(key, axis=1) if len(core) else None
            for mk in left:
                mset = set(me[mykeys == mk].sid)
                if tkeys is None: break
                for tk, g in core.groupby(tkeys):
                    k_ = len(mset & set(g.sid))
                    if k_: cl.append((k_, mk, tk))
            cl.sort(key=lambda x: -x[0])
            sents, prev = [], None
            for k_, mk, tk in cl[:3]:
                if k_ < 3 and sents: break
                p, qq = int(mk[2][1:]), int(tk[2][1:]) if tk[2][1:].isdigit() else -99; nsec = sizes[('L', mk)]; one = k_ == 1
                if mk == prev: base, vb = f"Another {k_} from that class", dict(go='go', come='come', are='are')
                else: base, vb = f"{k_} of your {nsec} {seclab(mk)} students", dict(go='goes' if one else 'go', come='comes' if one else 'come', are='is' if one else 'are')
                if qq == p + 1: sents.append(f"{base} {vb['go']} straight from your room into {last(T)}'s {seclab(tk)}.")
                elif qq == p - 1: sents.append(f"{base} {vb['come']} to you straight from {last(T)}'s {seclab(tk)}.")
                elif qq == p: sents.append(f"{base} {vb['are']} also listed in {last(T)}'s {seclab(tk)}, the same block as your class (see the counselor notes below).")
                else: sents.append(f"{base} {vb['are']} in {last(T)}'s {seclab(tk)}.")
                prev = mk
            why = ('Your biggest overlap. ' if not partners else '') + ' '.join(sents)
            for cat, phrase in (('ela_elective', "also in {T}'s {c} elective ({p})"), ('math_support', "in {T}'s {c} support section ({p}), which runs alongside the general section in the same block")):
                for c, g in tsecs[tsecs.cat == cat].groupby('short'):
                    n_ = len(mine & set(g.sid))
                    if n_: why += f" {n_} of your students {'is' if n_ == 1 else 'are'} " + phrase.format(T=last(T), c=c, p=periods_phrase(sorted({str(p) for p in g.period}))) + '.'
            tmath = set(tsecs[tsecs.cat == 'math'].short)
            if tmath and not any(c.startswith('Algebra 1') for c in tmath): why += ' These are your students in accelerated Math; they can carry more algebra in your class.'
            if shared <= 3 and tsecs.cat.isin(['math_support']).any(): why += ' Worth a short check-in on accommodations rather than a pacing partnership.'
            partners.append((first_last(T), course_line, shared, why.strip()))
        prow = ''.join(f'<div class="partner"><div class="pnum">{n}<span>of {nuniq}</span></div><div><div class="pname">{H.escape(nme)}</div><div class="pcourse">{H.escape(c)}</div><p>{H.escape(why)}</p></div></div>' for nme, c, n, why in partners)

        # ---- team matrix
        hdr = ''.join(f'<th class="{"me" if t == ME else ""}"><span>{H.escape(last(t))}</span></th>' for t in teachers_all)
        rows = ''
        for x_ in teachers_all:
            cells = ''
            for y_ in teachers_all:
                if x_ == y_: cells += '<td class="diag"></td>'; continue
                v = len(tset[x_] & tset[y_]); alpha = 0.08 + 0.72 * (v / pairmax) if v else 0
                style = f' style="background:rgba(42,54,68,{alpha:.2f});color:{"#fff" if alpha > 0.45 else "#17212B"}"' if v else ''
                cells += f'<td class="v{" me" if ME in (x_, y_) else ""}"{style}>{v if v else "–"}</td>'
            rows += f'<tr class="{"me" if x_ == ME else ""}"><th><span class="tn">{H.escape(last(x_))}</span><span class="tc">{H.escape(courses_by_t[x_])} · {len(tset[x_])}</span></th>{cells}</tr>'
        matrix = f'<table class="grid"><thead><tr><th></th>{hdr}</tr></thead><tbody>{rows}</tbody></table>'
        single_txt = ('; '.join(f"{last(t)} is the only teacher of {c}" for c, t in single) + ', so every Math or ELA tie-in in the grade runs through ' + ('them' if len(single) > 1 else 'that person') + '.') if single else ''

        my_courses = ' and '.join(f"{c} ({periods_phrase(sorted({str(p) for p in g.period}))})" for c, g in sorted(me.groupby('short'), key=lambda cg: -len(cg[1])))
        legend = ''.join(f'<span><i style="background:{ORIGIN[k][1]}"></i>{H.escape(k[1])}, period {k[2][1:]}</span>' for k in left)
        no_math_me = len(mine - set(mathmap)); no_ela_me = len(mine - set(elamap))
        slug = term.lower() + last(ME).lower().replace(' ', '')
        css_focus = ''.join(f"svg.f-o{i} .rib:not(.o{i}){{opacity:.07}}svg.f-o{i} .rib.o{i}{{opacity:.85}}" for i in range(len(left)))
        electives = sorted(set(tq[tq.cat == 'ela_elective'].short)); supports = sorted(set(tq[tq.cat == 'math_support'].short))
        cap_extra = ''
        if electives: cap_extra += f" {', '.join(electives)} (ELA elective{'s' if len(electives) > 1 else ''}) {'is' if len(electives) == 1 else 'are'} not counted as an ELA class here."
        if supports: cap_extra += f" {', '.join(supports)} {'is a support section' if len(supports) == 1 else 'are support sections'} running alongside the general class and {'is' if len(supports) == 1 else 'are'} not drawn separately."
        dup_txt = f"; {plural(dupn)} {'is' if dupn == 1 else 'are'} in two of your sections and appear{'s' if dupn == 1 else ''} twice" if dupn else ''
        conf_li = ''.join(f'<li>{H.escape(c)}</li>' for c in conf)
        blank_li = f"<li>{plural(len(blank_hr))} on the {ordinal}-grade roster {'has' if len(blank_hr) == 1 else 'have'} an entirely blank matrix — no classes, lunch, advisory, or counselor assignment (homeroom listed as {', '.join(blank_hr)}).</li>" if blank_hr else ''
        yog_li = f"<li>Year of graduation reads {yog_mode} for {int((st.yog == yog_mode).sum())} students, but " + ', '.join(f"{k} for {v}" for k, v in yog_other.items()) + '.</li>' if yog_other else ''
        qname = {'Q': 'Quarter', 'T': 'Trimester'}.get(term[0], 'Term') + ' ' + term[1:]
        pulled = f' pulled {a.pulled}' if a.pulled else ''

        page = f'''<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{slug} — {term} Math and ELA co-enrollment, {ordinal} grade, {H.escape(school)}</title>
<style>
:root{{--bg:#F5F7F8;--ink:#17212B;--mute:#63707E;--rule:#D9DFE4;--o0:{COLORS[0]}}}
*{{box-sizing:border-box}}html{{background:var(--bg)}}
body{{margin:0;color:var(--ink);font:15px/1.5 "Avenir Next","Avenir","Segoe UI Variable Text","Segoe UI","Helvetica Neue",Helvetica,Arial,sans-serif;font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased}}
main{{max-width:1180px;margin:0 auto;padding:44px 28px 64px}}
.kicker{{color:var(--mute);margin:0 0 10px;font-size:14px}}
h1{{font-size:clamp(24px,3.2vw,34px);line-height:1.15;font-weight:650;letter-spacing:-0.015em;margin:0 0 10px;max-width:30ch}}
.lede{{color:var(--mute);max-width:70ch;margin:0 0 6px}}
.legend{{display:flex;gap:18px 26px;flex-wrap:wrap;margin:20px 0 6px;font-size:14px;color:var(--mute)}}
.legend span{{display:inline-flex;align-items:center;gap:8px}}.legend i{{width:22px;height:10px;border-radius:2px;display:inline-block}}
.legend .none{{background:repeating-linear-gradient(45deg,#E3E8EC 0 3px,#B9C2CB 3px 5px);border:1px dashed #8E99A4}}
figure{{margin:0;background:#fff;border:1px solid var(--rule);border-radius:6px;padding:14px 18px 8px}}figure svg{{width:100%;height:auto;display:block}}
.colhead{{font-size:13px;font-weight:650;fill:var(--mute)}}
.lab{{font-size:13.5px;fill:var(--ink);paint-order:stroke;stroke:#fff;stroke-width:5px;stroke-linejoin:round}}.lab.who{{font-weight:650}}
.lab .sub,.lab.sub{{fill:var(--mute);font-weight:400}}.lab .num{{fill:var(--ink);font-weight:650}}
.rib{{opacity:.55;transition:opacity .15s ease}}.rib:hover{{opacity:.9}}{css_focus}
.node[data-o]{{cursor:pointer}}.node[data-o]:focus-visible rect{{stroke:var(--ink);stroke-width:2}}
figcaption{{font-size:13px;color:var(--mute);padding:10px 2px 6px;max-width:90ch}}
h2{{font-size:20px;font-weight:650;letter-spacing:-0.01em;margin:44px 0 6px}}.h2sub{{color:var(--mute);margin:0 0 18px;max-width:70ch}}
.partners{{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:0 36px}}
.partner{{display:grid;grid-template-columns:78px 1fr;gap:14px;padding:18px 0;border-top:1px solid var(--rule)}}
.pnum{{font-size:34px;font-weight:650;line-height:1;letter-spacing:-0.02em}}.pnum span{{display:block;font-size:12px;color:var(--mute);font-weight:400;letter-spacing:0;margin-top:4px}}
.pname{{font-weight:650}}.pcourse{{color:var(--mute);font-size:13.5px}}.partner p{{margin:6px 0 0;font-size:14px;max-width:52ch}}
.gridwrap{{overflow-x:auto;background:#fff;border:1px solid var(--rule);border-radius:6px;padding:10px 12px}}
.grid{{border-collapse:separate;border-spacing:3px;font-size:13.5px;width:100%}}.grid th{{font-weight:500;text-align:left;color:var(--ink);vertical-align:bottom}}
.grid thead th span{{display:inline-block;writing-mode:vertical-rl;transform:rotate(180deg);padding:6px 0 2px;color:var(--mute)}}.grid thead th.me span{{color:var(--ink);font-weight:650}}
.grid tbody th{{white-space:nowrap;padding-right:10px;line-height:1.2;min-width:170px}}.grid .tn{{display:block;font-weight:600}}
.grid .tc{{display:block;font-size:11.5px;color:var(--mute);font-weight:400;max-width:230px;white-space:normal}}
.grid td{{text-align:center;min-width:44px;height:38px;border-radius:3px;background:#F1F4F6;color:var(--mute)}}.grid td.diag{{background:transparent}}
.grid tr.me th .tn{{color:var(--o0)}}.grid tr.me td.v,.grid td.me{{box-shadow:inset 0 0 0 1.5px var(--o0)}}
.notes{{font-size:14px;color:var(--ink);max-width:78ch}}.notes li{{margin:6px 0}}.notes ul{{padding-left:20px;margin:8px 0 0}}
.small{{font-size:12.5px;color:var(--mute);margin-top:30px;max-width:80ch}}
@media (prefers-reduced-motion:reduce){{.rib{{transition:none}}}}
@media (max-width:640px){{main{{padding:28px 14px 48px}}.partner{{grid-template-columns:64px 1fr}}.pnum{{font-size:28px}}.partners{{grid-template-columns:1fr}}}}
</style></head><body><main>
<p class="kicker">Prepared for {H.escape(first_last(ME))}: {H.escape(my_courses)}. {qname}{', ' + year_pretty if year_pretty else ''}.</p>
<h1>Where your freshmen sit for Math and ELA this quarter</h1>
<p class="lede">All {nuniq} students in your {word(len(left))} {term} section{'s' if len(left) != 1 else ''}, traced from your room to their Math class and then to their ELA class. Ribbon width is the number of students; color is which of your sections they come from. Hover or tap a ribbon for the exact path; click one of your sections to isolate it.</p>
<div class="legend">{legend}<span><i class="none"></i>No class in that subject this quarter</span></div>
<figure>
{SVG}
<figcaption>{H.escape(school)}, {ordinal} grade, {qname}{' ' + year_pretty if year_pretty else ''}, from the PowerSchool schedule matrix{pulled}. {N} seats across your {word(len(left))} section{'s' if len(left) != 1 else ''}{dup_txt}.{cap_extra}</figcaption>
</figure>
<h2>Who to sit down with</h2>
<p class="h2sub">The Math and ELA teachers ranked by how many of your {nuniq} students they also have this quarter.</p>
<div class="partners">{prow}</div>
<h2>The whole {ordinal}-grade team</h2>
<p class="h2sub">Students shared between each pair of teachers in {term} academic sections. Darker means more shared students; your row and column are outlined. {H.escape(single_txt)}</p>
<div class="gridwrap">{matrix}</div>
<h2>Things worth a look from the counselor</h2>
<div class="notes"><ul>
{blank_li}{conf_li}
<li>Missing year-long assignments: {plural(miss['lunch'])} without lunch, {miss['advisory']} without advisory, {miss['a counselor']} without a counselor.</li>
{yog_li}
<li>Grade-wide this quarter: {len(mathmap)} of {nstud} freshmen have a Math class and {len(elamap)} have an ELA class; {neither_all} have neither. Of your {nuniq} students, {no_math_me} have no Math class and {no_ela_me} have no ELA class in {term}.</li>
</ul></div>
<p class="small">Student names and IDs from the source report were used only to count overlaps and do not appear on this page. Regenerate each quarter from the new matrix report; the sections and teachers will shift.</p>
</main>
<script>
(function(){{var svg=document.querySelector('figure svg');var pinned=null;var all=[{','.join(f"'f-o{i}'" for i in range(len(left)))}];
function set(o){{svg.classList.remove.apply(svg.classList,all);if(o)svg.classList.add('f-'+o);}}
svg.querySelectorAll('.node[data-o]').forEach(function(n){{n.setAttribute('tabindex','0');
n.addEventListener('mouseenter',function(){{if(!pinned)set(n.dataset.o);}});n.addEventListener('mouseleave',function(){{if(!pinned)set(null);}});
n.addEventListener('focus',function(){{if(!pinned)set(n.dataset.o);}});n.addEventListener('blur',function(){{if(!pinned)set(null);}});
n.addEventListener('click',function(){{pinned=(pinned===n.dataset.o)?null:n.dataset.o;set(pinned);}});
n.addEventListener('keydown',function(e){{if(e.key==='Enter'||e.key===' '){{e.preventDefault();n.click();}}}});}});
svg.querySelectorAll('.rib').forEach(function(p){{var o=[].filter.call(p.classList,function(c){{return /^o\\d$/.test(c);}})[0];
p.addEventListener('mouseenter',function(){{if(!pinned)set(o);}});p.addEventListener('mouseleave',function(){{if(!pinned)set(null);}});}});}})();
</script></body></html>'''
        out = os.path.join(a.out, f'{slug}.html'); open(out, 'w').write(page)
        print(f"\n{slug}.html  {nuniq} students / {N} seats | " + ' | '.join(f"{k[1]} {k[2]}={sizes[('L', k)]}" for k in left) + f" | no Math {no_math_me}, no ELA {no_ela_me}")
        for nme, c, n, why in partners: print(f"   {n:3d} {nme}: {why}")

    print('\nGrade-wide:', f"{len(mathmap)}/{nstud} have Math, {len(elamap)}/{nstud} have ELA, {neither_all} neither.")
    for c in conf: print(' -', c)
    if blank_hr: print(' -', plural(len(blank_hr)), 'with a blank matrix; homeroom:', blank_hr)
    print(' - missing:', miss, '| YOG other than', yog_mode, ':', yog_other)

if __name__ == '__main__':
    main()
