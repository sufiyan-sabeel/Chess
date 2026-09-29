#!/usr/bin/env python3
"""Pure-Python zipalign (Termux has no `zipalign` binary).

Aligns the start of each entry's data in the local header to N bytes by
adding a private extra field (this is how zipalign itself works for
uncompressed entries).

Usage:
  zipalign.py IN.apk OUT.apk [alignment]   # default alignment 4
  zipalign.py --check FILE.apk [alignment] # exit 1 when misaligned
"""
from __future__ import annotations

import struct
import sys
import zipfile

PRIVATE_EXTRA_ID = 0xCAFE  # private-use extra field used purely for padding


def _pad_extra(data_start: int, alignment: int) -> bytes:
    """Return an extra field so data_start + 4 + len(extra) is aligned."""
    rem = data_start % alignment
    need = (alignment - rem) % alignment
    if need == 0:
        return b""
    if need < 4:
        need += alignment  # extra header itself is 4 bytes
    payload_len = need - 4
    return struct.pack("<HH", PRIVATE_EXTRA_ID, payload_len) + b"\0" * payload_len


def align(src: str, dst: str, alignment: int) -> None:
    zin = zipfile.ZipFile(src, "r")
    with zipfile.ZipFile(dst, "w", zipfile.ZIP_DEFLATED) as zout:
        for info in zin.infolist():
            data = zin.read(info.filename)
            zi = zipfile.ZipInfo(info.filename, date_time=info.date_time)
            zi.compress_type = info.compress_type
            zi.external_attr = info.external_attr
            zi.internal_attr = info.internal_attr
            zi.create_system = info.create_system
            zi.comment = info.comment

            name_len = len(zi.filename.encode("utf-8"))
            # offset where THIS entry's local header will start
            header_start = zout.fp.tell()
            base_data_start = header_start + 30 + name_len
            zi.extra = _pad_extra(base_data_start, alignment)
            zout.writestr(zi, data)
        zout.comment = zin.comment
    zin.close()


def check(path: str, alignment: int) -> int:
    """Verify every entry's data offset is aligned. Returns 0 when OK.

    META-INF/* is skipped: apksigner appends signature entries *after*
    alignment, and those never require alignment.
    """
    bad = []
    with open(path, "rb") as f:
        with zipfile.ZipFile(path) as z:
            for info in z.infolist():
                if info.filename.startswith("META-INF/"):
                    continue
                # locate local header via central directory offset
                if info.header_offset is None:
                    continue
                f.seek(info.header_offset)
                hdr = f.read(30)
                if len(hdr) < 30 or hdr[:4] != b"PK\x03\x04":
                    bad.append((info.filename, "bad local header"))
                    continue
                name_len, extra_len = struct.unpack("<HH", hdr[26:30])
                data_start = info.header_offset + 30 + name_len + extra_len
                if data_start % alignment:
                    bad.append((info.filename, f"data offset {data_start} not {alignment}-aligned"))
    if bad:
        for name, why in bad[:20]:
            print(f"MISALIGNED: {name}: {why}", file=sys.stderr)
        return 1
    return 0


def main(argv) -> int:
    if len(argv) >= 2 and argv[1] == "--check":
        path = argv[2]
        alignment = int(argv[3]) if len(argv) > 3 else 4
        return check(path, alignment)
    if len(argv) < 3:
        print(__doc__, file=sys.stderr)
        return 2
    src, dst = argv[1], argv[2]
    alignment = int(argv[3]) if len(argv) > 3 else 4
    align(src, dst, alignment)
    return check(dst, alignment)


if __name__ == "__main__":
    sys.exit(main(sys.argv))
