"""
Shap-E Text-to-3D FastAPI server.

Endpoints:
  POST /generate  — テキストプロンプトから .glb メッシュを生成して返す
  GET  /health    — ヘルスチェック
"""

import io
import logging
import tempfile
import time
from contextlib import asynccontextmanager
from pathlib import Path

import numpy as np
import torch
import trimesh
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import Response
from pydantic import BaseModel, Field

# ---------------------------------------------------------------------------
# Shap-E imports
# ---------------------------------------------------------------------------
from shap_e.diffusion.gaussian_diffusion import diffusion_from_config
from shap_e.diffusion.sample import sample_latents
from shap_e.models.download import load_config, load_model
from shap_e.util.notebooks import decode_latent_mesh

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Global model cache
# ---------------------------------------------------------------------------
_device: torch.device | None = None
_xm = None
_model = None
_diffusion = None

OUTPUT_DIR = Path(__file__).resolve().parent / "output"
OUTPUT_DIR.mkdir(exist_ok=True)


def _load_models():
    """Load Shap-E models once at startup."""
    global _device, _xm, _model, _diffusion

    # Shap-E uses float64 ops internally which MPS doesn't support.
    # Force CPU for reliable generation on Apple Silicon.
    _device = torch.device("cpu")

    logger.info("Using device: %s", _device)
    logger.info("Loading Shap-E transmitter model …")
    _xm = load_model("transmitter", device=_device)

    logger.info("Loading Shap-E text300M model …")
    _model = load_model("text300M", device=_device)

    logger.info("Loading diffusion config …")
    _diffusion = diffusion_from_config(load_config("diffusion"))

    logger.info("All models loaded.")


@asynccontextmanager
async def lifespan(app: FastAPI):
    _load_models()
    yield


# ---------------------------------------------------------------------------
# FastAPI app
# ---------------------------------------------------------------------------
app = FastAPI(title="Text-to-3D (Shap-E)", lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


# ---------------------------------------------------------------------------
# Schemas
# ---------------------------------------------------------------------------
class GenerateRequest(BaseModel):
    prompt: str = Field(..., min_length=1, max_length=500)
    guidance_scale: float = Field(default=15.0, ge=1.0, le=100.0)
    steps: int = Field(default=64, ge=16, le=256)


class HealthResponse(BaseModel):
    status: str
    device: str
    models_loaded: bool


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------
@app.get("/health", response_model=HealthResponse)
async def health():
    return HealthResponse(
        status="ok",
        device=str(_device) if _device else "not loaded",
        models_loaded=_xm is not None,
    )


@app.post("/generate")
async def generate(req: GenerateRequest):
    if _xm is None or _model is None or _diffusion is None:
        raise HTTPException(status_code=503, detail="Models not loaded yet.")

    prompt = req.prompt.strip()
    logger.info("Generating 3D for prompt: %r  (steps=%d, guidance=%.1f)", prompt, req.steps, req.guidance_scale)

    t0 = time.time()

    batch_size = 1
    latents = sample_latents(
        batch_size=batch_size,
        model=_model,
        diffusion=_diffusion,
        guidance_scale=req.guidance_scale,
        model_kwargs=dict(texts=[prompt] * batch_size),
        progress=True,
        clip_denoised=True,
        use_fp16=False,
        use_karras=True,
        karras_steps=req.steps,
        sigma_min=1e-3,
        sigma_max=160,
        s_churn=0,
    )

    elapsed_sample = time.time() - t0
    logger.info("Sampling done in %.1fs. Decoding mesh …", elapsed_sample)

    # Decode latent → mesh
    t1 = time.time()
    mesh = decode_latent_mesh(_xm, latents[0]).tri_mesh()
    elapsed_decode = time.time() - t1
    logger.info("Mesh decoded in %.1fs. Vertices=%d, Faces=%d", elapsed_decode, len(mesh.verts), len(mesh.faces))

    # Convert to trimesh → GLB bytes
    tri = trimesh.Trimesh(
        vertices=mesh.verts,
        faces=mesh.faces,
    )

    # Assign vertex colors if available
    if hasattr(mesh, "vertex_channels") and mesh.vertex_channels:
        channels = mesh.vertex_channels
        if "R" in channels and "G" in channels and "B" in channels:
            r = np.clip(np.array(channels["R"]) * 255, 0, 255).astype(np.uint8)
            g = np.clip(np.array(channels["G"]) * 255, 0, 255).astype(np.uint8)
            b = np.clip(np.array(channels["B"]) * 255, 0, 255).astype(np.uint8)
            a = np.full_like(r, 255)
            tri.visual.vertex_colors = np.stack([r, g, b, a], axis=-1)

    glb_bytes = tri.export(file_type="glb")

    # Also save a copy locally
    out_path = OUTPUT_DIR / f"shap_e_{int(time.time())}.glb"
    out_path.write_bytes(glb_bytes)
    logger.info("Saved GLB to %s  (%.1f KB)", out_path, len(glb_bytes) / 1024)

    total = time.time() - t0
    logger.info("Total generation time: %.1fs", total)

    return Response(
        content=glb_bytes,
        media_type="model/gltf-binary",
        headers={
            "Content-Disposition": 'attachment; filename="model.glb"',
            "X-Generation-Time": f"{total:.1f}",
        },
    )
