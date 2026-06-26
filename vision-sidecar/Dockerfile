# crunch vision sidecar — Florence-2-base (transformers, CPU).
FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# CPU-only torch + torchvision (no CUDA) from the same index so their versions match.
# timm/transformers pull in torchvision; if it comes from PyPI against a cpu-wheel torch
# you get "operator torchvision::nms does not exist". Installing both here pins the pair.
RUN pip install --no-cache-dir torch torchvision --index-url https://download.pytorch.org/whl/cpu

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

# HF cache on a volume so the model download survives restarts.
ENV VISION_MODEL=microsoft/Florence-2-base \
    HF_HOME=/models

EXPOSE 9000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000", "--workers", "1"]
