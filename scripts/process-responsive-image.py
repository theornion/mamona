import json
import os
import sys

from PIL import Image


def fail(message: str) -> None:
    print(json.dumps({"ok": False, "error": message}, ensure_ascii=False))
    raise SystemExit(1)


if len(sys.argv) != 5:
    fail("Użycie: process-responsive-image.py SOURCE TARGET WIDTH QUALITY")

source_path = os.path.abspath(sys.argv[1])
target_path = os.path.abspath(sys.argv[2])
try:
    target_width = max(1, int(sys.argv[3]))
    quality = max(1, min(100, int(sys.argv[4])))
except ValueError:
    fail("Szerokość i jakość muszą być liczbami.")

if not os.path.isfile(source_path):
    fail("Nie znaleziono obrazu źródłowego.")

temporary_path = target_path + f".tmp-{os.getpid()}"
try:
    with Image.open(source_path) as source:
        source.load()
        if source.width <= target_width:
            fail("Obraz nie wymaga pomniejszania.")
        target_height = max(1, round(source.height * target_width / source.width))
        output = source.resize((target_width, target_height), Image.Resampling.LANCZOS)
        if output.mode not in ("RGB", "RGBA"):
            output = output.convert("RGB")
        output.save(temporary_path, "WEBP", quality=quality, method=6)
    os.replace(temporary_path, target_path)
except (OSError, ValueError) as error:
    if os.path.exists(temporary_path):
        os.unlink(temporary_path)
    fail(str(error))

print(json.dumps({"ok": True, "width": target_width, "height": target_height}))
