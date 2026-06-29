"""Build-time PaddleOCR self-test.

Runs at `docker build` on the SAME box that serves runtime, so it's representative. It
(1) instantiates the pipeline — which downloads + bakes the PP-OCRv6 models into the image —
and (2) asserts paddle can actually read text. A wheel that imports and runs but produces
NO detections (a real risk when the CPU/Python-ABI combo differs from what was validated)
would otherwise ship a silently-empty engine; here it fails the build loudly instead.
"""

from __future__ import annotations

from PIL import Image, ImageDraw, ImageFont
from paddleocr import PaddleOCR

img = Image.new("RGB", (480, 120), "white")
draw = ImageDraw.Draw(img)
try:
    font = ImageFont.load_default(size=48)  # Pillow >= 10
except TypeError:  # pragma: no cover - very old Pillow
    font = ImageFont.load_default()
draw.text((20, 30), "Upgrade Plan", fill="black", font=font)
img.save("/tmp/ocr_selftest.png")

ocr = PaddleOCR(
    lang="en",
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    use_textline_orientation=False,
)
result = ocr.predict("/tmp/ocr_selftest.png")

# rec_texts comes back as a numpy array — guard with an explicit None check, never `or []`
# (truthiness of a multi-element array raises).
rec_texts = result[0].get("rec_texts") if result else None
texts = list(rec_texts) if rec_texts is not None else []
joined = " ".join(t for t in texts if t).strip()

assert joined, f"PaddleOCR self-test produced NO text (rec_texts={texts!r}) — broken wheel/CPU/ABI?"
print(f"PaddleOCR self-test OK: {joined!r}")
