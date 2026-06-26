"""
crunch ASR sidecar — speech-to-text (Whisper large-v3-turbo via faster-whisper).

Verbatim CrisperWhisper needs a GPU (full transformers OOMs CPU; the CTranslate2 port
produces broken text). On this no-GPU box the right tool is standard Whisper turbo:
clean, fast, accurate, with word-level timestamps. edator pairs this with its own
envelope.js (RMS onsets) to find filler/dead-air cut points the transcript omits.

Contract (owned by us, shaped for edator):
    POST /transcribe (multipart audio) ->
      { model, text, duration, infer_secs, language, words: [ { w, start, end }, ... ] }
"""

from __future__ import annotations

import os
import tempfile
import time

from fastapi import FastAPI, File, HTTPException, UploadFile
from faster_whisper import WhisperModel

MODEL = os.environ.get("ASR_MODEL", "large-v3-turbo")
DEVICE = os.environ.get("ASR_DEVICE", "cpu")
COMPUTE_TYPE = os.environ.get("ASR_COMPUTE_TYPE", "int8")
CPU_THREADS = int(os.environ.get("ASR_THREADS", "0"))  # 0 = use all cores (turbo is small)
BEAM_SIZE = int(os.environ.get("ASR_BEAM_SIZE", "5"))

app = FastAPI(title="crunch-asr", version="4.0.0")
_model = None


def get_model() -> WhisperModel:
    global _model
    if _model is None:
        _model = WhisperModel(MODEL, device=DEVICE, compute_type=COMPUTE_TYPE, cpu_threads=CPU_THREADS)
    return _model


@app.get("/health")
def health() -> dict:
    return {"ok": True, "model": MODEL, "loaded": _model is not None}


@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...)) -> dict:
    suffix = os.path.splitext(audio.filename or "")[1] or ".wav"
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        tmp.write(await audio.read())
        path = tmp.name

    try:
        started = time.time()
        segments, info = get_model().transcribe(
            path,
            language=os.environ.get("ASR_LANGUAGE", "en"),
            beam_size=BEAM_SIZE,
            word_timestamps=True,
            vad_filter=False,
        )
        words, text_parts = [], []
        for seg in segments:
            text_parts.append(seg.text)
            for w in (seg.words or []):
                words.append({"w": w.word.strip(), "start": round(w.start, 3), "end": round(w.end, 3)})
        infer = time.time() - started

        return {
            "model": MODEL,
            "text": "".join(text_parts).strip(),
            "duration": round(info.duration, 3),
            "language": info.language,
            "infer_secs": round(infer, 2),
            "words": words,
        }
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"transcription failed: {exc}") from exc
    finally:
        os.unlink(path)
