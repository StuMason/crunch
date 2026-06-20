# crunch

**Self-hosted, OpenAI-compatible inference API.** One bite encoder models — embeddings,
reranking, classification, vision and speech. Fast, warm, and yours.

🔗 **Live:** [crunch.stumason.dev](https://crunch.stumason.dev) · **Interactive API docs:** [/docs/api](https://crunch.stumason.dev/docs/api)

crunch runs *discriminative* ("one-shot") models — input → vector / score / label — as a
single [Laravel](https://laravel.com) app on [Octane](https://laravel.com/docs/octane)
(FrankenPHP), with models kept **warm in memory** so synchronous calls are fast. Inference is
in-process via [transformers-php](https://github.com/CodeWithKyrian/transformers-php) (ONNX
Runtime). No Python, no GPU, no per-call cost.

---

## Authentication

Send a bearer token on every request:

```
Authorization: Bearer <token>
```

- **Create tokens** in the dashboard (`/dashboard`) — shown once, copy immediately.
- Each token has a **rate limit** (requests/minute) and an optional **monthly quota**.
- OpenAI SDKs work directly with `base_url = https://crunch.stumason.dev`.

```bash
KEY="crunch_..."
curl -sX POST https://crunch.stumason.dev/embed \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"inputs":["hello world","goodbye world"]}'
```

## Endpoints

| Method & path | Body | Returns | Model |
|---|---|---|---|
| `POST /v1/embeddings` | `{"input": str \| [str]}` | OpenAI shape `{"data":[{"embedding":[…]}]}` | Qwen3-Embedding-0.6B (1024-d, unit-normalized) |
| `POST /embed` | `{"inputs": str \| [str]}` | `[[float, …]]` | Qwen3-Embedding-0.6B |
| `POST /rerank` | `{"query": str, "texts": [str], "top_k"?: int}` | `{"results":[{index, score, text}]}` (desc) | ms-marco-MiniLM-L-6-v2 |
| `POST /sentiment` | `{"inputs": str}` | `{"results":[{label, score}]}` (28 emotions) | go_emotions |
| `POST /moderate` | `{"inputs": str}` | `{"results":[{label, score}]}` (multi-category) | KoalaAI/Text-Moderation |
| `POST /classify-image` | image¹ + `{"labels": [str]}` | `{"results":[{label, score}]}` | CLIP (zero-shot) |
| `POST /caption` | image¹ | `{"caption": str}` | vit-gpt2 |
| `POST /transcribe` | audio¹ | `202` + job to poll | distil-whisper small.en |
| `GET /jobs/{id}` | — | job `{status, result, …}` | — |
| `GET /up` | — | health `200` | — |

¹ **Media inputs** (`/classify-image`, `/caption`, `/transcribe`) accept any one of:
a public `url`, a base64 string (`image` / `audio`), or a multipart file upload.

### Examples

```bash
# rerank
curl -sX POST https://crunch.stumason.dev/rerank -H "Authorization: Bearer $KEY" \
  -d '{"query":"healing peptide","texts":["BPC-157 repairs tissue","how to bake bread"]}'

# image caption (by url)
curl -sX POST https://crunch.stumason.dev/caption -H "Authorization: Bearer $KEY" \
  -d '{"url":"https://example.com/cat.jpg"}'

# transcribe (async): returns a job, then poll
JOB=$(curl -sX POST https://crunch.stumason.dev/transcribe -H "Authorization: Bearer $KEY" \
  -F audio=@clip.wav | jq -r .id)
curl -s https://crunch.stumason.dev/jobs/$JOB -H "Authorization: Bearer $KEY"
```

```python
# OpenAI SDK — embeddings
from openai import OpenAI
client = OpenAI(base_url="https://crunch.stumason.dev", api_key="crunch_...")
client.embeddings.create(model="crunch", input=["hello", "world"])
```

## Why these models

Inference runs through transformers-php, so a model needs an ONNX export **and** an
architecture/tokenizer/processor that transformers-php implements. Within that envelope the
set above is best-in-class; a couple of slots are capped by library coverage (notably
captioning, stuck at vit-gpt2 until a better captioner is supported — a sidecar is planned).
Every model is a one-line swap in `config/crunch.php`.

## Architecture

- **One container.** Laravel + Octane (FrankenPHP). Models load lazily, stay warm per worker.
- **SQLite (WAL)** for data, **database queue** for async work, **database cache** — no Redis.
- **transformers-php** (ONNX Runtime via FFI) for all inference.
- Async (transcription) runs on a queue worker in the same container; clients poll `/jobs/{id}`.
- Usage is logged per request; per-token rate limits + monthly quotas are enforced in middleware.

## Self-hosting

Deployed on [Coolify](https://coolify.io) from this repo's `Dockerfile`. Key bits:

- Build: FrankenPHP base + `ffi`, `pdo_sqlite`, `libsndfile`/`ffmpeg`; composer pulls the
  arm64 ONNX Runtime; Vite builds the React frontend.
- Persistent volume at `/data` holds the model cache (`/data/models`) and the SQLite DB.
- Env: `APP_KEY`, `CRUNCH_API_KEY` (legacy master key), `CRUNCH_MODEL_CACHE=/data/models`,
  `CRUNCH_WARM=embed` (capabilities pre-loaded on boot), `OCTANE_WORKERS`, `DB_*=sqlite`.

```bash
docker build -t crunch . && docker run -p 8000:8000 -v crunch-data:/data \
  -e APP_KEY=base64:... -e CRUNCH_API_KEY=... crunch
```

## Local development

```bash
composer install && npm install
php artisan migrate
composer run dev          # serve + vite + queue
```

Run tests: `php artisan test`.

## Roadmap

- Better captioner via a sidecar (Florence-2 / Moondream) once viable.
- More models behind the same gateway; image embeddings.
- Automated model + transformers-php update tracking with alerts.
