#!/usr/bin/env python3
"""Compiles languages/*.po into .mo files: python3 tools/compile-mo.py

Same output as gettext's msgfmt for these files, without needing gettext.
"""
import glob
import re
import struct


def parse(path):
    entries, cur, field = {}, {}, None
    for line in open(path, encoding='utf-8').read().splitlines() + ['']:
        m = re.match(r'^(msgid|msgstr) "(.*)"$', line)
        if m:
            if m.group(1) == 'msgid' and 'msgstr' in cur:
                entries[cur['msgid']] = cur['msgstr']
                cur = {}
            field = m.group(1)
            cur[field] = unquote(m.group(2))
        elif line.startswith('"') and field:
            cur[field] += unquote(line[1:-1])
        elif not line.strip() and 'msgstr' in cur:
            entries[cur['msgid']] = cur['msgstr']
            cur, field = {}, None
    return {k: v for k, v in entries.items() if v}


def unquote(s):
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t'}.get(m.group(1), m.group(1)), s)


def write_mo(entries, path):
    items = sorted(entries.items())
    ids = strs = b''
    offsets = []
    for k, v in items:
        kb, vb = k.encode(), v.encode()
        offsets.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\0'
        strs += vb + b'\0'
    n = len(items)
    keys_at = 28
    values_at = keys_at + n * 8
    ids_at = values_at + n * 8
    strs_at = ids_at + len(ids)
    out = struct.pack('<7I', 0x950412DE, 0, n, keys_at, values_at, 0, 0)
    for o, length, _, _ in offsets:
        out += struct.pack('<2I', length, ids_at + o)
    for _, _, o, length in offsets:
        out += struct.pack('<2I', length, strs_at + o)
    open(path, 'wb').write(out + ids + strs)


for po in sorted(glob.glob('languages/*.po')):
    write_mo(parse(po), po[:-3] + '.mo')
    print(po[:-3] + '.mo')
