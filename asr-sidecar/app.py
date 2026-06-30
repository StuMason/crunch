"""
crunch ASR sidecar — speech-to-text (Whisper large-v3-turbo via faster-whisper).

Verbatim CrisperWhisper needs a GPU (full transformers OOMs CPU; the CTranslate2 port
produces broken text). On this no-GPU box the right tool is standard Whisper turbo:
clean, fast, accurate, with word-level timestamps. edator pairs this with its own
envelope.js (RMS onsets) to find filler/dead-air cut points the transcript omits.

Contract: OpenAI Whisper `verbose_json` (so a stock Whisper client can read it), plus a
couple of additive crunch fields (`model`, `infer_secs`):
    POST /transcribe (multipart audio) ->
      { task, language, duration, text, words: [ { word, start, end }, ... ], model, infer_secs }
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
# 0 lets CTranslate2 pick its own default (a hardware heuristic, NOT "all cores"). In prod
# this is pinned low (ASR_THREADS=6 in docker-compose) + a hard Docker cpus limit, so a
# transcribe can't peg every core and starve the other apps sharing the box.
CPU_THREADS = int(os.environ.get("ASR_THREADS", "0"))
BEAM_SIZE = int(os.environ.get("ASR_BEAM_SIZE", "5"))
# large-v3-turbo via faster-whisper otherwise emits all-lowercase, unpunctuated text.
# Seeding the decoder with a short, punctuated prompt restores normal casing and
# punctuation (it's prior context, not transcribed). Override/clear via env if it ever
# leaks into output on very short clips.
INITIAL_PROMPT = os.environ.get("ASR_INITIAL_PROMPT", "Okay, so let's take a look at this.")

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
            initial_prompt=INITIAL_PROMPT or None,  # restores punctuation/casing
        )
        words, text_parts = [], []
        for seg in segments:
            text_parts.append(seg.text)
            for w in (seg.words or []):
                words.append({"word": w.word.strip(), "start": round(w.start, 3), "end": round(w.end, 3)})
        infer = time.time() - started

        return {
            "task": "transcribe",
            "language": info.language,
            "duration": round(info.duration, 3),
            "text": "".join(text_parts).strip(),
            "words": words,
            "model": MODEL,
            "infer_secs": round(infer, 2),
        }
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"transcription failed: {exc}") from exc
    finally:
        os.unlink(path)
