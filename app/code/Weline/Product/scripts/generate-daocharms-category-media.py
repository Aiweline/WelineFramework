#!/usr/bin/env python3
"""Generate DaoCharms category icons (800x800) and banners (1500x300) — Plan B ink + brass."""

from __future__ import annotations

import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter

ROOT = Path(__file__).resolve().parents[5]
ICON_DIR = ROOT / "pub/media/catalog/daocharms/categories/icons"
BANNER_DIR = ROOT / "pub/media/catalog/daocharms/categories/banners"

# Plan B palette
FOG = (241, 240, 239)
INK = (38, 36, 34)
INK_SOFT = (74, 85, 96)
BRASS = (154, 123, 79)
BRASS_SOFT = (196, 165, 110)
MOSS = (150, 163, 143)
STONE = (92, 88, 84)
OBSIDIAN = (28, 30, 34)
JADE = (110, 140, 120)
CRYSTAL = (180, 190, 200)


def new_canvas(size: tuple[int, int], color=FOG) -> Image.Image:
    return Image.new("RGB", size, color)


def save_webp(img: Image.Image, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    img.save(path, "WEBP", quality=88, method=6)


def circle(draw: ImageDraw.ImageDraw, cx: float, cy: float, r: float, fill, outline=None, width=2):
    bbox = [cx - r, cy - r, cx + r, cy + r]
    draw.ellipse(bbox, fill=fill, outline=outline, width=width)


def line(draw, a, b, fill, width=3):
    draw.line([a, b], fill=fill, width=width)


def yin_yang(draw, cx, cy, r):
    circle(draw, cx, cy, r, FOG, INK, 4)
    draw.pieslice([cx - r, cy - r, cx + r, cy + r], 90, 270, fill=INK)
    draw.pieslice([cx - r, cy - r, cx + r, cy + r], -90, 90, fill=FOG)
    circle(draw, cx, cy - r / 2, r / 2, INK)
    circle(draw, cx, cy + r / 2, r / 2, FOG)
    circle(draw, cx, cy - r / 2, r / 8, FOG)
    circle(draw, cx, cy + r / 2, r / 8, INK)
    circle(draw, cx, cy, r, None, BRASS, 3)


def bagua(draw, cx, cy, r):
    circle(draw, cx, cy, r * 0.35, None, INK, 3)
    for i in range(8):
        ang = math.radians(i * 45 - 90)
        x1 = cx + math.cos(ang) * r * 0.45
        y1 = cy + math.sin(ang) * r * 0.45
        x2 = cx + math.cos(ang) * r * 0.9
        y2 = cy + math.sin(ang) * r * 0.9
        # broken / solid bars
        if i % 2 == 0:
            line(draw, (x1, y1), (x2, y2), INK, 5)
        else:
            mx, my = (x1 + x2) / 2, (y1 + y2) / 2
            line(draw, (x1, y1), (mx - 6 * math.cos(ang + math.pi / 2), my - 6 * math.sin(ang + math.pi / 2)), INK, 5)
            line(draw, (mx + 6 * math.cos(ang + math.pi / 2), my + 6 * math.sin(ang + math.pi / 2)), (x2, y2), INK, 5)
    circle(draw, cx, cy, r * 0.18, BRASS)


def five_elements(draw, cx, cy, r):
    colors = [BRASS, MOSS, INK_SOFT, (180, 100, 90), STONE]
    for i, col in enumerate(colors):
        ang = math.radians(i * 72 - 90)
        x = cx + math.cos(ang) * r * 0.55
        y = cy + math.sin(ang) * r * 0.55
        circle(draw, x, y, r * 0.18, col)
    circle(draw, cx, cy, r * 0.12, FOG, BRASS, 3)


def peace_disc(draw, cx, cy, r):
    circle(draw, cx, cy, r, None, INK, 6)
    circle(draw, cx, cy, r * 0.72, None, BRASS, 3)
    circle(draw, cx, cy, r * 0.28, FOG, INK, 4)


def plain_tablet(draw, cx, cy, w, h):
    x0, y0 = cx - w / 2, cy - h / 2
    draw.rounded_rectangle([x0, y0, x0 + w, y0 + h], radius=18, outline=INK, width=4)
    draw.rounded_rectangle([x0 + 18, y0 + 18, x0 + w - 18, y0 + h - 18], radius=10, outline=BRASS, width=2)


def shape_round(draw, cx, cy, r):
    circle(draw, cx, cy, r, None, INK, 5)
    circle(draw, cx, cy, r * 0.55, BRASS_SOFT)


def shape_tablet(draw, cx, cy):
    plain_tablet(draw, cx, cy, 220, 300)


def shape_drop(draw, cx, cy, r):
    # teardrop approx
    pts = []
    for t in range(0, 360, 3):
        a = math.radians(t)
        rr = r * (1.05 - 0.35 * math.cos(a))
        # orient point down
        x = cx + rr * math.sin(a) * 0.75
        y = cy - r * 0.15 + rr * math.cos(a)
        pts.append((x, y))
    draw.polygon(pts, outline=INK)
    # redraw thicker via ellipse+triangle composite
    circle(draw, cx, cy + r * 0.15, r * 0.72, FOG, INK, 5)
    draw.polygon(
        [(cx, cy - r), (cx - r * 0.55, cy + r * 0.1), (cx + r * 0.55, cy + r * 0.1)],
        fill=FOG,
        outline=INK,
    )
    circle(draw, cx, cy + r * 0.2, r * 0.28, BRASS_SOFT)


def shape_point(draw, cx, cy, r):
    draw.polygon(
        [(cx, cy - r), (cx + r * 0.45, cy + r), (cx - r * 0.45, cy + r)],
        outline=INK,
        fill=FOG,
    )
    draw.line([(cx, cy - r), (cx, cy + r * 0.7)], fill=BRASS, width=4)


def shape_carved(draw, cx, cy, r):
    # stylized fish / motif swirl
    circle(draw, cx, cy, r * 0.85, None, INK, 4)
    draw.arc([cx - r, cy - r * 0.6, cx + r * 0.2, cy + r * 0.6], 200, 20, fill=BRASS, width=5)
    draw.arc([cx - r * 0.2, cy - r * 0.6, cx + r, cy + r * 0.6], 20, 200, fill=INK, width=5)
    circle(draw, cx - r * 0.25, cy - r * 0.1, 8, INK)
    circle(draw, cx + r * 0.25, cy + r * 0.1, 8, BRASS)


def material_obsidian(draw, cx, cy, r):
    circle(draw, cx, cy, r, OBSIDIAN)
    circle(draw, cx - r * 0.25, cy - r * 0.25, r * 0.2, (55, 58, 64))
    circle(draw, cx, cy, r, None, BRASS, 3)


def material_jade(draw, cx, cy, r):
    circle(draw, cx, cy, r, JADE)
    circle(draw, cx, cy, r * 0.55, (130, 160, 140))
    circle(draw, cx, cy, r, None, INK, 3)


def material_crystal(draw, cx, cy, r):
    pts = [(cx, cy - r), (cx + r * 0.7, cy), (cx, cy + r), (cx - r * 0.7, cy)]
    draw.polygon(pts, fill=CRYSTAL, outline=INK)
    draw.line([(cx, cy - r), (cx, cy + r)], fill=BRASS, width=2)


def material_other(draw, cx, cy, r):
    for i, col in enumerate([(160, 120, 100), (120, 130, 140), (140, 130, 110)]):
        circle(draw, cx + (i - 1) * r * 0.45, cy, r * 0.4, col, INK, 2)


def material_alloy(draw, cx, cy, r):
    circle(draw, cx, cy, r * 0.7, STONE, BRASS, 6)
    circle(draw, cx, cy, r * 0.25, BRASS)


def intent_balance(draw, cx, cy, r):
    yin_yang(draw, cx, cy, r * 0.85)


def intent_calm(draw, cx, cy, r):
    for i in range(3):
        y = cy - r * 0.4 + i * r * 0.4
        draw.arc([cx - r, y - r * 0.25, cx + r, y + r * 0.25], 200, 340, fill=INK_SOFT if i else INK, width=4)
    circle(draw, cx, cy + r * 0.55, 10, BRASS)


def intent_practice(draw, cx, cy, r):
    # spiral / practice path
    pts = []
    for t in range(0, 540, 4):
        a = math.radians(t)
        rr = r * (0.15 + 0.75 * t / 540)
        pts.append((cx + rr * math.cos(a), cy + rr * math.sin(a)))
    if len(pts) > 1:
        draw.line(pts, fill=INK, width=4)
    circle(draw, pts[-1][0], pts[-1][1], 10, BRASS)


def intent_grounding(draw, cx, cy, r):
    # mountain / grounding
    draw.polygon(
        [(cx - r, cy + r * 0.6), (cx - r * 0.2, cy - r * 0.5), (cx + r * 0.15, cy + r * 0.1), (cx + r, cy + r * 0.6)],
        fill=INK_SOFT,
        outline=INK,
    )
    draw.polygon(
        [(cx - r * 0.5, cy + r * 0.6), (cx, cy - r * 0.85), (cx + r * 0.5, cy + r * 0.6)],
        fill=STONE,
        outline=INK,
    )
    draw.line([(cx - r, cy + r * 0.65), (cx + r, cy + r * 0.65)], fill=BRASS, width=4)


def intent_focus(draw, cx, cy, r):
    for i in range(3):
        circle(draw, cx, cy, r * (1 - i * 0.28), None, INK if i == 0 else INK_SOFT, 3)
    circle(draw, cx, cy, r * 0.18, BRASS)


def group_symbol(draw, cx, cy, r):
    yin_yang(draw, cx - r * 0.35, cy, r * 0.4)
    bagua(draw, cx + r * 0.4, cy, r * 0.45)


def group_shape(draw, cx, cy, r):
    shape_round(draw, cx - r * 0.45, cy, r * 0.35)
    shape_point(draw, cx + r * 0.35, cy, r * 0.45)


def group_material(draw, cx, cy, r):
    material_obsidian(draw, cx - r * 0.4, cy, r * 0.35)
    material_jade(draw, cx + r * 0.4, cy, r * 0.35)


MOTIFS = {
    "intent-balance-harmony": intent_balance,
    "intent-calm-wellness": intent_calm,
    "intent-practice-training": intent_practice,
    "intent-grounding-boundaries": intent_grounding,
    "intent-focus-clarity": intent_focus,
    "by-symbol": group_symbol,
    "symbol-yinyang": lambda d, x, y, r: yin_yang(d, x, y, r),
    "symbol-bagua": lambda d, x, y, r: bagua(d, x, y, r),
    "symbol-five-elements": lambda d, x, y, r: five_elements(d, x, y, r),
    "symbol-peace-disc": lambda d, x, y, r: peace_disc(d, x, y, r),
    "symbol-plain-tablet": lambda d, x, y, r: plain_tablet(d, x, y, r * 1.3, r * 1.7),
    "by-shape": group_shape,
    "shape-round-disc": lambda d, x, y, r: shape_round(d, x, y, r),
    "shape-tablet": lambda d, x, y, r: shape_tablet(d, x, y),
    "shape-drop": lambda d, x, y, r: shape_drop(d, x, y, r),
    "shape-point": lambda d, x, y, r: shape_point(d, x, y, r),
    "shape-carved-motif": lambda d, x, y, r: shape_carved(d, x, y, r),
    "by-material": group_material,
    "material-obsidian": lambda d, x, y, r: material_obsidian(d, x, y, r),
    "material-jade": lambda d, x, y, r: material_jade(d, x, y, r),
    "material-crystal": lambda d, x, y, r: material_crystal(d, x, y, r),
    "material-other-stone": lambda d, x, y, r: material_other(d, x, y, r),
    "material-alloy": lambda d, x, y, r: material_alloy(d, x, y, r),
}


def render_icon(code: str) -> Image.Image:
    img = new_canvas((800, 800))
    # soft wash
    wash = Image.new("RGB", (800, 800), FOG)
    wd = ImageDraw.Draw(wash)
    for i in range(12):
        circle(wd, 400, 400, 380 - i * 12, None, (232, 230, 227), 2)
    img = Image.blend(img, wash, 0.35)
    draw = ImageDraw.Draw(img)
    # thin brass frame
    draw.rounded_rectangle([36, 36, 764, 764], radius=8, outline=BRASS, width=2)
    draw.rounded_rectangle([48, 48, 752, 752], radius=6, outline=(217, 214, 212), width=1)
    motif = MOTIFS[code]
    motif(draw, 400, 400, 240)
    return img.filter(ImageFilter.SMOOTH_MORE)


def render_banner(code: str) -> Image.Image:
    img = new_canvas((1500, 300))
    draw = ImageDraw.Draw(img)
    # left ink wash panel + right motif
    draw.rectangle([0, 0, 1500, 300], fill=FOG)
    draw.rectangle([0, 0, 420, 300], fill=(232, 230, 227))
    draw.line([(420, 24), (420, 276)], fill=BRASS, width=2)
    draw.line([(36, 36), (380, 36)], fill=INK, width=2)
    draw.line([(36, 264), (380, 264)], fill=BRASS, width=2)
    # small motif on left
    motif = MOTIFS[code]
    # draw motif onto a temp square then paste
    tile = new_canvas((300, 300))
    td = ImageDraw.Draw(tile)
    motif(td, 150, 150, 95)
    img.paste(tile, (60, 0))
    # right decorative arcs
    for i in range(4):
        draw.arc([700 + i * 40, 40, 1400, 260], 200, 340, fill=INK_SOFT if i % 2 else BRASS_SOFT, width=2)
    draw.rounded_rectangle([24, 24, 1476, 276], radius=4, outline=(217, 214, 212), width=1)
    return img


def main() -> None:
    ICON_DIR.mkdir(parents=True, exist_ok=True)
    BANNER_DIR.mkdir(parents=True, exist_ok=True)
    for code in MOTIFS:
        icon = render_icon(code)
        banner = render_banner(code)
        save_webp(icon, ICON_DIR / f"{code}.webp")
        save_webp(banner, BANNER_DIR / f"{code}.webp")
        print(f"ok {code} icon={icon.size} banner={banner.size}")
    print(f"wrote {len(MOTIFS)} pairs → {ICON_DIR.parent}")


if __name__ == "__main__":
    main()
