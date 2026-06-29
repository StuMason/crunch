"""
crunch vision sidecar — image understanding (Florence-2-base).

vit-gpt2 (the ONNX captioner) is the core's weak spot and transformers-php can't run
anything better. Florence-2-base is a tiny (~0.23B) multi-task vision model that runs
fine on CPU and is dramatically smarter: captions at three levels of detail, plus OCR,
object detection and region captioning from the *same* weights. That makes /caption good
and quietly unlocks a whole vision toolkit on the spare-capacity box (no GPU).

Contract (owned by us):
    POST /caption (multipart image, optional `detail`, optional `task`) ->
      caption tasks: { model, task, caption }
      other tasks:   { model, task, result }   # result is Florence-2's parsed structure
"""

from __future__ import annotations

import os
import time
from contextlib import nullcontext
from unittest.mock import patch

import torch
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from PIL import Image

MODEL = os.environ.get("VISION_MODEL", "microsoft/Florence-2-base")
NUM_BEAMS = int(os.environ.get("VISION_NUM_BEAMS", "3"))

# Florence-2 OCR/detection on a full-res screen frame (e.g. 2560x1440) blows the CPU
# latency budget — it tries to read/box every glyph and runs for >90s, returning a 500
# (issue #11). Downscaling the longest edge to MAX_EDGE before inference cuts the work
# dramatically; coordinates are reported against the ORIGINAL size so callers still map.
# 0 disables downscaling.
MAX_EDGE = int(os.environ.get("VISION_MAX_EDGE", "1024"))

# Map a friendly `detail` value to Florence-2's caption task token.
DETAIL_TASKS = {
    "normal": "<CAPTION>",
    "detailed": "<DETAILED_CAPTION>",
    "more": "<MORE_DETAILED_CAPTION>",
}
CAPTION_TASKS = set(DETAIL_TASKS.values())

app = FastAPI(title="crunch-vision", version="1.0.0")
_model = None
_processor = None


# Bind the ORIGINAL get_imports at import time. The patch below replaces the module
# attribute, so calling it by attribute lookup inside the patch would recurse forever —
# we must hold a direct reference to the real function and delegate to that.
from transformers.dynamic_module_utils import get_imports as _orig_get_imports  # noqa: E402


def _no_flash_attn(filename: str):
    """
    Florence-2's remote code unconditionally imports flash_attn, which isn't installed
    (and is GPU-only). transformers probes a module's imports before loading it; strip
    flash_attn from that probe so the eager CPU path is used instead.
    """
    return [imp for imp in _orig_get_imports(filename) if imp != "flash_attn"]


def load():
    global _model, _processor
    if _model is None:
        from transformers import AutoModelForCausalLM, AutoProcessor

        with patch("transformers.dynamic_module_utils.get_imports", _no_flash_attn):
            _model = AutoModelForCausalLM.from_pretrained(
                MODEL, trust_remote_code=True, torch_dtype=torch.float32
            ).eval()
        _processor = AutoProcessor.from_pretrained(MODEL, trust_remote_code=True)
    return _model, _processor


@app.get("/health")
def health() -> dict:
    return {"ok": True, "model": MODEL, "loaded": _model is not None}


@app.post("/caption")
async def caption(
    image: UploadFile = File(...),
    detail: str = Form("normal"),
    task: str | None = Form(None),
) -> dict:
    prompt = task or DETAIL_TASKS.get(detail, "<CAPTION>")

    raw = await image.read()
    try:
        img = Image.open(__import__("io").BytesIO(raw)).convert("RGB")
    except Exception as exc:
        raise HTTPException(status_code=422, detail=f"unreadable image: {exc}") from exc

    # Downscale oversized frames before inference (issue #11). thumbnail() preserves aspect
    # ratio and mutates in place; we keep the original size so coordinates map to the frame
    # the caller actually sent, not the downscaled one.
    original_size = (img.width, img.height)
    if MAX_EDGE > 0 and max(original_size) > MAX_EDGE:
        img.thumbnail((MAX_EDGE, MAX_EDGE), Image.LANCZOS)
    processed_size = (img.width, img.height)

    model, processor = load()
    started = time.time()
    try:
        inputs = processor(text=prompt, images=img, return_tensors="pt")
        # Detailed captions and OCR/OD can be long; short captions don't need the budget.
        max_new = 256 if prompt == "<CAPTION>" else 1024
        with torch.inference_mode():
            generated_ids = model.generate(
                input_ids=inputs["input_ids"],
                pixel_values=inputs["pixel_values"],
                max_new_tokens=max_new,
                num_beams=NUM_BEAMS,
                do_sample=False,
            )
        decoded = processor.batch_decode(generated_ids, skip_special_tokens=False)[0]
        # Pass the ORIGINAL size so OCR/detection coordinates are scaled back to the
        # frame the caller sent (aspect ratio is preserved, so the bins map cleanly).
        parsed = processor.post_process_generation(
            decoded, task=prompt, image_size=original_size
        )
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"vision inference failed: {exc}") from exc

    infer = round(time.time() - started, 2)
    value = parsed.get(prompt, parsed)
    sizes = {"original_size": list(original_size), "processed_size": list(processed_size)}

    if prompt in CAPTION_TASKS:
        return {"model": MODEL, "task": prompt, "infer_secs": infer, **sizes, "caption": str(value).strip()}
    return {"model": MODEL, "task": prompt, "infer_secs": infer, **sizes, "result": value}
