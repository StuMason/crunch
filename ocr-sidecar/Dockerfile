# crunch OCR sidecar — Tesseract (default) + PaddleOCR PP-OCRv6 (lazy), CPU.
FROM python:3.11-slim

# tesseract-ocr: the OCR binary pytesseract shells out to (eng traineddata included).
# libgl1 + libglib2.0-0: PaddleOCR/OpenCV link libGL.so.1 / libgthread at import — the
# classic "ImportError: libGL.so.1" on slim images. ca-certificates for model downloads.
RUN apt-get update && apt-get install -y --no-install-recommends \
      tesseract-ocr libgl1 libglib2.0-0 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

# Pre-bake the PaddleOCR PP-OCRv6 det+rec models into the image (~130MB) so the first
# `engine=paddle` request doesn't stall on a download. This only writes to disk — paddle
# is still lazy-loaded into RAM at request time (tesseract-only stays ~70MB).
RUN python -c "from paddleocr import PaddleOCR; PaddleOCR(lang='en', use_doc_orientation_classify=False, use_doc_unwarping=False, use_textline_orientation=False)"

ENV OCR_DEFAULT_ENGINE=tesseract \
    OCR_DEFAULT_PSM=6

EXPOSE 9000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000", "--workers", "1"]
