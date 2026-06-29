# crunch OCR sidecar — Tesseract (default) + PaddleOCR PP-OCRv6 (lazy), CPU.
#
# Python 3.13: validated against the cp313 paddlepaddle wheel. paddlepaddle 3.2.2 ships
# manylinux wheels for cp311 + cp313 on both x86_64 and aarch64, so this builds on either arch.
# (History: on the old aarch64 box the cp311 wheel ran but produced silently-empty inference,
# so we standardised on cp313.) Belt-and-braces against any wheel/arch surprise: the build
# self-test below AND the runtime self-verify in app.py both fail LOUD if paddle can't actually
# read text — so a port to a new arch can't silently ship a dead engine.
FROM python:3.13-slim

# tesseract-ocr: the OCR binary pytesseract shells out to (eng traineddata included).
# libgl1 + libglib2.0-0: PaddleOCR/OpenCV link libGL.so.1 / libgthread at import — the
# classic "ImportError: libGL.so.1" on slim images. ca-certificates for model downloads.
RUN apt-get update && apt-get install -y --no-install-recommends \
      tesseract-ocr libgl1 libglib2.0-0 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py selftest.py ./

# Pin HOME so the build-time bake and the runtime process resolve the SAME PaddleOCR model
# cache (~/.paddlex). Without this, the build `RUN` (HOME=/root) bakes models to /root/.paddlex
# but the runtime container's HOME isn't guaranteed to be /root — paddle then finds no models
# and returns empty in ~0.2s with no error (observed on prod). Setting HOME makes them agree.
ENV HOME=/root

# Pre-bake the PaddleOCR PP-OCRv6 det+rec models into the image (~130MB) so the first
# `engine=paddle` request doesn't stall on a download, AND assert paddle can actually read
# text on this box — a silently-empty wheel fails the build here instead of at runtime. This
# only writes models to disk; paddle is still lazy-loaded into RAM per request (idle ~70MB).
RUN python selftest.py

ENV OCR_DEFAULT_ENGINE=tesseract \
    OCR_DEFAULT_PSM=6

EXPOSE 9000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000", "--workers", "1"]
