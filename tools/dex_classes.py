#!/usr/bin/env python3
"""List class descriptors contained in a classes.dex (no external tools).

Usage: dex_classes.py FILE.dex            -> one class name per line
       dex_classes.py --contains NAME     -> exit 0 when NAME is present
"""
from __future__ import annotations

import struct
import sys


def uleb128(buf: bytes, off: int) -> tuple[int, int]:
    result = 0
    shift = 0
    while True:
        b = buf[off]
        off += 1
        result |= (b & 0x7F) << shift
        if not (b & 0x80):
            return result, off
        shift += 7


def read_mutf8(buf: bytes, off: int) -> str:
    _, off = uleb128(buf, off)  # utf16 length (not byte length)
    end = buf.index(b"\x00", off)
    return buf[off:end].decode("utf-8", "replace")


def class_names(path: str) -> list[str]:
    with open(path, "rb") as f:
        buf = f.read()
    if buf[:4] != b"dex\n":
        raise SystemExit("not a dex file")
    (
        _magic, _checksum, _signature,
        file_size, header_size, endian,
        link_size, link_off, map_off,
        string_ids_size, string_ids_off,
        type_ids_size, type_ids_off,
        proto_ids_size, proto_ids_off,
        field_ids_size, field_ids_off,
        method_ids_size, method_ids_off,
        class_defs_size, class_defs_off,
        data_size, data_off,
    ) = struct.unpack("<8sI20sIIIIIIIIIIIIIIIIIIII", buf[:112])

    out = []
    for i in range(class_defs_size):
        base = class_defs_off + i * 32
        class_idx = struct.unpack_from("<I", buf, base)[0]
        type_str_idx = struct.unpack_from("<I", buf, type_ids_off + class_idx * 4)[0]
        str_off = struct.unpack_from("<I", buf, string_ids_off + type_str_idx * 4)[0]
        desc = read_mutf8(buf, str_off)
        name = desc[1:-1] if desc.startswith("L") and desc.endswith(";") else desc
        out.append(name.replace("/", "."))
    return out


def main(argv) -> int:
    if len(argv) < 2:
        print(__doc__, file=sys.stderr)
        return 2
    if argv[1] == "--contains":
        needle, path = argv[2], argv[3]
        names = class_names(path)
        return 0 if any(needle == n or needle in n for n in names) else 1
    for n in class_names(argv[1]):
        print(n)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
