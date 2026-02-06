#!/usr/bin/env python3
"""Generate SpamAnvil promotional GIF."""

from PIL import Image, ImageDraw, ImageFont
import math
import os

# Config
WIDTH, HEIGHT = 800, 400
FPS = 12
TOTAL_SECONDS = 8
TOTAL_FRAMES = FPS * TOTAL_SECONDS

# Colors
BG_DARK = (18, 18, 40)
BG_GRADIENT_END = (30, 30, 70)
WHITE = (255, 255, 255)
LIGHT_GRAY = (180, 180, 200)
GREEN = (0, 210, 100)
BLUE_ACCENT = (70, 130, 255)
ORANGE = (255, 160, 40)
RED = (220, 50, 60)
CYAN = (0, 200, 220)
PURPLE = (160, 80, 255)

# Try system fonts
def get_font(size, bold=False):
    font_paths = [
        "/System/Library/Fonts/Helvetica.ttc",
        "/System/Library/Fonts/SFNSDisplay.ttf",
        "/Library/Fonts/Arial Bold.ttf" if bold else "/Library/Fonts/Arial.ttf",
        "/System/Library/Fonts/SFCompact.ttf",
    ]
    for path in font_paths:
        if os.path.exists(path):
            try:
                return ImageFont.truetype(path, size, index=1 if bold and path.endswith('.ttc') else 0)
            except:
                try:
                    return ImageFont.truetype(path, size)
                except:
                    continue
    return ImageFont.load_default()

font_title = get_font(52, bold=True)
font_subtitle = get_font(22)
font_feature = get_font(26, bold=True)
font_feature_desc = get_font(18)
font_big = get_font(64, bold=True)
font_medium = get_font(28, bold=True)
font_small = get_font(16)
font_cta = get_font(30, bold=True)

def draw_gradient_bg(draw):
    for y in range(HEIGHT):
        r = int(BG_DARK[0] + (BG_GRADIENT_END[0] - BG_DARK[0]) * y / HEIGHT)
        g = int(BG_DARK[1] + (BG_GRADIENT_END[1] - BG_DARK[1]) * y / HEIGHT)
        b = int(BG_DARK[2] + (BG_GRADIENT_END[2] - BG_DARK[2]) * y / HEIGHT)
        draw.line([(0, y), (WIDTH, y)], fill=(r, g, b))

def ease_out(t):
    return 1 - (1 - t) ** 3

def ease_in_out(t):
    if t < 0.5:
        return 4 * t * t * t
    else:
        return 1 - pow(-2 * t + 2, 3) / 2

def draw_text_centered(draw, y, text, font, fill, alpha=255):
    bbox = draw.textbbox((0, 0), text, font=font)
    tw = bbox[2] - bbox[0]
    x = (WIDTH - tw) // 2
    if alpha < 255:
        fill = (*fill[:3], alpha) if len(fill) == 4 else (*fill, alpha)
    draw.text((x, y), text, font=font, fill=fill)

def draw_anvil_icon(draw, cx, cy, size, alpha=255):
    """Draw a stylized anvil shape."""
    s = size
    body_color = (180, 190, 210)
    dark_color = (120, 130, 150)
    highlight = (220, 225, 240)
    # Top flat surface (wider)
    draw.rounded_rectangle([cx - s*5//4, cy - s*3//4, cx + s, cy - s//3], radius=3, fill=body_color)
    # Horn (left pointed)
    draw.polygon([
        (cx - s*5//4, cy - s*3//4),
        (cx - s*2, cy - s//2),
        (cx - s*5//4, cy - s//3),
    ], fill=body_color)
    # Neck
    draw.rectangle([cx - s//2, cy - s//3, cx + s//2, cy + s//6], fill=dark_color)
    # Base (wide)
    draw.rounded_rectangle([cx - s, cy + s//6, cx + s, cy + s//2], radius=4, fill=body_color)
    # Highlight line on top
    draw.line([(cx - s, cy - s*3//4 + 2), (cx + s - 4, cy - s*3//4 + 2)], fill=highlight, width=2)

def draw_shield_icon(draw, cx, cy, size, color=GREEN):
    s = size
    points = [
        (cx, cy - s),
        (cx + s, cy - s//2),
        (cx + s, cy + s//3),
        (cx, cy + s),
        (cx - s, cy + s//3),
        (cx - s, cy - s//2),
    ]
    draw.polygon(points, fill=color)
    # Checkmark
    draw.line([(cx - s//3, cy), (cx - s//8, cy + s//3), (cx + s//3, cy - s//3)],
              fill=WHITE, width=2)

def draw_particle(draw, frame, x_base, y_base, speed=1):
    """Draw a floating particle."""
    t = (frame * speed) % TOTAL_FRAMES
    y = y_base - (t / TOTAL_FRAMES) * HEIGHT * 0.3
    x = x_base + math.sin(t / 10) * 20
    alpha = int(255 * (1 - t / TOTAL_FRAMES))
    size = max(1, 3 - int(t / TOTAL_FRAMES * 3))
    draw.ellipse([x-size, y-size, x+size, y+size], fill=(*BLUE_ACCENT, min(alpha, 150)))


def generate_frame(frame_num):
    img = Image.new('RGBA', (WIDTH, HEIGHT), BG_DARK)
    draw = ImageDraw.Draw(img)
    draw_gradient_bg(draw)

    t = frame_num / TOTAL_FRAMES  # 0.0 to 1.0 through the animation
    frame_sec = frame_num / FPS

    # Floating particles in background
    for i in range(8):
        px = 100 + i * 90
        py = HEIGHT - 20
        draw_particle(draw, frame_num + i * 10, px, py, speed=0.7 + i * 0.1)

    # Subtle grid lines (very faint)
    for gx in range(0, WIDTH, 80):
        draw.line([(gx, 0), (gx, HEIGHT)], fill=(255, 255, 255, 5), width=1)
    for gy in range(0, HEIGHT, 80):
        draw.line([(0, gy), (WIDTH, gy)], fill=(255, 255, 255, 5), width=1)

    # === SCENE 1: Title (0-2.5s) ===
    if frame_sec < 2.5:
        progress = min(1.0, frame_sec / 0.8)
        ep = ease_out(progress)

        # Anvil icon - to the left of title
        anvil_scale = int(22 * ep)
        if anvil_scale > 5:
            draw_anvil_icon(draw, WIDTH // 2 - 180, 120, anvil_scale)

        # Title
        scale_offset = int(15 * (1 - ep))
        draw_text_centered(draw, 85 + scale_offset, "SpamAnvil", font_title, WHITE)

        # Subtitle appears after title
        if frame_sec > 0.5:
            draw_text_centered(draw, 150, "AI-Powered Anti-Spam for WordPress",
                             font_subtitle, LIGHT_GRAY)

        # "Free & Open Source" badge
        if frame_sec > 1.0:
            badge_progress = min(1.0, (frame_sec - 1.0) / 0.5)
            badge_ep = ease_out(badge_progress)
            badge_y = 200
            badge_text = "FREE & OPEN SOURCE"
            bbox = draw.textbbox((0, 0), badge_text, font=font_feature_desc)
            bw = bbox[2] - bbox[0]
            bx = (WIDTH - bw) // 2 - 12
            draw.rounded_rectangle(
                [bx - 4, badge_y - 4, bx + bw + 16, badge_y + 26],
                radius=4, fill=(*GREEN, int(200 * badge_ep))
            )
            draw.text((bx + 6, badge_y), badge_text, font=font_feature_desc,
                     fill=(*BG_DARK,))

        # Tagline
        if frame_sec > 1.4:
            draw_text_centered(draw, 250, "Stop spam with ChatGPT, Claude, Gemini & more",
                             font_feature_desc, LIGHT_GRAY)
            draw_text_centered(draw, 278, "No subscription needed. Works with free AI models.",
                             font_small, LIGHT_GRAY)

        # Decorative line
        if frame_sec > 0.3:
            line_progress = min(1.0, (frame_sec - 0.3) / 0.8)
            line_w = int(300 * ease_out(line_progress))
            cx = WIDTH // 2
            draw.line([(cx - line_w, 145), (cx + line_w, 145)], fill=(*BLUE_ACCENT, 60), width=1)

    # === SCENE 2: Features (2.5-5.5s) ===
    elif frame_sec < 5.5:
        local_t = frame_sec - 2.5  # 0 to 3

        features = [
            ("AI Spam Detection", "LLM scores each comment 0-100", BLUE_ACCENT),
            ("6+ AI Providers", "OpenAI, Claude, Gemini, free models", PURPLE),
            ("Smart IP Blocking", "Auto-bans repeat offenders", ORANGE),
            ("Async Processing", "Background queue, zero latency", CYAN),
        ]

        # Title
        draw_text_centered(draw, 30, "SpamAnvil", font_medium, (*WHITE[:3],))
        draw.line([(200, 65), (600, 65)], fill=(*BLUE_ACCENT, 100), width=1)

        for i, (title, desc, color) in enumerate(features):
            feat_start = i * 0.6
            if local_t < feat_start:
                continue
            feat_progress = min(1.0, (local_t - feat_start) / 0.5)
            ep = ease_out(feat_progress)

            y = 90 + i * 72
            x_offset = int(30 * (1 - ep))

            # Feature indicator dot
            dot_x = 160 + x_offset
            draw.ellipse([dot_x, y + 8, dot_x + 16, y + 24], fill=color)

            # Feature text
            draw.text((190 + x_offset, y + 4), title, font=font_feature, fill=WHITE)
            draw.text((190 + x_offset, y + 34), desc, font=font_small, fill=LIGHT_GRAY)

            # Score bar for visual flair
            bar_x = 590
            bar_width = int(120 * ep)
            draw.rounded_rectangle(
                [bar_x, y + 10, bar_x + bar_width, y + 24],
                radius=3, fill=(*color, 150)
            )

    # === SCENE 3: Value Prop + CTA (5.5-8s) ===
    else:
        local_t = frame_sec - 5.5  # 0 to 2.5

        # "100% FREE" big text
        free_progress = min(1.0, local_t / 0.6)
        ep = ease_out(free_progress)
        scale = 0.5 + 0.5 * ep

        draw_text_centered(draw, 60, "100% FREE", font_big, GREEN)

        if local_t > 0.4:
            draw_text_centered(draw, 140, "No subscription. No premium tier.", font_subtitle, WHITE)

        if local_t > 0.8:
            draw_text_centered(draw, 175, "Bring your own AI key (free options available)",
                             font_feature_desc, LIGHT_GRAY)

        # CTA button
        if local_t > 1.2:
            cta_progress = min(1.0, (local_t - 1.2) / 0.5)
            cta_ep = ease_out(cta_progress)

            # Pulsing effect
            pulse = 1 + 0.03 * math.sin(frame_num * 0.5)

            cta_text = "Download on WordPress.org"
            bbox = draw.textbbox((0, 0), cta_text, font=font_cta)
            cta_w = bbox[2] - bbox[0]
            cta_h = bbox[3] - bbox[1]
            cta_x = (WIDTH - cta_w) // 2
            cta_y = 250

            pad_x, pad_y = 30, 14
            btn_rect = [
                cta_x - pad_x, cta_y - pad_y,
                cta_x + cta_w + pad_x, cta_y + cta_h + pad_y + 4
            ]

            # Button glow
            for g in range(4, 0, -1):
                glow_rect = [btn_rect[0]-g*2, btn_rect[1]-g*2, btn_rect[2]+g*2, btn_rect[3]+g*2]
                draw.rounded_rectangle(glow_rect, radius=10+g*2,
                                     fill=(*BLUE_ACCENT, int(20 * cta_ep)))

            draw.rounded_rectangle(btn_rect, radius=10, fill=BLUE_ACCENT)
            draw.text((cta_x, cta_y), cta_text, font=font_cta, fill=WHITE)

        # Bottom tagline
        if local_t > 1.6:
            draw_text_centered(draw, 340, "Works with OpenAI  |  Claude  |  Gemini  |  Free Models",
                             font_small, LIGHT_GRAY)
            draw_text_centered(draw, 365, "software.amato.com.br/spamanvil",
                             font_small, (*BLUE_ACCENT,))

    return img.convert('RGB')


# Generate all frames
print("Generating frames...")
frames = []
for i in range(TOTAL_FRAMES):
    frames.append(generate_frame(i))
    if i % 10 == 0:
        print(f"  Frame {i}/{TOTAL_FRAMES}")

# Save as GIF
output_path = "/Users/alexandreamato/Amato Dropbox/Alexandre Amato/Projects/Informatica/Software/llm_anti_spam/spamanvil-promo.gif"
print(f"Saving GIF to {output_path}...")

frames[0].save(
    output_path,
    save_all=True,
    append_images=frames[1:],
    duration=int(1000 / FPS),
    loop=0,
    optimize=True,
)

file_size = os.path.getsize(output_path)
print(f"Done! File size: {file_size / 1024:.0f} KB")
print(f"Dimensions: {WIDTH}x{HEIGHT}, {TOTAL_FRAMES} frames, {FPS} FPS, {TOTAL_SECONDS}s")
