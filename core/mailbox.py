# -*- coding: utf-8 -*-
"""
core/mailbox.py — Outlook das Máquinas, Árvore de Pastas mRNA & Telemetria
"""

import datetime
import hashlib
import json
import os
import re
from pathlib import Path

from .config import (
    BASE_DIR,
    ERRORS_DIR,
    MAX_CONCURRENT_REQUESTS,
    MESH_DIR,
    MIN_NODE_COOLDOWN_SEC,
    MRNA_DIR,
    NODES_DIR,
    PROTEINS_DIR,
    PROTOCOL_VERSION,
    TELEMETRY_FILE,
    ensure_dirs,
    load_secret,
    mask_token,
    now_iso,
    sanitize_folder_name,
)
from .society import SEED_GRADIO_LLM_NODES, enrich_node_with_caste


def count_mesh_filesystem() -> tuple[int, int]:
    total_folders = 0
    total_files = 0
    if not MESH_DIR.exists():
        return 0, 0
    for _, dirs, files in os.walk(MESH_DIR):
        total_folders += len(dirs)
        total_files += len(files)
    return total_folders, total_files


def build_folder_tree_snapshot(max_depth: int = 4) -> list:
    ensure_dirs()
    tree = []

    def walk_dir(current_path: Path, depth: int) -> dict:
        node = {
            "name": current_path.name,
            "path": str(current_path.relative_to(BASE_DIR)).replace("\\", "/"),
            "type": "folder",
            "children": [],
        }
        if depth > max_depth:
            return node
        try:
            entries = sorted(current_path.iterdir(), key=lambda p: (p.is_file(), p.name))
            for entry in entries[:45]:
                if entry.is_dir():
                    node["children"].append(walk_dir(entry, depth + 1))
                else:
                    size_b = entry.stat().st_size
                    node["children"].append({
                        "name": entry.name,
                        "path": str(entry.relative_to(BASE_DIR)).replace("\\", "/"),
                        "type": "file",
                        "size_bytes": size_b,
                    })
        except Exception:
            pass
        return node

    for sub in (MRNA_DIR, PROTEINS_DIR, NODES_DIR, ERRORS_DIR):
        if sub.exists():
            tree.append(walk_dir(sub, 1))
    return tree


def clean_machine_output(text: str) -> str:
    """Remove cabeçalhos de protocolo, instruções de sistema e ruídos técnicos para exibição limpa."""
    if not text:
        return ""
    lines = []
    for line in text.splitlines():
        s = line.strip()
        if s.startswith(("@LORA_", "@HIVE_", "@MESH_", "[RNA", "DECREE:", "GLOBAL MISSION:", "YOUR ORDERS")):
            continue
        lines.append(line)
    cleaned = "\n".join(lines).strip()
    if "@RNA_HANDOFF" in cleaned:
        cleaned = cleaned.split("@RNA_HANDOFF")[0].strip()
    if "ZeroGPU runs limit" in cleaned:
        return "⚠️ [Obstáculo Detectado no Solo]: Limite temporário de ZeroGPU atingido nesta unidade. O Micélio desviou automaticamente a tarefa para a próxima máquina disponível."
    return cleaned


def reconstruct_mailbox_from_mrna() -> list[dict]:
    """Lê as conversas reais em mesh/mrna/ e formata para o Outlook das Máquinas."""
    ensure_dirs()
    emails = []
    if not MRNA_DIR.exists():
        return emails

    thread_dirs = sorted([d for d in MRNA_DIR.iterdir() if d.is_dir()], key=lambda p: p.name, reverse=True)
    for t_dir in thread_dirs[:25]:
        job_id = t_dir.name
        sub_dirs = sorted([d for d in t_dir.iterdir() if d.is_dir()], key=lambda p: p.name)

        for s_dir in sub_dirs:
            sname = s_dir.name
            est_file = s_dir / "estimate.md"
            if est_file.exists():
                raw = est_file.read_text(encoding="utf-8", errors="replace")
                m_node = re.search(r"node:([^|\n]+)", raw)
                m_comp = re.search(r"complexity:([^|\n]+)", raw)
                m_tok = re.search(r"est_tokens:([^|\n]+)", raw)
                sender = m_node.group(1).strip() if m_node else "Umranz/Shruti-Soft-Chat"
                comp = m_comp.group(1).strip() if m_comp else "3/10"
                est_t = m_tok.group(1).strip() if m_tok else "660"
                body_clean = raw.split("---", 1)[-1].strip() if "---" in raw else raw
                emails.append({
                    "id": hashlib.md5(f"{job_id}:{sname}".encode()).hexdigest()[:10],
                    "thread_id": job_id,
                    "hop": "Hop 1 (Batedor READ_SCOUT / Decreto de Alocação)",
                    "folder_type": "READ_SCOUT",
                    "from_node": sender,
                    "from_role": "🦅 Batedor / Centurião (READ_SCOUT)",
                    "to_node": "Comando da Sociedade RNA",
                    "to_role": "WRITE_SYNTHESIZER",
                    "quota_used": "Token Interno do Container Remoto (0 reqs na sua chave)",
                    "subject": f"[Decreto & Cálculo: {est_t} tok | Complexidade {comp}] Alocação da tropa definida",
                    "timestamp": datetime.datetime.fromtimestamp(est_file.stat().st_mtime).isoformat(timespec="seconds"),
                    "summary_human": (
                        f"A unidade {sender} inspecionou a missão usando a cota interna dela, calculou o gasto de "
                        f"~{est_t} tokens (Complexidade {comp}) e emitiu o decreto de quem das massas vai resolver."
                    ),
                    "body": body_clean,
                    "file_path": str(est_file.relative_to(BASE_DIR)).replace("\\", "/"),
                })

            baton_file = s_dir / "baton_handoff.md"
            if baton_file.exists():
                raw = baton_file.read_text(encoding="utf-8", errors="replace")
                m_from = re.search(r"from:([^|\n]+)", raw)
                m_to = re.search(r"to:([^|\n]+)", raw)
                m_pct = re.search(r"done_pct:([^|\n]+)", raw)
                sender = m_from.group(1).strip() if m_from else "Infantaria (READ)"
                receiver = m_to.group(1).strip() if m_to else "General Escriba (WRITE)"
                pct = m_pct.group(1).strip() if m_pct else "60%"
                body_clean = raw.split("---", 1)[-1].strip() if "---" in raw else raw
                emails.append({
                    "id": hashlib.md5(f"{job_id}:{sname}:baton".encode()).hexdigest()[:10],
                    "thread_id": job_id,
                    "hop": "Hop 1 ➔ Hop 2 (Passagem de Bastão @RNA_HANDOFF)",
                    "folder_type": "HANDOFF",
                    "from_node": sender,
                    "from_role": "🛡️ Infantaria READ (Pedindo Escrita)",
                    "to_node": receiver,
                    "to_role": "⚔️ General Escriba WRITE",
                    "quota_used": "Delegação Direta entre Containers Remotos",
                    "subject": f"🔗 [Passagem de Bastão {pct}] {sender.split('/')[-1]} ➔ {receiver.split('/')[-1]}: Escreva a solução final",
                    "timestamp": datetime.datetime.fromtimestamp(baton_file.stat().st_mtime).isoformat(timespec="seconds"),
                    "summary_human": (
                        f"A unidade {sender} concluiu {pct} da frente inicial e transmitiu o bastão para "
                        f"{receiver} (que possui autoridade WRITE_SYNTHESIZER) redigir os arquivos finais."
                    ),
                    "body": body_clean,
                    "file_path": str(baton_file.relative_to(BASE_DIR)).replace("\\", "/"),
                })

            syn_file = s_dir / "syn.md"
            prompt_sent = ""
            if syn_file.exists():
                syn_raw = syn_file.read_text(encoding="utf-8", errors="replace")
                prompt_sent = syn_raw.split("---", 1)[-1].strip() if "---" in syn_raw else syn_raw

            for rx_sub in s_dir.iterdir():
                if rx_sub.is_dir():
                    pkt_file = rx_sub / "pkt.md"
                    if pkt_file.exists():
                        raw = pkt_file.read_text(encoding="utf-8", errors="replace")
                        header_line = raw.splitlines()[0] if raw else ""
                        m_from = re.search(r"from:([^|\n]+)", header_line)
                        m_codon = re.search(r"codon:([^|\n]+)", header_line)
                        m_ms = re.search(r"ms:([^|\n]+)", header_line)
                        m_tok = re.search(r"tok_gen:([^|\n]+)", header_line)
                        m_sav = re.search(r"tok_saved:([^|\n]+)", header_line)

                        sender = m_from.group(1).strip() if m_from else "HF Remote Node"
                        codon = m_codon.group(1).strip() if m_codon else sname
                        ms = m_ms.group(1).strip() if m_ms else "0"
                        tok_g = m_tok.group(1).strip() if m_tok else "0"
                        tok_s = m_sav.group(1).strip() if m_sav else "0"
                        body_clean = clean_machine_output(raw.split("---", 1)[-1].strip() if "---" in raw else raw)

                        is_write = any(k in sender.lower() for k in ("qwen", "einstein")) or "WRITE" in codon or "STAGE2" in codon or "CORE" in codon
                        role_label = "⚔️ General/Soberano WRITE" if is_write else "🛡️ Centurião READ"

                        emails.append({
                            "id": hashlib.md5(f"{job_id}:{rx_sub.name}".encode()).hexdigest()[:10],
                            "thread_id": job_id,
                            "hop": f"{codon}",
                            "folder_type": "WRITE_SYNTH" if is_write else "READ_SCOUT",
                            "from_node": sender,
                            "from_role": role_label,
                            "to_node": "Ribossomo Central",
                            "to_role": "STORAGE_PROTEIN",
                            "quota_used": f"Token Próprio ({tok_g} tok | +{tok_s} poupados)",
                            "subject": f"{codon.replace('_', ' ')} — {sender.split('/')[-1]} ({ms}ms)",
                            "timestamp": datetime.datetime.fromtimestamp(pkt_file.stat().st_mtime).isoformat(timespec="seconds"),
                            "summary_human": (
                                f"Processado por {sender.split('/')[-1]} ({role_label}) em {ms} ms. "
                                f"Economizou +{tok_s} tokens do seu plano."
                            ),
                            "body": body_clean,
                            "file_path": str(pkt_file.relative_to(BASE_DIR)).replace("\\", "/"),
                        })

    emails.sort(key=lambda x: x.get("timestamp", ""), reverse=True)
    return emails


def write_node_folder(node_data: dict):
    safe_id = sanitize_folder_name(node_data["id"], 40)
    node_root = NODES_DIR / safe_id
    node_root.mkdir(parents=True, exist_ok=True)

    for child in node_root.iterdir():
        if child.is_dir():
            try:
                for f in child.iterdir():
                    f.unlink()
                child.rmdir()
            except Exception:
                pass

    tier_short = node_data.get("tier", "TIER_B").replace("TIER_", "T")
    status_label = f"{node_data['status']}_{tier_short}_{node_data['lora']['sf']}_{int(node_data['latency_ms'])}ms"
    status_dir = node_root / sanitize_folder_name(status_label, 48)
    status_dir.mkdir(parents=True, exist_ok=True)

    meta_md = (
        f"@LORA_NODE|v3.0|id:{node_data['id']}|status:{node_data['status']}|caste:{node_data.get('caste')}|role:{node_data.get('access_role')}\n"
        f"---\n"
        f"- **Nome**: {node_data['name']}\n"
        f"- **Patente na Sociedade**: `{node_data.get('rank_title', 'Centurião')}`\n"
        f"- **Papel (READ/WRITE/IoT)**: `{node_data.get('access_role', 'READ_SCOUT')}`\n"
        f"- **Quem paga os Tokens**: `{node_data.get('quota_owner', 'Container Remoto')}`\n"
        f"- **URL**: {node_data['url']}\n"
        f"- **Latência**: `{node_data['latency_ms']} ms` | **RSSI**: `{node_data['lora']['rssi_dbm']} dBm`\n"
        f"- **Tarefas Processadas**: `{node_data.get('tasks_completed', 0)}` | **Tokens Remotos**: `{node_data.get('tokens_processed', 0)}`\n"
        f"- **Último Contato**: `{node_data['last_seen']}`\n"
    )
    (status_dir / "node_meta.md").write_text(meta_md, encoding="utf-8")


def log_error(telemetry: dict, node_id: str, err_code: str, message: str, url: str = ""):
    ensure_dirs()
    ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    short_node = sanitize_folder_name(node_id, 24)
    short_code = sanitize_folder_name(err_code, 20)
    err_folder = ERRORS_DIR / f"{ts}_{short_node}_{short_code}"
    err_folder.mkdir(parents=True, exist_ok=True)

    err_entry = {
        "id": hashlib.md5(f"{ts}:{node_id}:{err_code}:{message}".encode()).hexdigest()[:8],
        "timestamp": now_iso(),
        "node_id": node_id,
        "code": err_code,
        "message": message[:320],
        "url": url,
        "folder": str(err_folder.relative_to(BASE_DIR)).replace("\\", "/"),
    }
    err_md = (
        f"@LORA_ERR|v3.0|node:{node_id}|code:{err_code}|time:{err_entry['timestamp']}\n"
        f"---\n"
        f"**URL**: `{url}`\n"
        f"**Detalhe**: {message}\n"
    )
    (err_folder / "err.md").write_text(err_md, encoding="utf-8")

    telemetry.setdefault("errors", []).insert(0, err_entry)
    telemetry["errors"] = telemetry["errors"][:30]


def load_telemetry() -> dict:
    ensure_dirs()
    if TELEMETRY_FILE.exists():
        try:
            data = json.loads(TELEMETRY_FILE.read_text(encoding="utf-8"))
            for nid, n in data.get("nodes", {}).items():
                enrich_node_with_caste(n)
            return data
        except Exception:
            pass
    return {
        "protocol": PROTOCOL_VERSION,
        "created_at": now_iso(),
        "updated_at": now_iso(),
        "last_scan_epoch": 0,
        "token_status": {
            "configured": False,
            "masked": "MODO ANÔNIMO SEGURO",
            "account_user": None,
        },
        "kpis": {
            "agent_tokens_saved": 0,
            "remote_tokens_generated": 0,
            "remote_tokens_preserved_by_tiering": 0,
            "total_bytes_processed": 0,
            "total_tasks_completed": 0,
            "total_synthesized_projects": 0,
            "total_relay_handoffs": 0,
            "total_folders_created": 0,
            "total_files_created": 0,
            "active_nodes_count": 0,
            "iot_sensors_count": 0,
            "preserved_apex_nodes_count": 0,
            "scanned_nodes_count": 0,
            "error_count": 0,
            "mailbox_messages_count": 0,
        },
        "nodes": {},
        "mailbox": [],
        "threads": [],
        "proteins": [],
        "errors": [],
    }


def save_telemetry(telemetry: dict):
    ensure_dirs()
    secret = load_secret()
    token = secret.get("hf_token", "")
    telemetry["protocol"] = PROTOCOL_VERSION
    telemetry["updated_at"] = now_iso()
    telemetry["safety_guard"] = {
        "mode": "AUTOCRATIC_HIVE_MULTI_HOP_SAFE",
        "account_isolation": "ACTIVE (Sua chave só dá o Wake inicial; cada máquina remota usa o token do próprio container dela)",
        "read_write_delegation": "ACTIVE (Batedores/Centuriões READ repassam a escrita para Generais/Soberanos WRITE)",
        "max_concurrent": MAX_CONCURRENT_REQUESTS,
        "min_node_cooldown_sec": MIN_NODE_COOLDOWN_SEC,
    }
    telemetry["token_status"]["configured"] = bool(token)
    telemetry["token_status"]["masked"] = mask_token(token)

    folders_count, files_count = count_mesh_filesystem()
    telemetry["kpis"]["total_folders_created"] = folders_count
    telemetry["kpis"]["total_files_created"] = files_count

    nodes = telemetry.get("nodes", {})
    seed_map = {sp["id"]: sp for sp in SEED_GRADIO_LLM_NODES}
    for nid, n in nodes.items():
        if nid in seed_map:
            enrich_node_with_caste(n, seed_map[nid]["caste"])
        else:
            enrich_node_with_caste(n)

    active_nodes = [n for n in nodes.values() if n.get("status") == "ONLINE"]
    iot_nodes = [n for n in nodes.values() if n.get("caste") == "CASTE_5_IOT_SENSOR"]
    preserved_nodes = [
        n for n in nodes.values()
        if n.get("preservation_mode") == "PRESERVE_FOR_COMPLEX" or n.get("tier") == "TIER_S_APEX"
    ]
    mailbox = reconstruct_mailbox_from_mrna()
    telemetry["mailbox"] = mailbox
    telemetry["kpis"]["mailbox_messages_count"] = len(mailbox)
    telemetry["kpis"]["active_nodes_count"] = len(active_nodes)
    telemetry["kpis"]["iot_sensors_count"] = len(iot_nodes)
    telemetry["kpis"]["preserved_apex_nodes_count"] = len(preserved_nodes)
    telemetry["kpis"]["scanned_nodes_count"] = len(nodes)
    telemetry["kpis"]["error_count"] = len(telemetry.get("errors", []))
    telemetry["mesh_tree"] = build_folder_tree_snapshot()

    TELEMETRY_FILE.write_text(json.dumps(telemetry, indent=2, ensure_ascii=False), encoding="utf-8")

