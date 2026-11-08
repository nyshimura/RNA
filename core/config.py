# -*- coding: utf-8 -*-
"""
core/config.py — Configurações Globais, Caminhos e Guardião de Duty Cycle (Fair-Use)
"""

import datetime
import json
import os
import random
import re
import time
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
STATE_DIR = BASE_DIR / "state"
MESH_DIR = BASE_DIR / "mesh"
NODES_DIR = MESH_DIR / "nodes"
MRNA_DIR = MESH_DIR / "mrna"
PROTEINS_DIR = MESH_DIR / "proteins"
ERRORS_DIR = MESH_DIR / "errors"

SECRET_FILE = STATE_DIR / "secret_config.json"
TELEMETRY_FILE = STATE_DIR / "telemetry.json"

# Parâmetros do Guardião Legal (LoRa Duty Cycle — Zero Risco de Ban)
MAX_CONCURRENT_REQUESTS = 2
MIN_NODE_COOLDOWN_SEC = 12
SCAN_CACHE_TTL_SEC = 600
JITTER_MIN_SEC = 0.9
JITTER_MAX_SEC = 2.0

PROTOCOL_VERSION = "RNA-LoRa-v3.0-AutocraticHive"
TZ_SP = datetime.timezone(datetime.timedelta(hours=-3))


def now_iso() -> str:
    return datetime.datetime.now(TZ_SP).isoformat(timespec="seconds")


def now_ts() -> float:
    return time.time()


def polite_sleep():
    """Espaçamento suave (Fair-Use) entre pacotes de rádio LoRa."""
    time.sleep(random.uniform(JITTER_MIN_SEC, JITTER_MAX_SEC))


def ensure_dirs():
    for d in (STATE_DIR, MESH_DIR, NODES_DIR, MRNA_DIR, PROTEINS_DIR, ERRORS_DIR):
        d.mkdir(parents=True, exist_ok=True)


def sanitize_folder_name(text: str, max_len: int = 48) -> str:
    cleaned = re.sub(r"[^a-zA-Z0-9_\-\.]", "_", str(text).strip())
    cleaned = re.sub(r"_+", "_", cleaned).strip("_.")
    return (cleaned[:max_len] or "node").rstrip("_.")


def load_secret() -> dict:
    ensure_dirs()
    if SECRET_FILE.exists():
        try:
            return json.loads(SECRET_FILE.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {"hf_token": os.environ.get("HF_TOKEN", ""), "mode": "safe_fair_use_lora"}


def save_secret(hf_token: str):
    ensure_dirs()
    data = {
        "hf_token": hf_token.strip(),
        "updated_at": now_iso(),
        "mode": "safe_fair_use_lora",
    }
    SECRET_FILE.write_text(json.dumps(data, indent=2), encoding="utf-8")


def mask_token(token: str) -> str:
    if not token or len(token) < 10:
        return "MODO ANÔNIMO SEGURO"
    return f"{token[:6]}...{token[-4:]}"

