# crunch OCR sidecar — Tesseract (default) + PaddleOCR PP-OCRv6 (lazy), CPU.
#
# Python 3.13 specifically: PaddleOCR was validated on the cp313 aarch64 paddlepaddle wheel.
# The cp311 wheel produced silently-empty inference on this box (ran, no error, no text), so
# we pin to the ABI we actually tested. The build self-test below guards against a regression.
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

# Pre-bake the PaddleOCR PP-OCRv6 det+rec models into the image (~130MB) so the first
# `engine=paddle` request doesn't stall on a download, AND assert paddle can actually read
# text on this box — a silently-empty wheel fails the build here instead of at runtime. This
# only writes models to disk; paddle is still lazy-loaded into RAM per request (idle ~70MB).
RUN python selftest.py

ENV OCR_DEFAULT_ENGINE=tesseract \
    OCR_DEFAULT_PSM=6

EXPOSE 9000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000", "--workers", "1"]
