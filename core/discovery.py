# -*- coding: utf-8 -*-
"""
core/discovery.py — Scanner LoRa, Sensores IoT & Recrutador Autônomo do Exército RNA
"""

import concurrent.futures
import urllib.parse

from .config import (
    MAX_CONCURRENT_REQUESTS,
    SCAN_CACHE_TTL_SEC,
    load_secret,
    mask_token,
    now_iso,
    now_ts,
    polite_sleep,
)
from .mailbox import load_telemetry, log_error, save_telemetry, write_node_folder
from .society import (
    PUBLIC_IOT_EDGE_NODES,
    SEED_GRADIO_LLM_NODES,
    classify_machine_into_caste,
    enrich_node_with_caste,
)
from .transceiver import (
    calculate_lora_metrics,
    call_gradio_space_llm_safe,
    http_json,
    probe_iot_sensor,
    verify_hf_whoami,
)


def scan_huggingface_mesh(limit_spaces: int = 12, force: bool = False) -> dict:
    """
    Escaneia as unidades de elite (Gradio LLM), sensores globais IoT e catálogo público,
    classificando cada máquina na hierarquia da Sociedade RNA.
    """
    telemetry = load_telemetry()
    secret = load_secret()
    token = secret.get("hf_token", "")

    for old_router_id in list(telemetry.get("nodes", {}).keys()):
        if telemetry["nodes"][old_router_id].get("type") == "HF Inference Router":
            del telemetry["nodes"][old_router_id]
    telemetry["errors"] = [e for e in telemetry.get("errors", []) if e.get("code") != "HTTP_403"]

    last_scan = telemetry.get("last_scan_epoch", 0)
    if not force and telemetry.get("nodes") and (now_ts() - last_scan) < SCAN_CACHE_TTL_SEC:
        save_telemetry(telemetry)
        return telemetry

    whoami = verify_hf_whoami(token)
    telemetry["token_status"]["configured"] = bool(token)
    telemetry["token_status"]["masked"] = mask_token(token)
    telemetry["token_status"]["account_user"] = whoami.get("user")
    telemetry["token_status"]["valid"] = whoami.get("ok", False)

    # 1. Testa as unidades Gradio LLM (Máximo 2 simultâneas — Fair-Use)
    def probe_llm_space(sp: dict) -> tuple[str, dict, dict | None]:
        sid = sp["id"]
        res = call_gradio_space_llm_safe(
            sp["host"],
            sp["endpoint"],
            prompt="Reply ONLY with one word: ACK_LORA_OK",
            timeout=25.0,
        )
        existing = telemetry["nodes"].get(sid, {})
        lora = calculate_lora_metrics(res["latency_ms"], res["ok"])
        node_info = {
            "id": sid,
            "name": sp["name"],
            "type": sp["type"],
            "specialty": sp["specialty"],
            "caste": sp["caste"],
            "url": f"https://huggingface.co/spaces/{sid}",
            "host": sp["host"],
            "gradio_endpoint": sp["endpoint"],
            "endpoint": res.get("endpoint", ""),
            "status": "ONLINE" if res["ok"] else "STANDBY",
            "latency_ms": res["latency_ms"],
            "lora": lora,
            "tasks_completed": existing.get("tasks_completed", 0) + (1 if res["ok"] else 0),
            "tokens_processed": existing.get("tokens_processed", 0) + res.get("tokens_generated", 0),
            "bytes_processed": existing.get("bytes_processed", 0) + len(res.get("text", "")),
            "last_response": res.get("text", "")[:90] if res["ok"] else "",
            "last_seen": now_iso(),
        }
        enrich_node_with_caste(node_info, sp["caste"])
        err_info = None
        if not res["ok"]:
            err_info = {
                "node_id": sid,
                "code": f"STANDBY_{res.get('status', 0)}",
                "message": res.get("error", "Space em Standby"),
                "url": f"https://huggingface.co/spaces/{sid}",
            }
        return sid, node_info, err_info

    with concurrent.futures.ThreadPoolExecutor(max_workers=MAX_CONCURRENT_REQUESTS) as pool:
        futures = [pool.submit(probe_llm_space, sp) for sp in SEED_GRADIO_LLM_NODES]
        for fut in concurrent.futures.as_completed(futures):
            sid, node_info, err_info = fut.result()
            telemetry["nodes"][sid] = node_info
            write_node_folder(node_info)
            if err_info:
                log_error(telemetry, err_info["node_id"], err_info["code"], err_info["message"], err_info["url"])

    # 2. Expande o exército recrutando novas máquinas de IA públicas no Hugging Face
    if limit_spaces > 0:
        recruit_from_hf_catalog(telemetry, max_new=limit_spaces)

    telemetry["last_scan_epoch"] = now_ts()
    save_telemetry(telemetry)
    return telemetry


def recruit_from_hf_catalog(telemetry: dict, max_new: int = 15, search_query: str = "") -> int:
    """
    Recrutador Autônomo: Busca novos Spaces no Hugging Face de forma paginada e educada
    (1 única chamada pública GET sem token), classificando cada máquina nas Castas da Sociedade.
    """
    queries = [search_query] if search_query else ["qwen", "coder", "llama", "deepseek", "chat", "agent"]
    recruited = 0

    for q in queries:
        if recruited >= max_new:
            break
        q_enc = urllib.parse.quote(q)
        url = f"https://huggingface.co/api/spaces?search={q_enc}&sort=trendingScore&direction=-1&limit={min(25, max_new)}"
        st, spaces_list, lat = http_json(url, headers={}, timeout=8.0)
        if st != 200 or not isinstance(spaces_list, list):
            continue

        for s in spaces_list:
            if recruited >= max_new:
                break
            sid = s.get("id")
            if not sid or sid in telemetry.get("nodes", {}):
                continue
            sdk = s.get("sdk", "gradio")
            caste = classify_machine_into_caste(sid, sdk, False)
            node_info = {
                "id": sid,
                "name": sid.split("/")[-1],
                "type": f"HF Space ({sdk})",
                "specialty": f"recruited_{q}_unit",
                "caste": caste,
                "url": f"https://huggingface.co/spaces/{sid}",
                "status": "STANDBY",
                "latency_ms": round(lat, 1),
                "lora": calculate_lora_metrics(lat, True),
                "tasks_completed": 0,
                "tokens_processed": 0,
                "bytes_processed": 0,
                "last_seen": now_iso(),
            }
            enrich_node_with_caste(node_info, caste)
            telemetry["nodes"][sid] = node_info
            write_node_folder(node_info)
            recruited += 1

        polite_sleep()

    return recruited

