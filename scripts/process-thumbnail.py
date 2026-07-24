import json
import os
import sys

from PIL import Image, ImageOps

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")


def fail(message: str) -> None:
    print(json.dumps({"ok": False, "error": message}, ensure_ascii=False))
    raise SystemExit(1)


if len(sys.argv) != 3:
    fail("Nieprawidłowe argumenty procesora miniatur.")

source_path = os.path.abspath(sys.argv[1])
target_path = os.path.abspath(sys.argv[2])

if not os.path.isfile(source_path):
    fail("Nie znaleziono pliku źródłowego.")

Image.MAX_IMAGE_PIXELS = 40_000_000

try:
    with Image.open(source_path) as image:
        image.verify()
    with Image.open(source_path) as image:
        original_format = (image.format or "").upper()
        image = ImageOps.exif_transpose(image)
        original_width, original_height = image.size
        if original_format not in {"JPEG", "PNG", "WEBP"}:
            fail("Obsługiwane są wyłącznie obrazy JPEG, PNG i WebP.")
        if original_width < 1280 or original_height < 720:
            fail("Oryginał musi mieć co najmniej 1280×720 px.")

        target_ratio = 16 / 9
        current_ratio = original_width / original_height
        if current_ratio > target_ratio:
            crop_width = round(original_height * target_ratio)
            left = max(0, (original_width - crop_width) // 2)
            box = (left, 0, left + crop_width, original_height)
        else:
            crop_height = round(original_width / target_ratio)
            top = max(0, (original_height - crop_height) // 2)
            box = (0, top, original_width, top + crop_height)

        image = image.crop(box).convert("RGB")
        image = image.resize((1280, 720), Image.Resampling.LANCZOS)
        os.makedirs(os.path.dirname(target_path), exist_ok=True)
        image.save(target_path, "WEBP", quality=85, method=6, exif=b"")
except Image.DecompressionBombError:
    fail("Obraz przekracza bezpieczny limit pikseli.")
except Exception as exception:
    fail("Nie można przetworzyć obrazu: " + str(exception))

print(
    json.dumps(
        {
            "ok": True,
            "original_width": original_width,
            "original_height": original_height,
            "public_width": 1280,
            "public_height": 720,
            "original_format": original_format,
        },
        ensure_ascii=False,
    )
)
