# -*- coding: utf-8 -*-
"""
core/cloud_bridge.py — Ponte de Comunicação com a API Local / Self-Hosted
=========================================================================
Conecta a Skill /rna e o CLI local ao motor nativo em `http://localhost/rna/api.php`
(ou à URL definida na variável de ambiente RNA_API_URL).
"""

import json
import os
import ssl
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

from .config import BASE_DIR, TELEMETRY_FILE, ensure_dirs
from .orchestrator import extract_code_blocks_to_files

CLOUD_ENDPOINTS = [
    os.environ.get("RNA_API_URL", "http://localhost/rna/api.php"),
    "http://127.0.0.1/rna/api.php",
]


def call_cloud_hq(action: str, payload: dict | None = None, timeout: int = 75) -> dict | None:
    """Chama a API local do RNA Engine e sincroniza telemetry.json local."""
    body_data = {"action": action}
    if payload:
        body_data.update(payload)

    raw_bytes = json.dumps(body_data, ensure_ascii=False).encode("utf-8")
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE

    for base_url in CLOUD_ENDPOINTS:
        try:
            url = f"{base_url}?action={urllib.parse.quote(action)}"
            req = urllib.request.Request(
                url,
                data=raw_bytes if payload else None,
                headers={
                    "Content-Type": "application/json; charset=utf-8",
                    "Accept": "application/json",
                    "User-Agent": "RNA-BioLoRa-Mesh/3.0 (Self-Hosted)",
                },
                method="POST" if payload else "GET",
            )
            with urllib.request.urlopen(req, timeout=timeout, context=ctx if base_url.startswith("https") else None) as resp:
                text = resp.read().decode("utf-8", errors="replace").strip()
                parsed = json.loads(text)
                if isinstance(parsed, dict):
                    # Se veio telemetry embutida ou se a própria resposta é o telemetry
                    ensure_dirs()
                    if "protocol" in parsed and "kpis" in parsed:
                        TELEMETRY_FILE.write_text(json.dumps(parsed, indent=2, ensure_ascii=False), encoding="utf-8")
                    elif isinstance(parsed.get("telemetry"), dict) and "kpis" in parsed["telemetry"]:
                        TELEMETRY_FILE.write_text(
                            json.dumps(parsed["telemetry"], indent=2, ensure_ascii=False),
                            encoding="utf-8",
                        )
                    return parsed
        except Exception:
            continue
    return None


def stream_cloud_mission(task: str, mode: str = "relay", node: str = "", target_dir: str | None = None, timeout: int = 90) -> dict | None:
    """Consome a API de Streaming em Tempo Real do motor RNA via SSE com logs no console."""
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE

    query_params = urllib.parse.urlencode({
        "action": "stream_dispatch",
        "mode": mode,
        "node": node or "",
        "task": task
    })

    final_result = None

    for base_url in CLOUD_ENDPOINTS:
        url = f"{base_url}?{query_params}"
        try:
            req = urllib.request.Request(
                url,
                headers={
                    "Accept": "text/event-stream",
                    "User-Agent": "RNA-BioLoRa-Mesh/3.0 (Self-Hosted)",
                },
                method="GET"
            )
            with urllib.request.urlopen(req, timeout=timeout, context=ctx if base_url.startswith("https") else None) as resp:
                for raw_line in resp:
                    line = raw_line.decode("utf-8", errors="replace").strip()
                    if line.startswith("data:"):
                        payload_str = line[5:].strip()
                        try:
                            event = json.loads(payload_str)
                            step = event.get("step", "")
                            machine = event.get("machine", "RNA")
                            msg = event.get("msg", "")
                            progress = event.get("progress", 0)
                            print(f"[RNA CLOUD {progress:3d}%] [{machine}] {msg}", flush=True)

                            if step == "COMPLETE" or progress >= 100:
                                if event.get("extra"):
                                    final_result = event["extra"]
                                try:
                                    resp.close()
                                except Exception:
                                    pass
                                break
                        except Exception:
                            pass
            if final_result:
                break
        except Exception as e:
            print(f"[RNA CLOUD AVISO] Conexão com {base_url} falhou: {e}", flush=True)
            continue

    if final_result:
        if final_result.get("protein_file"):
            extracted = sync_protein_file_from_cloud(final_result["protein_file"], target_dir)
            final_result["extracted_files"] = extracted
        elif target_dir and final_result.get("full_response"):
            out_dir = Path(target_dir)
            out_dir.mkdir(parents=True, exist_ok=True)
            extracted = extract_code_blocks_to_files(final_result["full_response"], out_dir)
            final_result["extracted_files"] = extracted

    return final_result


def sync_protein_file_from_cloud(rel_path: str, target_dir: str | None = None) -> list[str]:
    """Baixa o arquivo .md sintetizado, salva em mesh/ local e extrai blocos se target_dir."""
    if not rel_path:
        return []
    res = call_cloud_hq("read_file", {"path": rel_path}, timeout=20)
    if not res or not res.get("ok") or not res.get("content"):
        return []

    content = res["content"]
    local_file = BASE_DIR / rel_path
    local_file.parent.mkdir(parents=True, exist_ok=True)
    local_file.write_text(content, encoding="utf-8")

    extracted = []
    if target_dir:
        out_dir = Path(target_dir)
        out_dir.mkdir(parents=True, exist_ok=True)
        extracted = extract_code_blocks_to_files(content, out_dir)
    return extracted

