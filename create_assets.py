#!/usr/bin/env python3
"""Generate SpamAnvil WordPress.org assets: icon + banner (static & animated)."""

from PIL import Image, ImageDraw, ImageFont
import math
import os

BASE_DIR = "/Users/alexandreamato/Amato Dropbox/Alexandre Amato/Projects/Informatica/Software/llm_anti_spam"
ASSETS_DIR = os.path.join(BASE_DIR, "svn-spamanvil", "assets")
os.makedirs(ASSETS_DIR, exist_ok=True)

# Colors
BG_DARK = (18, 20, 36)
BG_MID = (24, 28, 52)
BG_LIGHT = (32, 36, 68)
WHITE = (255, 255, 255)
LIGHT_GRAY = (180, 185, 200)
MID_GRAY = (120, 125, 140)
STEEL = (170, 180, 200)
STEEL_DARK = (110, 120, 140)
STEEL_LIGHT = (210, 218, 235)
GREEN = (0, 200, 100)
BLUE = (70, 130, 255)
ORANGE = (255, 160, 40)
RED = (220, 60, 70)
CYAN = (0, 190, 210)
PURPLE = (140, 80, 240)


def get_font(size, bold=False):
    font_paths = [
        "/System/Library/Fonts/Helvetica.ttc",
        "/System/Library/Fonts/SFNSDisplay.ttf",
        "/Library/Fonts/Arial Bold.ttf" if bold else "/Library/Fonts/Arial.ttf",
    ]
    for path in font_paths:
        if os.path.exists(path):
            try:
                return ImageFont.truetype(path, size, index=1 if bold and path.endswith('.ttc') else 0)
            except Exception:
                try:
                    return ImageFont.truetype(path, size)
                except Exception:
                    continue
    return ImageFont.load_default()


def draw_gradient(draw, width, height, color_top, color_bottom):
    for y in range(height):
        r = int(color_top[0] + (color_bottom[0] - color_top[0]) * y / height)
        g = int(color_top[1] + (color_bottom[1] - color_top[1]) * y / height)
        b = int(color_top[2] + (color_bottom[2] - color_top[2]) * y / height)
        draw.line([(0, y), (width, y)], fill=(r, g, b))


def draw_radial_glow(img, cx, cy, radius, color, intensity=0.3):
    """Draw a subtle radial glow effect."""
    overlay = Image.new('RGBA', img.size, (0, 0, 0, 0))
    od = ImageDraw.Draw(overlay)
    for r in range(radius, 0, -2):
        alpha = int(intensity * 255 * (1 - r / radius) ** 2)
        alpha = min(alpha, 80)
        od.ellipse([cx - r, cy - r, cx + r, cy + r], fill=(*color, alpha))
    img.paste(Image.alpha_composite(img.convert('RGBA'), overlay).convert('RGB'), (0, 0))


def draw_anvil(draw, cx, cy, size, steel=STEEL, dark=STEEL_DARK, light=STEEL_LIGHT):
    """Draw a detailed stylized anvil."""
    s = size

    # Base (widest part at bottom)
    base_h = s // 4
    draw.rounded_rectangle(
        [cx - s, cy + s // 3, cx + s, cy + s // 3 + base_h],
        radius=4, fill=steel
    )
    # Base highlight
    draw.line(
        [(cx - s + 4, cy + s // 3 + 2), (cx + s - 4, cy + s // 3 + 2)],
        fill=light, width=2
    )
    # Base shadow
    draw.line(
        [(cx - s + 2, cy + s // 3 + base_h - 1), (cx + s - 2, cy + s // 3 + base_h - 1)],
        fill=dark, width=1
    )

    # Waist/neck (narrow middle)
    neck_w = s // 2
    draw.rectangle(
        [cx - neck_w, cy - s // 6, cx + neck_w, cy + s // 3],
        fill=dark
    )
    # Neck side highlights
    draw.line(
        [(cx - neck_w, cy - s // 6), (cx - neck_w, cy + s // 3)],
        fill=(*MID_GRAY,), width=1
    )

    # Face/top (working surface - wide flat top)
    face_w = s * 5 // 4
    face_h = s // 2
    draw.rounded_rectangle(
        [cx - face_w, cy - s * 3 // 4, cx + s, cy - s // 6],
        radius=5, fill=steel
    )
    # Face top highlight (bright edge)
    draw.line(
        [(cx - face_w + 4, cy - s * 3 // 4 + 3), (cx + s - 4, cy - s * 3 // 4 + 3)],
        fill=light, width=3
    )

    # Horn (pointed left extension)
    horn_points = [
        (cx - face_w, cy - s * 3 // 4),
        (cx - face_w - s * 3 // 4, cy - s // 2),
        (cx - face_w, cy - s // 6),
    ]
    draw.polygon(horn_points, fill=steel)
    # Horn highlight
    draw.line(
        [(cx - face_w - s * 3 // 4 + 4, cy - s // 2), (cx - face_w, cy - s * 3 // 4 + 4)],
        fill=light, width=2
    )

    # Heel (right step-down)
    heel_w = s // 3
    draw.rectangle(
        [cx + s - heel_w, cy - s // 6, cx + s, cy + s // 3],
        fill=steel
    )


def draw_spark(draw, cx, cy, size, color=ORANGE):
    """Draw a small spark/star shape."""
    s = size
    # 4-point star
    points = [
        (cx, cy - s),  # top
        (cx + s // 4, cy - s // 4),
        (cx + s, cy),  # right
        (cx + s // 4, cy + s // 4),
        (cx, cy + s),  # bottom
        (cx - s // 4, cy + s // 4),
        (cx - s, cy),  # left
        (cx - s // 4, cy - s // 4),
    ]
    draw.polygon(points, fill=color)


# =============================================================================
# ICON
# =============================================================================
def create_icon(size):
    """Create the plugin icon at given size."""
    img = Image.new('RGB', (size, size), BG_DARK)
    draw = ImageDraw.Draw(img)

    # Gradient background
    draw_gradient(draw, size, size, BG_DARK, BG_MID)

    # Subtle radial glow behind anvil
    draw_radial_glow(img, size // 2, size // 2, size // 2, BLUE, intensity=0.2)
    draw = ImageDraw.Draw(img)

    # Anvil centered
    anvil_size = size // 4
    draw_anvil(draw, size // 2 + anvil_size // 6, size * 9 // 16, anvil_size)

    # Sparks above anvil (impact effect)
    spark_positions = [
        (size // 2 - size // 6, size // 3, size // 16, (*ORANGE,)),
        (size // 2 + size // 5, size // 4, size // 20, (*BLUE,)),
        (size // 2 - size // 12, size // 5, size // 14, (255, 200, 60)),
        (size // 2 + size // 8, size * 2 // 5, size // 22, (*CYAN,)),
    ]
    for sx, sy, ss, sc in spark_positions:
        draw_spark(draw, sx, sy, ss, sc)

    # Subtle border ring
    border_pad = size // 32
    draw.rounded_rectangle(
        [border_pad, border_pad, size - border_pad, size - border_pad],
        radius=size // 8, outline=(*BLUE, 80), width=2
    )

    # "SA" text above anvil (small, subtle)
    font_sa = get_font(size // 5, bold=True)
    text = "SA"
    bbox = draw.textbbox((0, 0), text, font=font_sa)
    tw = bbox[2] - bbox[0]
    draw.text(((size - tw) // 2, size // 10), text, font=font_sa, fill=WHITE)

    return img


# =============================================================================
# STATIC BANNER
# =============================================================================
def create_banner(width, height):
    """Create the static plugin banner."""
    img = Image.new('RGBA', (width, height), (*BG_DARK, 255))
    draw = ImageDraw.Draw(img)

    # Gradient background
    draw_gradient(draw, width, height, BG_DARK, BG_MID)

    # Subtle grid pattern (very faint via alpha compositing)
    grid = Image.new('RGBA', (width, height), (0, 0, 0, 0))
    gd = ImageDraw.Draw(grid)
    grid_color = (255, 255, 255, 10)
    for gx in range(0, width, 60):
        gd.line([(gx, 0), (gx, height)], fill=grid_color, width=1)
    for gy in range(0, height, 60):
        gd.line([(0, gy), (width, gy)], fill=grid_color, width=1)
    img = Image.alpha_composite(img, grid)

    # Radial glow behind anvil area
    glow = Image.new('RGBA', (width, height), (0, 0, 0, 0))
    cx_glow, cy_glow = int(width * 0.17), height // 2
    radius = int(height * 0.9)
    gd2 = ImageDraw.Draw(glow)
    for r in range(radius, 0, -3):
        alpha = int(0.15 * 255 * (1 - r / radius) ** 2)
        alpha = min(alpha, 60)
        gd2.ellipse([cx_glow - r, cy_glow - r, cx_glow + r, cy_glow + r], fill=(*BLUE, alpha))
    img = Image.alpha_composite(img, glow)
    draw = ImageDraw.Draw(img)

    # Scale fonts relative to banner height
    scale = height / 250
    font_title = get_font(int(48 * scale), bold=True)
    font_sub = get_font(int(18 * scale))
    font_feat = get_font(int(14 * scale), bold=True)
    font_small = get_font(int(12 * scale))
    font_badge = get_font(int(13 * scale), bold=True)

    # Anvil on the left
    anvil_size = int(40 * scale)
    anvil_cx = int(width * 0.17)
    anvil_cy = int(height * 0.52)
    draw_anvil(draw, anvil_cx, anvil_cy, anvil_size)

    # Sparks around anvil
    sparks = [
        (anvil_cx - int(30 * scale), anvil_cy - int(50 * scale), int(8 * scale), ORANGE),
        (anvil_cx + int(50 * scale), anvil_cy - int(55 * scale), int(6 * scale), BLUE),
        (anvil_cx - int(10 * scale), anvil_cy - int(65 * scale), int(10 * scale), (255, 200, 60)),
        (anvil_cx + int(30 * scale), anvil_cy - int(40 * scale), int(5 * scale), CYAN),
    ]
    for sx, sy, ss, sc in sparks:
        draw_spark(draw, sx, sy, ss, sc)

    # Title text
    text_x = int(width * 0.32)
    draw.text((text_x, int(30 * scale)), "SpamAnvil", font=font_title, fill=WHITE)

    # Accent line under title
    title_bbox = draw.textbbox((text_x, int(30 * scale)), "SpamAnvil", font=font_title)
    line_y = title_bbox[3] + int(6 * scale)
    draw.line(
        [(text_x, line_y), (text_x + int(280 * scale), line_y)],
        fill=BLUE, width=max(1, int(2 * scale))
    )

    # Subtitle
    draw.text(
        (text_x, line_y + int(10 * scale)),
        "AI-Powered Anti-Spam for WordPress",
        font=font_sub, fill=LIGHT_GRAY
    )

    # Feature pills with proper alpha compositing
    features = [
        ("ChatGPT", BLUE),
        ("Claude", PURPLE),
        ("Gemini", CYAN),
        ("Free Models", GREEN),
    ]
    pill_y = line_y + int(38 * scale)
    pill_x = text_x
    for label, color in features:
        bbox = draw.textbbox((0, 0), label, font=font_feat)
        pw = bbox[2] - bbox[0] + int(16 * scale)
        ph = bbox[3] - bbox[1] + int(10 * scale)

        # Pill background via overlay for proper alpha
        pill_bg = Image.new('RGBA', img.size, (0, 0, 0, 0))
        pd = ImageDraw.Draw(pill_bg)
        pd.rounded_rectangle(
            [pill_x, pill_y, pill_x + pw, pill_y + ph],
            radius=int(4 * scale),
            fill=(*color, 50),
        )
        img = Image.alpha_composite(img, pill_bg)
        draw = ImageDraw.Draw(img)

        # Pill border
        draw.rounded_rectangle(
            [pill_x, pill_y, pill_x + pw, pill_y + ph],
            radius=int(4 * scale),
            outline=color,
            width=1,
        )
        # Pill text (bright, full opacity)
        draw.text(
            (pill_x + int(8 * scale), pill_y + int(4 * scale)),
            label, font=font_feat, fill=WHITE
        )
        pill_x += pw + int(10 * scale)

    # "FREE" badge
    badge_text = "100% FREE"
    badge_bbox = draw.textbbox((0, 0), badge_text, font=font_badge)
    badge_w = badge_bbox[2] - badge_bbox[0] + int(16 * scale)
    badge_h = badge_bbox[3] - badge_bbox[1] + int(10 * scale)
    badge_x = int(width * 0.32)
    badge_y = pill_y + ph + int(14 * scale)

    draw.rounded_rectangle(
        [badge_x, badge_y, badge_x + badge_w, badge_y + badge_h],
        radius=int(3 * scale), fill=GREEN
    )
    draw.text(
        (badge_x + int(8 * scale), badge_y + int(4 * scale)),
        badge_text, font=font_badge, fill=BG_DARK
    )

    # "No subscription" text next to badge
    draw.text(
        (badge_x + badge_w + int(10 * scale), badge_y + int(4 * scale)),
        "No subscription. Bring your own API key.",
        font=font_small, fill=MID_GRAY
    )

    # Decorative dots in bottom-right
    for i in range(5):
        dx = width - int(40 * scale) - i * int(25 * scale)
        dy = height - int(20 * scale)
        r = int(3 * scale)
        c = [BLUE, PURPLE, CYAN, GREEN, ORANGE][i]
        draw.ellipse([dx - r, dy - r, dx + r, dy + r], fill=c)

    return img.convert('RGB')


# =============================================================================
# ANIMATED BANNER (GIF)
# =============================================================================
def create_animated_banner(width, height):
    """Create animated GIF banner."""
    FPS = 12
    DURATION = 6  # seconds
    TOTAL_FRAMES = FPS * DURATION
    frames = []

    scale = height / 250

    font_title = get_font(int(48 * scale), bold=True)
    font_sub = get_font(int(18 * scale))
    font_feat = get_font(int(14 * scale), bold=True)
    font_small = get_font(int(12 * scale))
    font_badge = get_font(int(13 * scale), bold=True)
    font_big = get_font(int(56 * scale), bold=True)

    def ease_out(t):
        return 1 - (1 - min(1, max(0, t))) ** 3

    def ease_in_out(t):
        t = min(1, max(0, t))
        return 4 * t * t * t if t < 0.5 else 1 - pow(-2 * t + 2, 3) / 2

    # Very subtle grid color (barely visible against dark bg)
    GRID_COLOR = (26, 28, 46)

    def draw_base(draw):
        draw_gradient(draw, width, height, BG_DARK, BG_MID)
        for gx in range(0, width, 60):
            draw.line([(gx, 0), (gx, height)], fill=GRID_COLOR, width=1)
        for gy in range(0, height, 60):
            draw.line([(0, gy), (width, gy)], fill=GRID_COLOR, width=1)

    for f in range(TOTAL_FRAMES):
        img = Image.new('RGB', (width, height), BG_DARK)
        draw = ImageDraw.Draw(img)
        draw_base(draw)

        t = f / TOTAL_FRAMES
        sec = f / FPS

        anvil_cx = int(width * 0.17)
        anvil_cy = int(height * 0.52)
        anvil_size = int(40 * scale)
        text_x = int(width * 0.32)

        # Scene 1: Anvil drops + title appears (0-2s)
        if sec < 2.0:
            # Anvil drops from above
            drop_t = ease_out(sec / 0.6)
            anvil_offset_y = int((1 - drop_t) * -height * 0.6)

            draw_radial_glow(img, anvil_cx, anvil_cy, int(height * 0.9), BLUE, intensity=0.15 * drop_t)
            draw = ImageDraw.Draw(img)

            draw_anvil(draw, anvil_cx, anvil_cy + anvil_offset_y, anvil_size)

            # Impact sparks after landing
            if sec > 0.5:
                spark_t = (sec - 0.5) / 0.5
                if spark_t < 1.0:
                    spark_spread = ease_out(spark_t)
                    spark_alpha = 1 - spark_t
                    spark_data = [
                        (-30, -50, 8, ORANGE),
                        (50, -55, 6, BLUE),
                        (-10, -65, 10, (255, 200, 60)),
                        (30, -40, 5, CYAN),
                    ]
                    for dx, dy, ss, sc in spark_data:
                        sx = anvil_cx + int(dx * scale * (1 + spark_spread * 0.5))
                        sy = anvil_cy + int(dy * scale * (1 + spark_spread * 0.3))
                        if spark_alpha > 0.3:
                            draw_spark(draw, sx, sy, int(ss * scale * (1 + spark_spread * 0.3)), sc)

                # Keep sparks visible (static) after animation
                if spark_t >= 1.0 or sec > 1.0:
                    for dx, dy, ss, sc in [(-30, -50, 8, ORANGE), (50, -55, 6, BLUE),
                                            (-10, -65, 10, (255, 200, 60)), (30, -40, 5, CYAN)]:
                        draw_spark(draw, anvil_cx + int(dx * scale), anvil_cy + int(dy * scale),
                                   int(ss * scale), sc)

            # Title slides in from right
            title_t = ease_out((sec - 0.3) / 0.6) if sec > 0.3 else 0
            title_offset = int((1 - title_t) * width * 0.3)
            draw.text((text_x + title_offset, int(30 * scale)), "SpamAnvil", font=font_title, fill=WHITE)

            # Accent line
            if sec > 0.6:
                line_t = ease_out((sec - 0.6) / 0.4)
                title_bbox = draw.textbbox((text_x, int(30 * scale)), "SpamAnvil", font=font_title)
                line_y = title_bbox[3] + int(6 * scale)
                line_w = int(280 * scale * line_t)
                draw.line([(text_x, line_y), (text_x + line_w, line_y)], fill=BLUE, width=int(2 * scale))

            # Subtitle
            if sec > 0.9:
                sub_t = ease_out((sec - 0.9) / 0.4)
                title_bbox = draw.textbbox((text_x, int(30 * scale)), "SpamAnvil", font=font_title)
                line_y = title_bbox[3] + int(6 * scale)
                sub_alpha = int(255 * sub_t)
                draw.text(
                    (text_x, line_y + int(10 * scale)),
                    "AI-Powered Anti-Spam for WordPress",
                    font=font_sub, fill=(*LIGHT_GRAY[:3],)
                )

        # Scene 2: Features + badge (2-4.5s)
        elif sec < 4.5:
            local = sec - 2.0

            # Static elements
            draw_radial_glow(img, anvil_cx, anvil_cy, int(height * 0.9), BLUE, intensity=0.15)
            draw = ImageDraw.Draw(img)
            draw_anvil(draw, anvil_cx, anvil_cy, anvil_size)
            for dx, dy, ss, sc in [(-30, -50, 8, ORANGE), (50, -55, 6, BLUE),
                                    (-10, -65, 10, (255, 200, 60)), (30, -40, 5, CYAN)]:
                draw_spark(draw, anvil_cx + int(dx * scale), anvil_cy + int(dy * scale),
                           int(ss * scale), sc)

            draw.text((text_x, int(30 * scale)), "SpamAnvil", font=font_title, fill=WHITE)
            title_bbox = draw.textbbox((text_x, int(30 * scale)), "SpamAnvil", font=font_title)
            line_y = title_bbox[3] + int(6 * scale)
            draw.line([(text_x, line_y), (text_x + int(280 * scale), line_y)], fill=BLUE, width=int(2 * scale))
            draw.text((text_x, line_y + int(10 * scale)), "AI-Powered Anti-Spam for WordPress",
                       font=font_sub, fill=LIGHT_GRAY)

            # Feature pills appear one by one
            features = [
                ("ChatGPT", BLUE, 0.0),
                ("Claude", PURPLE, 0.3),
                ("Gemini", CYAN, 0.6),
                ("Free Models", GREEN, 0.9),
            ]
            pill_y = line_y + int(38 * scale)
            pill_x = text_x
            for label, color, delay in features:
                bbox = draw.textbbox((0, 0), label, font=font_feat)
                pw = bbox[2] - bbox[0] + int(16 * scale)
                ph = bbox[3] - bbox[1] + int(10 * scale)

                if local > delay:
                    pill_t = ease_out((local - delay) / 0.3)
                    pill_offset = int((1 - pill_t) * 20 * scale)
                    # Dark fill blended with color (no alpha on RGB)
                    pill_fill = (color[0] // 5, color[1] // 5, color[2] // 5)
                    draw.rounded_rectangle(
                        [pill_x, pill_y + pill_offset, pill_x + pw, pill_y + ph + pill_offset],
                        radius=int(4 * scale), fill=pill_fill, outline=color, width=1
                    )
                    draw.text((pill_x + int(8 * scale), pill_y + int(4 * scale) + pill_offset),
                              label, font=font_feat, fill=WHITE)
                pill_x += pw + int(10 * scale)

            # "100% FREE" badge
            if local > 1.3:
                badge_t = ease_out((local - 1.3) / 0.3)
                badge_text = "100% FREE"
                badge_bbox = draw.textbbox((0, 0), badge_text, font=font_badge)
                badge_w = badge_bbox[2] - badge_bbox[0] + int(16 * scale)
                badge_h = badge_bbox[3] - badge_bbox[1] + int(8 * scale)
                badge_x = int(width * 0.32)
                badge_y = pill_y + ph + int(14 * scale)
                badge_scale = 0.5 + 0.5 * badge_t

                draw.rounded_rectangle(
                    [badge_x, badge_y, badge_x + badge_w, badge_y + badge_h],
                    radius=int(3 * scale), fill=GREEN
                )
                draw.text((badge_x + int(8 * scale), badge_y + int(3 * scale)),
                           badge_text, font=font_badge, fill=BG_DARK)

                draw.text(
                    (badge_x + badge_w + int(10 * scale), badge_y + int(3 * scale)),
                    "No subscription. Bring your own API key.",
                    font=font_small, fill=MID_GRAY
                )

        # Scene 3: CTA (4.5-6s)
        else:
            local = sec - 4.5

            draw_radial_glow(img, width // 2, height // 2, int(height * 0.8), BLUE, intensity=0.2)
            draw = ImageDraw.Draw(img)

            # Big centered text
            cta_t = ease_out(local / 0.5)
            text = "SpamAnvil"
            bbox = draw.textbbox((0, 0), text, font=font_big)
            tw = bbox[2] - bbox[0]
            draw.text(((width - tw) // 2, int(40 * scale)), text, font=font_big, fill=WHITE)

            if local > 0.3:
                sub = "Free AI Anti-Spam for WordPress"
                bbox2 = draw.textbbox((0, 0), sub, font=font_sub)
                tw2 = bbox2[2] - bbox2[0]
                draw.text(((width - tw2) // 2, int(110 * scale)), sub, font=font_sub, fill=LIGHT_GRAY)

            if local > 0.6:
                url = "wordpress.org/plugins/spamanvil"
                bbox3 = draw.textbbox((0, 0), url, font=font_feat)
                tw3 = bbox3[2] - bbox3[0]
                ux = (width - tw3) // 2
                uy = int(150 * scale)

                # Button-like background
                pad = int(10 * scale)
                draw.rounded_rectangle(
                    [ux - pad * 2, uy - pad, ux + tw3 + pad * 2, uy + (bbox3[3] - bbox3[1]) + pad],
                    radius=int(6 * scale), fill=BLUE
                )
                draw.text((ux, uy), url, font=font_feat, fill=WHITE)

            # Decorative dots
            if local > 0.5:
                for i in range(5):
                    dx = width // 2 - int(100 * scale) + i * int(50 * scale)
                    dy = int(200 * scale)
                    r = int(3 * scale)
                    c = [BLUE, PURPLE, CYAN, GREEN, ORANGE][i]
                    draw.ellipse([dx - r, dy - r, dx + r, dy + r], fill=c)

        frames.append(img)

        if f % 12 == 0:
            print(f"  Frame {f}/{TOTAL_FRAMES}")

    return frames, int(1000 / FPS)


# =============================================================================
# MAIN
# =============================================================================
if __name__ == '__main__':
    print("=== SpamAnvil Asset Generator ===\n")

    # Icon 256x256
    print("Creating icon-256x256.png...")
    icon_256 = create_icon(256)
    icon_256.save(os.path.join(ASSETS_DIR, "icon-256x256.png"), "PNG")

    # Icon 128x128
    print("Creating icon-128x128.png...")
    icon_128 = icon_256.resize((128, 128), Image.LANCZOS)
    icon_128.save(os.path.join(ASSETS_DIR, "icon-128x128.png"), "PNG")

    # Static banner 772x250
    print("Creating banner-772x250.png...")
    banner = create_banner(772, 250)
    banner.save(os.path.join(ASSETS_DIR, "banner-772x250.png"), "PNG")

    # Static banner 1544x500 (retina)
    print("Creating banner-1544x500.png...")
    banner_2x = create_banner(1544, 500)
    banner_2x.save(os.path.join(ASSETS_DIR, "banner-1544x500.png"), "PNG")

    # Animated banner GIF 772x250
    print("Creating banner-772x250.gif (animated)...")
    frames, duration = create_animated_banner(772, 250)
    frames[0].save(
        os.path.join(ASSETS_DIR, "banner-772x250.gif"),
        save_all=True,
        append_images=frames[1:],
        duration=duration,
        loop=0,
        optimize=True,
    )

    print("\n=== Assets generated in", ASSETS_DIR, "===")
    for f in sorted(os.listdir(ASSETS_DIR)):
        fpath = os.path.join(ASSETS_DIR, f)
        if os.path.isfile(fpath):
            size_kb = os.path.getsize(fpath) / 1024
            print(f"  {f}: {size_kb:.0f} KB")
