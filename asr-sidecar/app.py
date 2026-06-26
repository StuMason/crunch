"""
crunch ASR sidecar — verbatim speech-to-text.

A deliberately small FastAPI service that the PHP app calls over the internal
compose network for the ONE capability the pure-PHP/ONNX core can't do:
*verbatim* transcription. CrisperWhisper (via faster-whisper / CTranslate2)
transcribes every word as spoken — fillers ("um"/"uh"), stutters, false starts —
with word-level timestamps. Vanilla Whisper deletes those by design; that's the
whole reason this service exists.

Contract (owned by us, shaped for edator):
    POST /transcribe   (multipart: audio=@file)  -> 200
      { model, text, duration, language, infer_secs,
        words: [ { w, start, end }, ... ] }        # raw, nothing stripped

The model loads lazily on first request and stays warm in the process.
"""

from __future__ import annotations

import os
import tempfile
import time

from fastapi import FastAPI, File, HTTPException, UploadFile
from faster_whisper import WhisperModel

# CrisperWhisper weights in CTranslate2 form. Swap to the full PyTorch
# "nyrahealth/CrisperWhisper" only if word-timestamp precision proves insufficient
# (it's heavier/slower; edator's envelope.js already supplies frame-accurate in/outs).
MODEL = os.environ.get("ASR_MODEL", "nyrahealth/faster_CrisperWhisper")
DEVICE = os.environ.get("ASR_DEVICE", "cpu")
COMPUTE_TYPE = os.environ.get("ASR_COMPUTE_TYPE", "int8")  # int8 = fast on CPU
BEAM_SIZE = int(os.environ.get("ASR_BEAM_SIZE", "5"))

app = FastAPI(title="crunch-asr", version="1.0.0")

_model: WhisperModel | None = None


def get_model() -> WhisperModel:
    global _model
    if _model is None:
        _model = WhisperModel(MODEL, device=DEVICE, compute_type=COMPUTE_TYPE)
    return _model


@app.get("/health")
def health() -> dict:
    return {"ok": True, "model": MODEL, "loaded": _model is not None}


@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...)) -> dict:
    suffix = os.path.splitext(audio.filename or "")[1] or ".bin"
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        tmp.write(await audio.read())
        path = tmp.name

    try:
        started = time.time()
        # condition_on_previous_text=False keeps verbatim output stable (no drift
        # toward "cleaned up" phrasing). VAD off so we don't silently drop quiet
        # disfluencies — keeping them is the point.
        segments, info = get_model().transcribe(
            path,
            language=os.environ.get("ASR_LANGUAGE", "en"),
            beam_size=BEAM_SIZE,
            word_timestamps=True,
            condition_on_previous_text=False,
            vad_filter=False,
        )

        words: list[dict] = []
        text_parts: list[str] = []
        for seg in segments:
            text_parts.append(seg.text)
            for w in seg.words or []:
                words.append(
                    {
                        "w": w.word.strip(),
                        "start": round(w.start, 3),
                        "end": round(w.end, 3),
                    }
                )

        return {
            "model": MODEL,
            "text": "".join(text_parts).strip(),
            "duration": round(info.duration, 3),
            "language": info.language,
            "infer_secs": round(time.time() - started, 2),
            "words": words,
        }
    except Exception as exc:  # surface a clean 500 the PHP side can record
        raise HTTPException(status_code=500, detail=f"transcription failed: {exc}") from exc
    finally:
        os.unlink(path)
