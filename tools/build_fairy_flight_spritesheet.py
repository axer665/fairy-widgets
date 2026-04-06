#!/usr/bin/env python3
"""
Build a flight/hover sprite sheet from a single fairy render (RGB, black background).
Produces transparent PNG grid + sprite-sheet.json (frame size, grid, fps hint).
"""
from __future__ import annotations

import argparse
import json
import math
from pathlib import Path

from PIL import Image


def rgb_to_rgba_transparent_black(im: Image.Image, threshold: int = 28) -> Image.Image:
    im = im.convert("RGBA")
    px = im.load()
    w, h = im.size
    for y in range(h):
        for x in range(w):
            r, g, b, a = px[x, y]
            if r <= threshold and g <= threshold and b <= threshold:
                px[x, y] = (0, 0, 0, 0)
    return im


def crop_content(im: Image.Image, pad: int) -> Image.Image:
    bbox = im.getbbox()
    if not bbox:
        return im
    l, t, r, b = bbox
    l = max(0, l - pad)
    t = max(0, t - pad)
    r = min(im.width, r + pad)
    b = min(im.height, b + pad)
    return im.crop((l, t, r, b))


def render_frame(base: Image.Image, phase: float) -> Image.Image:
    ang = 3.2 * math.sin(phase + 0.35)
    wing_w = 1.0 + 0.036 * math.sin(2.0 * phase)
    wing_h = 1.0 + 0.014 * math.sin(phase + math.pi * 0.6)

    out = base.rotate(
        ang,
        expand=True,
        fillcolor=(0, 0, 0, 0),
        resample=Image.BICUBIC,
    )
    nw = max(1, int(round(out.width * wing_w)))
    nh = max(1, int(round(out.height * wing_h)))
    out = out.resize((nw, nh), Image.LANCZOS)
    return out


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument(
        "--input",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "fe-client/public/sprites/fairy-base.png",
    )
    p.add_argument(
        "--output",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "fe-client/public/sprites/fairy-flight-sheet.png",
    )
    p.add_argument("--cols", type=int, default=4)
    p.add_argument("--rows", type=int, default=2)
    p.add_argument("--pad", type=int, default=56, help="Padding around cropped character")
    p.add_argument("--cell-pad", type=int, default=20, help="Inner padding inside each cell")
    p.add_argument("--bob", type=float, default=14.0, help="Vertical bob amplitude in pixels")
    args = p.parse_args()

    n = args.cols * args.rows
    if n < 1:
        raise SystemExit("cols * rows must be >= 1")

    base = Image.open(args.input)
    base = rgb_to_rgba_transparent_black(base)
    base = crop_content(base, args.pad)

    frames: list[tuple[Image.Image, int]] = []
    for i in range(n):
        phase = (2.0 * math.pi * i) / n
        dy = int(round(args.bob * math.sin(phase)))
        frames.append((render_frame(base, phase), dy))

    max_w = max(im.width for im, _ in frames)
    max_h = max(im.height for im, _ in frames)
    extra_y = int(math.ceil(args.bob))
    cell_w = max_w + 2 * args.cell_pad
    cell_h = max_h + 2 * args.cell_pad + 2 * extra_y

    sheet_w = args.cols * cell_w
    sheet_h = args.rows * cell_h
    sheet = Image.new("RGBA", (sheet_w, sheet_h), (0, 0, 0, 0))

    try:
        source_rel = str(args.input.relative_to(Path(__file__).resolve().parents[1]))
    except ValueError:
        source_rel = str(args.input)

    meta = {
        "source": source_rel,
        "frameWidth": cell_w,
        "frameHeight": cell_h,
        "columns": args.cols,
        "rows": args.rows,
        "frameCount": n,
        "fps": 12,
        "loop": True,
        "notes": "Single-source procedural hover: tilt, bob, subtle width/height pulse (wing/dress hint).",
    }

    for i, (im, dy) in enumerate(frames):
        col = i % args.cols
        row = i // args.cols
        cell = Image.new("RGBA", (cell_w, cell_h), (0, 0, 0, 0))
        x = (cell_w - im.width) // 2
        y = (cell_h - im.height) // 2 + dy
        cell.paste(im, (x, y), im)
        sheet.paste(cell, (col * cell_w, row * cell_h))

    args.output.parent.mkdir(parents=True, exist_ok=True)
    sheet.save(args.output, format="PNG", optimize=True)

    json_path = args.output.with_suffix(".json")
    json_path.write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {args.output} ({sheet_w}x{sheet_h})")
    print(f"Wrote {json_path}")


if __name__ == "__main__":
    main()
