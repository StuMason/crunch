# crunch ASR sidecar — Whisper large-v3-turbo (faster-whisper / CTranslate2, CPU).
FROM python:3.11-slim

# libgomp1: OpenMP runtime CTranslate2 links against. PyAV (via faster-whisper)
# carries its own ffmpeg, so no system ffmpeg needed.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libgomp1 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

# HF cache on a volume so the model download survives restarts.
ENV ASR_MODEL=large-v3-turbo \
    ASR_COMPUTE_TYPE=int8 \
    HF_HOME=/models

EXPOSE 9000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000", "--workers", "1"]
