"""
crunch OCR sidecar — dedicated document/UI-text OCR.

Florence-2 (the vision sidecar) is a generative VLM: strong at caption/detect, but it
*hallucinates* plausible-but-wrong tokens on dense UI text — exactly the `roll` workload
(reading the button/label under a click), where a clean UI crop OCR'd to "3ArchivedWifoy".
This sidecar runs purpose-built OCR engines instead:

  - tesseract : classic, tiny (~70MB RAM), fast (~0.2s), layout-preserving. The DEFAULT.
  - paddle    : PaddleOCR PP-OCRv6, best accuracy on small/low-contrast glyphs, but heavy
                (~1GB RAM resident once loaded) and slower on dense crops — so it's LAZY:
                the model only loads into memory on the first `engine=paddle` request.

Kept OUT of the vision sidecar deliberately: that one is a single synchronous worker behind
a tight PHP timeout, so a 5–10s Paddle call there would worsen the overload bug. Separate
service = isolated RAM, independent scaling, no contention with caption/detect.

Contract:
    POST /ocr (multipart image, form: engine?, psm?) ->
      { engine, text, model, infer_secs }
    POST /ocr/words (multipart image, form: psm?, min_conf?) ->   # tesseract, line-level
      { engine, lines: [{ text, conf, box:[x,y,w,h] }], text, image_height, model, infer_secs }
"""

from __future__ import annotations

import io
import os
import tempfile
import time

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from PIL import Image

DEFAULT_ENGINE = os.environ.get("OCR_DEFAULT_ENGINE", "tesseract")
DEFAULT_PSM = int(os.environ.get("OCR_DEFAULT_PSM", "6"))  # 6 = uniform block; 7 = single line
TESS_LANG = os.environ.get("OCR_TESS_LANG", "eng")
PADDLE_LANG = os.environ.get("OCR_PADDLE_LANG", "en")

app = FastAPI(title="crunch-ocr", version="1.0.0")
_paddle = None  # lazy — see get_paddle()


def get_paddle():
    """Build the PaddleOCR pipeline on first use (it costs ~1GB RAM, so we don't pay it
    until someone actually asks for engine=paddle). The doc-orientation / unwarping /
    textline-orientation sub-models are off: crunch feeds it already-upright UI crops, so
    they'd only add latency and memory.

    On first build we self-verify against a synthetic text image: if paddle can't read its
    own test image (e.g. the model cache didn't resolve at runtime), we raise instead of
    quietly returning empty text on every request — a loud 5xx beats a silent dead engine."""
    global _paddle
    if _paddle is None:
        from paddleocr import PaddleOCR

        pipeline = PaddleOCR(
            lang=PADDLE_LANG,
            use_doc_orientation_classify=False,
            use_doc_unwarping=False,
            use_textline_orientation=False,
        )
        _assert_paddle_healthy(pipeline)
        _paddle = pipeline
    return _paddle


def _assert_paddle_healthy(pipeline) -> None:
    """Render a known word and confirm paddle reads it — guards against a model cache that
    didn't resolve at runtime (silently-empty inference)."""
    from PIL import Image, ImageDraw, ImageFont

    img = Image.new("RGB", (480, 120), "white")
    draw = ImageDraw.Draw(img)
    try:
        font = ImageFont.load_default(size=48)
    except TypeError:  # pragma: no cover - very old Pillow
        font = ImageFont.load_default()
    draw.text((20, 30), "Upgrade Plan", fill="black", font=font)
    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
        img.save(tmp.name)
        probe = tmp.name
    try:
        result = pipeline.predict(probe)
        rec = result[0].get("rec_texts") if result else None
        texts = list(rec) if rec is not None else []
        if not any(t for t in texts):
            raise RuntimeError(
                "PaddleOCR loaded but read no text from its self-test image — the model "
                "cache likely didn't resolve at runtime (check HOME / ~/.paddlex)."
            )
    finally:
        os.unlink(probe)


@app.get("/health")
def health() -> dict:
    return {"ok": True, "default_engine": DEFAULT_ENGINE, "paddle_loaded": _paddle is not None}


def _tesseract(path: str, psm: int) -> str:
    import pytesseract

    return pytesseract.image_to_string(Image.open(path), lang=TESS_LANG, config=f"--oem 3 --psm {psm}").strip()


def _tesseract_lines(path: str, psm: int, min_conf: float) -> list[dict]:
    """Line-level OCR: tesseract's own line grouping (image_to_data) joined into clean
    reading-order text, each with a pixel bounding box.

    Why lines, not words: on a lossless frame, joining every word in a tesseract line reproduces
    `image_to_string` quality — the LSTM language model resolves the line as a whole ("autonomously
    in USDC via x402 ..."). Splitting on a per-word confidence floor (the old `/ocr/words` path) is
    what garbled dense UI text and inflated the downstream span index. We still drop words below a
    light floor (stray glyphs / pure noise) but never break a line on confidence.

    Boxes are in image-pixel space — and roll emits click/cursor coords in that SAME space — so a
    click resolves to the exact line under it downstream."""
    import pytesseract

    data = pytesseract.image_to_data(
        Image.open(path), lang=TESS_LANG, config=f"--oem 3 --psm {psm}",
        output_type=pytesseract.Output.DICT,
    )

    groups: dict[tuple, list[int]] = {}
    for i in range(len(data["text"])):
        text = (data["text"][i] or "").strip()
        try:
            conf = float(data["conf"][i])
        except (TypeError, ValueError):
            conf = -1.0
        if not text or conf < min_conf:
            continue
        groups.setdefault((data["block_num"][i], data["par_num"][i], data["line_num"][i]), []).append(i)

    lines: list[dict] = []
    for idxs in groups.values():
        idxs.sort(key=lambda i: data["left"][i])
        text = " ".join((data["text"][i] or "").strip() for i in idxs).strip()
        if not text:
            continue
        x0 = min(data["left"][i] for i in idxs)
        y0 = min(data["top"][i] for i in idxs)
        x1 = max(data["left"][i] + data["width"][i] for i in idxs)
        y1 = max(data["top"][i] + data["height"][i] for i in idxs)
        conf = sum(float(data["conf"][i]) for i in idxs) / len(idxs)
        lines.append({
            "text": text,
            "conf": round(conf, 1),
            "box": [int(x0), int(y0), int(x1 - x0), int(y1 - y0)],
        })

    lines.sort(key=lambda ln: (ln["box"][1], ln["box"][0]))
    return lines


def _paddle_ocr(path: str) -> str:
    """Run PaddleOCR and reassemble the detected lines into reading order. Paddle returns
    boxes in detection order, which scrambles multi-column text — sort top-to-bottom,
    then left-to-right by each line's box so the output reads naturally."""
    result = get_paddle().predict(path)
    if not result:
        return ""

    res = result[0]
    # rec_texts / rec_boxes come back as numpy arrays — guard with explicit None checks,
    # never `or []` (truthiness of a multi-element array is ambiguous and raises).
    rec_texts = res.get("rec_texts")
    rec_boxes = res.get("rec_boxes")
    texts = list(rec_texts) if rec_texts is not None else []
    boxes = list(rec_boxes) if rec_boxes is not None else []

    if texts and len(boxes) == len(texts):
        # rec_boxes rows are [x1, y1, x2, y2]; order by top edge then left edge.
        order = sorted(range(len(texts)), key=lambda i: (float(boxes[i][1]), float(boxes[i][0])))
        texts = [texts[i] for i in order]

    return "\n".join(t for t in texts if t).strip()


@app.post("/ocr")
async def ocr(
    image: UploadFile = File(...),
    engine: str | None = Form(None),
    psm: int | None = Form(None),
) -> dict:
    engine = (engine or DEFAULT_ENGINE).lower()
    if engine not in ("tesseract", "paddle"):
        raise HTTPException(status_code=400, detail=f"unknown engine '{engine}' (expected tesseract or paddle)")

    raw = await image.read()
    try:
        # Validate it's a real image up front so a bad upload is a clean 422, not a 500.
        Image.open(io.BytesIO(raw)).verify()
    except Exception as exc:
        raise HTTPException(status_code=422, detail=f"not a decodable image: {exc}") from exc

    with tempfile.NamedTemporaryFile(suffix=os.path.splitext(image.filename or "")[1] or ".png", delete=False) as tmp:
        tmp.write(raw)
        path = tmp.name

    try:
        started = time.time()
        if engine == "tesseract":
            text = _tesseract(path, psm if psm is not None else DEFAULT_PSM)
            model = "tesseract"
        else:
            text = _paddle_ocr(path)
            model = f"paddleocr/{PADDLE_LANG}"
        infer = time.time() - started

        return {"engine": engine, "text": text, "model": model, "infer_secs": round(infer, 3)}
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"{engine} OCR failed: {exc}") from exc
    finally:
        os.unlink(path)


@app.post("/ocr/words")
async def ocr_words(
    image: UploadFile = File(...),
    psm: int | None = Form(None),
    min_conf: float | None = Form(None),
) -> dict:
    """Line-level OCR (tesseract only): returns reading-order lines, each with a pixel box and mean
    confidence, plus the joined text and the frame height. Drives the roll join — a line box resolves
    a click to the text under it, and line-level grouping reads dense UI text far cleaner than the
    per-word confidence dropping it replaced. `image_height` lets the caller discard top-of-frame
    chrome (the macOS menu bar) without hardcoding a pixel margin."""
    raw = await image.read()
    try:
        Image.open(io.BytesIO(raw)).verify()
    except Exception as exc:
        raise HTTPException(status_code=422, detail=f"not a decodable image: {exc}") from exc

    with tempfile.NamedTemporaryFile(suffix=os.path.splitext(image.filename or "")[1] or ".png", delete=False) as tmp:
        tmp.write(raw)
        path = tmp.name

    try:
        started = time.time()
        lines = _tesseract_lines(path, psm if psm is not None else DEFAULT_PSM, min_conf if min_conf is not None else 40.0)
        return {
            "engine": "tesseract",
            "lines": lines,
            "text": "\n".join(ln["text"] for ln in lines),
            "image_height": Image.open(path).height,
            "model": "tesseract",
            "infer_secs": round(time.time() - started, 3),
        }
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"tesseract line OCR failed: {exc}") from exc
    finally:
        os.unlink(path)
