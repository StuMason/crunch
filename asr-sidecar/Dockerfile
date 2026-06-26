# crunch ASR sidecar — verbatim STT (CrisperWhisper via faster-whisper/CTranslate2).
# Slim Python base; PyAV wheels carry their own ffmpeg, CTranslate2 needs libgomp.
FROM python:3.11-slim

# libgomp1: OpenMP runtime CTranslate2 links against on CPU.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libgomp1 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

# HF model cache lands here — mount a volume so the ~1.5GB CrisperWhisper download
# survives restarts/redeploys instead of re-pulling every boot.
ENV ASR_MODEL=nyrahealth/faster_CrisperWhisper \
    HF_HOME=/models

EXPOSE 9000

# Single worker: the model is held warm in-process and a second worker would just
# double the RAM for a second copy. Scale by replicas, not workers.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000", "--workers", "1"]
