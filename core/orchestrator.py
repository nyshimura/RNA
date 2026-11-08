# -*- coding: utf-8 -*-
"""
core/orchestrator.py — Comando Autocrático-Cooperativo & Síntese Multi-Hop RNA
"""

import concurrent.futures
import datetime
import re
from pathlib import Path

from .config import (
    BASE_DIR,
    MAX_CONCURRENT_REQUESTS,
    MRNA_DIR,
    PROTEINS_DIR,
    now_iso,
    sanitize_folder_name,
)
from .discovery import scan_huggingface_mesh
from .mailbox import load_telemetry, log_error, save_telemetry, write_node_folder
from .mycelium import sort_by_least_resistance, update_hypha_conductance
from .society import (
    SEED_GRADIO_LLM_NODES,
    SWARM_COOPERATIVE_DIRECTIVE,
    autocratic_task_decree,
    enrich_node_with_caste,
)
from .transceiver import call_any_node


def select_best_online_llm_nodes(telemetry: dict, include_preserved: bool = True) -> list[dict]:
    online = [
        n for n in telemetry.get("nodes", {}).values()
        if n.get("status") == "ONLINE" and "Gradio LLM" in n.get("type", "")
    ]
    if not include_preserved:
        workers = [n for n in online if n.get("tier") != "TIER_S_APEX"]
        if workers:
            online = workers

    online = sort_by_least_resistance(online)
    if not online:
        return [enrich_node_with_caste(dict(sp), sp["caste"]) for sp in SEED_GRADIO_LLM_NODES[:3]]
    return online


def build_mesh_status_header(telemetry: dict) -> str:
    online = select_best_online_llm_nodes(telemetry, include_preserved=True)
    peers_str = ", ".join(
        f"{n['id'].split('/')[-1]}[{n.get('caste','CENTURION')}|{n.get('access_role','READ')}|budget:{n.get('max_token_budget',600)}t]"
        for n in online[:6]
    )
    return f"@HIVE_DECREE_STATUS|multi_hop:own_container_tokens|units_online:{len(online)}|legion:{peers_str}"


def estimate_task_complexity_and_budget(task_description: str, telemetry: dict) -> dict:
    words = len(task_description.split())
    lower = task_description.lower()
    complex_keywords = ("arquitetura", "completo", "sistema", "fullstack", "microservi", "complex", "compiler", "engine", "database", "cripto", "multi")
    kw_hits = sum(1 for k in complex_keywords if k in lower)

    est_complexity = min(10, max(2, 3 + kw_hits * 2 + (words // 15)))
    est_tokens = est_complexity * 220

    workers = select_best_online_llm_nodes(telemetry, include_preserved=False)
    scout_node = workers[0] if workers else select_best_online_llm_nodes(telemetry, include_preserved=True)[0]

    scout_prompt = (
        f"{SWARM_COOPERATIVE_DIRECTIVE}\n"
        f"You are the VANGUARD SCOUT COST CALCULATOR unit. Analyze this mission and reply in 2 short lines:\n"
        f"LINE 1: COMPLEXITY=<1-10>|EST_TOKENS=<number>|USE_APEX=<YES or NO>\n"
        f"LINE 2: STEP1=<READ analysis for Hop 1 Infantry> || STEP2=<WRITE implementation for Hop 2 General>\n"
        f"MISSION: {task_description}"
    )
    res = call_any_node(scout_node, prompt=scout_prompt, max_tokens=180, timeout=25.0)
    step1_plan = "Analyze requirements, define architecture and data contract (Hop 1 Mass Infantry READ)."
    step2_plan = "Write complete runnable code and verification based on Hop 1 blueprint (Hop 2 General WRITE)."
    scout_raw = ""

    if res.get("ok"):
        scout_raw = res["text"]
        m_comp = re.search(r"COMPLEXITY\s*=\s*(\d+)", scout_raw, re.IGNORECASE)
        m_tok = re.search(r"EST_TOKENS\s*=\s*(\d+)", scout_raw, re.IGNORECASE)
        if m_comp:
            est_complexity = min(10, max(1, int(m_comp.group(1))))
        if m_tok:
            est_tokens = max(120, min(8000, int(m_tok.group(1))))
        if "STEP1=" in scout_raw and "STEP2=" in scout_raw:
            parts = scout_raw.split("STEP1=")[-1].split("||")
            if len(parts) >= 2:
                step1_plan = parts[0].strip()
                step2_plan = parts[1].replace("STEP2=", "").strip()

    use_apex = est_complexity >= 7 or est_tokens >= 1100
    tokens_preserved_on_apex = 0 if use_apex else 1400

    return {
        "scout_node": scout_node["id"],
        "scout_latency_ms": res.get("latency_ms", 0),
        "scout_tokens": res.get("tokens_generated", 0),
        "complexity": est_complexity,
        "estimated_tokens": est_tokens,
        "use_apex_tier_s": use_apex,
        "tokens_preserved_on_apex": tokens_preserved_on_apex,
        "step1_plan": step1_plan[:260],
        "step2_plan": step2_plan[:260],
        "scout_raw": scout_raw[:400],
    }


def parse_handoff_marker(text: str) -> dict:
    if "@RNA_HANDOFF" not in text:
        return {
            "has_handoff": False,
            "pct": 100,
            "solved_summary": text[-350:].strip(),
            "next_work": "Write final code files and usage tests based on this READ specification.",
        }
    parts = text.split("@RNA_HANDOFF")
    main_body = parts[0].strip()
    marker_tail = parts[1].strip()
    pct = 65
    m_pct = re.search(r"PCT:(\d+)", marker_tail)
    if m_pct:
        pct = int(m_pct.group(1))
    solved = main_body[-300:] if main_body else "READ stage completed"
    m_sol = re.search(r"SOLVED:([^|\n]+)", marker_tail)
    if m_sol:
        solved = m_sol.group(1).strip()
    next_work = "Write complete implementation files."
    m_next = re.search(r"NEXT:([^\n]+)", marker_tail)
    if m_next:
        next_work = m_next.group(1).strip()

    return {
        "has_handoff": True,
        "pct": pct,
        "solved_summary": solved,
        "next_work": next_work,
    }


def dispatch_single_codon(
    thread_dir: Path,
    seq_idx: int,
    codon_name: str,
    prompt: str,
    preferred_node: dict,
    fallback_nodes: list[dict],
) -> dict:
    candidates = [preferred_node] + [n for n in fallback_nodes if n["id"] != preferred_node["id"]]
    last_failure = None

    tx_folder_name = sanitize_folder_name(f"{seq_idx:02d}_TX_{codon_name}_SYN", 48)
    tx_dir = thread_dir / tx_folder_name
    tx_dir.mkdir(parents=True, exist_ok=True)
    (tx_dir / "syn.md").write_text(
        f"@LORA_TX|v3.0|codon:{codon_name}|target:{preferred_node['id']}|caste:{preferred_node.get('caste','CENTURION')}|time:{now_iso()}\n---\n{prompt}\n",
        encoding="utf-8",
    )

    for node_entry in candidates:
        nid = node_entry["id"]
        budget = node_entry.get("max_token_budget", 800)
        res = call_any_node(node_entry, prompt=prompt, max_tokens=budget, timeout=42.0)
        if res["ok"]:
            update_hypha_conductance(node_entry, True, res["latency_ms"])
            short_node = sanitize_folder_name(nid.split("/")[-1], 22)
            rx_folder_name = sanitize_folder_name(
                f"{seq_idx:02d}_RX_{codon_name}_{short_node}_ACK_{int(res['latency_ms'])}ms", 56
            )
            rx_dir = tx_dir / rx_folder_name
            rx_dir.mkdir(parents=True, exist_ok=True)

            tokens_saved = int(res["tokens_generated"] * 1.4) + 95
            pkt_content = (
                f"@LORA_RX|v3.0|codon:{codon_name}|from:{nid}|caste:{node_entry.get('caste','CENTURION')}|hypha:{node_entry.get('hypha_mass',1.0)}|ms:{res['latency_ms']}|tok_gen:{res['tokens_generated']}|tok_saved:{tokens_saved}\n"
                f"---\n"
                f"{res['text']}\n"
            )
            pkt_file = rx_dir / "pkt.md"
            pkt_file.write_text(pkt_content, encoding="utf-8")

            return {
                "ok": True,
                "codon": codon_name,
                "node_id": nid,
                "latency_ms": res["latency_ms"],
                "hypha_mass": node_entry.get("hypha_mass", 1.0),
                "tokens_generated": res["tokens_generated"],
                "tokens_saved": tokens_saved,
                "bytes": len(res["text"].encode("utf-8")),
                "text": res["text"],
                "pkt_path": str(pkt_file.relative_to(BASE_DIR)).replace("\\", "/"),
                "folder_path": str(rx_dir.relative_to(BASE_DIR)).replace("\\", "/"),
            }
        else:
            update_hypha_conductance(node_entry, False, res.get("latency_ms", 0), res.get("error", ""))
            last_failure = {
                "node_id": nid,
                "status": res.get("status", 0),
                "error": res.get("error", "Unknown error"),
            }

    return {
        "ok": False,
        "codon": codon_name,
        "node_id": preferred_node["id"],
        "error": last_failure["error"] if last_failure else "Nenhuma máquina online respondeu",
        "status": last_failure["status"] if last_failure else 0,
    }


def extract_code_blocks_to_files(markdown_text: str, output_dir: Path) -> list[str]:
    created_files = []
    output_dir.mkdir(parents=True, exist_ok=True)

    pattern = re.compile(
        r"(?:###\s*FILE:\s*|//\s*FILE:\s*|#\s*FILE:\s*|\*\*FILE:\s*)([a-zA-Z0-9_\-\./]+)\*\*?\s*\n+```[a-zA-Z0-9_\-]*\n(.*?)```",
        re.DOTALL,
    )
    for match in pattern.finditer(markdown_text):
        rel_path = match.group(1).strip().lstrip("/\\")
        code_body = match.group(2)
        if ".." in rel_path:
            continue
        file_path = output_dir / rel_path
        file_path.parent.mkdir(parents=True, exist_ok=True)
        file_path.write_text(code_body.strip() + "\n", encoding="utf-8")
        created_files.append(str(file_path))

    return created_files


def execute_cooperative_relay(task_description: str, target_dir: str = None) -> dict:
    """
    Executa a Missão sob Comando Autocrático-Cooperativo:
    1. Batedor (Scout) calcula o custo em tokens e a complexidade.
    2. O Comando emite o Decreto Autocrático (autocratic_task_decree) decidindo quem das massas
       (Centuriões/Generais) vai trabalhar para preservar o Conselho Imperial (Tier S).
    3. Hop 1 (READ_SCOUT) prepara o terreno e passa o bastão (@RNA_HANDOFF) para Hop 2 (WRITE_SYNTHESIZER).
    """
    telemetry = load_telemetry()
    all_online = select_best_online_llm_nodes(telemetry, include_preserved=True)
    if not all_online:
        telemetry = scan_huggingface_mesh(limit_spaces=10)
        all_online = select_best_online_llm_nodes(telemetry, include_preserved=True)

    mesh_status_str = build_mesh_status_header(telemetry)

    ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    short_slug = sanitize_folder_name(task_description, 24)
    job_id = f"relay_{ts}_{short_slug}"
    thread_dir = MRNA_DIR / job_id
    thread_dir.mkdir(parents=True, exist_ok=True)

    protein_dir = Path(target_dir) if target_dir else (PROTEINS_DIR / job_id)
    protein_dir.mkdir(parents=True, exist_ok=True)

    est = estimate_task_complexity_and_budget(task_description, telemetry)
    decree = autocratic_task_decree(est["complexity"], est["estimated_tokens"], all_online)

    node_stage1 = decree["hop1_unit"]
    node_stage2 = decree["hop2_unit"]

    scout_dir = thread_dir / sanitize_folder_name(
        f"00_SCOUT_ESTIMATE_C{est['complexity']}_{est['estimated_tokens']}tok", 54
    )
    scout_dir.mkdir(parents=True, exist_ok=True)
    (scout_dir / "estimate.md").write_text(
        f"@LORA_SCOUT|v3.0|node:{est['scout_node']}|complexity:{est['complexity']}/10|est_tokens:{est['estimated_tokens']}|use_apex:{not decree['imperial_preserved']}\n"
        f"{mesh_status_str}\n---\n"
        f"- **Decreto do Comando Autocrático**: {decree['decree_text']}\n"
        f"- **Batedor Calculador (Scout)**: `{est['scout_node']}` (`{est['scout_latency_ms']} ms`)\n"
        f"- **Complexidade Calculada**: `{est['complexity']}/10` (~`{est['estimated_tokens']} tokens`)\n"
        f"- **Unidade Convocada Hop 1 (Infantaria READ)**: `{node_stage1['id']}` ({node_stage1.get('rank_title')})\n"
        f"- **Unidade Convocada Hop 2 (Escriba WRITE)**: `{node_stage2['id']}` ({node_stage2.get('rank_title')})\n\n"
        f"### Relatório do Batedor:\n{est['scout_raw']}\n",
        encoding="utf-8",
    )

    stage1_prompt = (
        f"{SWARM_COOPERATIVE_DIRECTIVE}\n"
        f"{mesh_status_str}\n"
        f"DECREE: {decree['decree_text']}\n"
        f"GLOBAL MISSION: {task_description}\n"
        f"YOUR ORDERS (Hop 1 Unit `{node_stage1['id']}`): {est['step1_plan']}\n"
        f"Execute concisely and end with @RNA_HANDOFF so unit `{node_stage2['id']}` can write the final implementation!"
    )
    res1 = dispatch_single_codon(thread_dir, 1, "HOP1_INFANTRY_READ", stage1_prompt, node_stage1, all_online)

    handoff_info = parse_handoff_marker(res1.get("text", "")) if res1.get("ok") else {
        "has_handoff": True,
        "pct": 0,
        "solved_summary": "Hop 1 failed, delegating full write.",
        "next_work": task_description,
    }

    handoff_dir = thread_dir / sanitize_folder_name(
        f"01b_HANDOFF_{handoff_info['pct']}pct_TO_{node_stage2['id'].split('/')[-1]}", 56
    )
    handoff_dir.mkdir(parents=True, exist_ok=True)
    (handoff_dir / "baton_handoff.md").write_text(
        f"@RNA_HANDOFF_PACKET|from:{res1.get('node_id', node_stage1['id'])}|to:{node_stage2['id']}|done_pct:{handoff_info['pct']}%\n"
        f"---\n"
        f"- **Decreto Militar**: {decree['decree_text']}\n"
        f"- **De (Hop 1 Infantaria READ)**: `{res1.get('node_id', node_stage1['id'])}`\n"
        f"- **Para (Hop 2 General WRITE)**: `{node_stage2['id']}`\n"
        f"- **O que a Infantaria já estruturou**: {handoff_info['solved_summary']}\n"
        f"- **O que o General deve escrever agora**: {handoff_info['next_work']}\n",
        encoding="utf-8",
    )

    stage1_excerpt = res1.get("text", "")[:900] if res1.get("ok") else ""
    stage2_prompt = (
        f"{SWARM_COOPERATIVE_DIRECTIVE}\n"
        f"{mesh_status_str}\n"
        f"MULTI-HOP COMMAND: Unit `{res1.get('node_id', node_stage1['id'])}` completed {handoff_info['pct']}% (READ blueprint) and hands the baton to YOU (`{node_stage2['id']}`, WRITE_SYNTHESIZER).\n"
        f"ORIGINAL MISSION: {task_description}\n"
        f"HOP 1 BLUEPRINT:\n{stage1_excerpt}\n\n"
        f"YOUR WRITE ORDERS: {handoff_info['next_work']} ({est['step2_plan']}). Write the complete solution now!"
    )
    res2 = dispatch_single_codon(thread_dir, 2, "HOP2_GENERAL_WRITE", stage2_prompt, node_stage2, all_online)

    total_saved = est["tokens_preserved_on_apex"]
    total_gen = est["scout_tokens"]
    total_bytes = 0
    extracted_files = []

    for r in (res1, res2):
        if r.get("ok"):
            nid = r["node_id"]
            total_saved += r["tokens_saved"]
            total_gen += r["tokens_generated"]
            total_bytes += r["bytes"]
            if nid in telemetry["nodes"]:
                ne = telemetry["nodes"][nid]
                ne["tasks_completed"] = ne.get("tasks_completed", 0) + 1
                ne["tokens_processed"] = ne.get("tokens_processed", 0) + r["tokens_generated"]
                ne["bytes_processed"] = ne.get("bytes_processed", 0) + r["bytes"]
                ne["latency_ms"] = r["latency_ms"]
                ne["last_response"] = r["text"][:90]
                ne["last_seen"] = now_iso()
                write_node_folder(ne)
            extracted_files.extend(extract_code_blocks_to_files(r["text"], protein_dir))

    relay_report = (
        f"# Relatório Imperial da Sociedade RNA (`{job_id}`)\n\n"
        f"- **Decreto do Estado-Maior**: {decree['decree_text']}\n"
        f"- **Pedido Original**: {task_description}\n"
        f"- **Hop 1 (`{res1.get('node_id')}`)**: Analisou em `{res1.get('latency_ms', 0)} ms`\n"
        f"- **Hop 2 (`{res2.get('node_id')}`)**: Escreveu a solução final em `{res2.get('latency_ms', 0)} ms`\n\n"
        f"---\n\n"
        f"## Hop 1 (Infantaria READ) — `{res1.get('node_id')}`\n\n{res1.get('text', 'Falha')}\n\n"
        f"---\n\n"
        f"## Hop 2 (General WRITE) — `{res2.get('node_id')}`\n\n{res2.get('text', 'Falha')}\n"
    )
    (protein_dir / "RELAY_SYNTHESIS_REPORT.md").write_text(relay_report, encoding="utf-8")

    telemetry["kpis"]["agent_tokens_saved"] = telemetry["kpis"].get("agent_tokens_saved", 0) + total_saved
    telemetry["kpis"]["remote_tokens_generated"] = telemetry["kpis"].get("remote_tokens_generated", 0) + total_gen
    telemetry["kpis"]["remote_tokens_preserved_by_tiering"] = (
        telemetry["kpis"].get("remote_tokens_preserved_by_tiering", 0) + est["tokens_preserved_on_apex"]
    )
    telemetry["kpis"]["total_bytes_processed"] = telemetry["kpis"].get("total_bytes_processed", 0) + total_bytes
    telemetry["kpis"]["total_tasks_completed"] = telemetry["kpis"].get("total_tasks_completed", 0) + 2
    telemetry["kpis"]["total_synthesized_projects"] = telemetry["kpis"].get("total_synthesized_projects", 0) + 1
    telemetry["kpis"]["total_relay_handoffs"] = telemetry["kpis"].get("total_relay_handoffs", 0) + 1

    summary = {
        "job_id": job_id,
        "mode": "AUTOCRATIC_HIVE_RELAY",
        "decree": decree["decree_text"],
        "timestamp": now_iso(),
        "task": task_description,
        "complexity": est["complexity"],
        "estimated_tokens": est["estimated_tokens"],
        "apex_preserved": decree["imperial_preserved"],
        "scout_node": est["scout_node"],
        "handoff_pct": handoff_info["pct"],
        "nodes_used": [est["scout_node"], res1.get("node_id"), res2.get("node_id")],
        "tokens_saved": total_saved,
        "tokens_generated": total_gen,
        "mrna_folder": f"mesh/mrna/{job_id}",
        "protein_folder": str(protein_dir.relative_to(BASE_DIR)).replace("\\", "/") if protein_dir.is_relative_to(BASE_DIR) else str(protein_dir),
    }
    telemetry.setdefault("threads", []).insert(0, summary)
    telemetry.setdefault("proteins", []).insert(0, summary)
    save_telemetry(telemetry)
    return summary


def synthesize_rna_project(task_description: str, target_dir: str = None) -> dict:
    telemetry = load_telemetry()
    online_nodes = select_best_online_llm_nodes(telemetry, include_preserved=True)
    if not online_nodes:
        telemetry = scan_huggingface_mesh(limit_spaces=10)
        online_nodes = select_best_online_llm_nodes(telemetry, include_preserved=True)

    mesh_status_str = build_mesh_status_header(telemetry)

    ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    short_slug = sanitize_folder_name(task_description, 26)
    job_id = f"mrna_{ts}_{short_slug}"
    thread_dir = MRNA_DIR / job_id
    thread_dir.mkdir(parents=True, exist_ok=True)

    protein_dir = Path(target_dir) if target_dir else (PROTEINS_DIR / job_id)
    protein_dir.mkdir(parents=True, exist_ok=True)

    codons = [
        {
            "name": "CODON_01_ARCH",
            "prompt": f"{SWARM_COOPERATIVE_DIRECTIVE}\n{mesh_status_str}\nTask: {task_description}\nRole: Software Architect (Codon 01). Provide concise architecture.",
        },
        {
            "name": "CODON_02_CORE",
            "prompt": f"{SWARM_COOPERATIVE_DIRECTIVE}\n{mesh_status_str}\nTask: {task_description}\nRole: Core Developer (Codon 02). Write clean working code.",
        },
        {
            "name": "CODON_03_TEST_USAGE",
            "prompt": f"{SWARM_COOPERATIVE_DIRECTIVE}\n{mesh_status_str}\nTask: {task_description}\nRole: Verification Engineer (Codon 03). Write usage example.",
        },
    ]

    results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=MAX_CONCURRENT_REQUESTS) as pool:
        future_map = {}
        for idx, codon in enumerate(codons, start=1):
            assigned_node = online_nodes[(idx - 1) % len(online_nodes)]
            fut = pool.submit(
                dispatch_single_codon,
                thread_dir,
                idx,
                codon["name"],
                codon["prompt"],
                assigned_node,
                online_nodes,
            )
            future_map[fut] = codon

        for fut in concurrent.futures.as_completed(future_map):
            results.append(fut.result())

    results.sort(key=lambda r: r.get("codon", ""))

    total_saved = 0
    total_gen = 0
    total_bytes = 0
    extracted_files = []
    synthesis_sections = [
        f"# Síntese Proteica RNA — `{job_id}`\n",
        f"- **Data**: `{now_iso()}`\n",
        f"- **Pedido Original (DNA)**: {task_description}\n\n---\n",
    ]

    for r in results:
        if r.get("ok"):
            nid = r["node_id"]
            total_saved += r["tokens_saved"]
            total_gen += r["tokens_generated"]
            total_bytes += r["bytes"]

            if nid in telemetry["nodes"]:
                node_entry = telemetry["nodes"][nid]
                node_entry["tasks_completed"] = node_entry.get("tasks_completed", 0) + 1
                node_entry["tokens_processed"] = node_entry.get("tokens_processed", 0) + r["tokens_generated"]
                node_entry["bytes_processed"] = node_entry.get("bytes_processed", 0) + r["bytes"]
                node_entry["latency_ms"] = r["latency_ms"]
                node_entry["last_response"] = r["text"][:90]
                node_entry["last_seen"] = now_iso()
                write_node_folder(node_entry)

            synthesis_sections.append(
                f"## {r['codon']} (Processado por `{nid}` em `{r['latency_ms']} ms`)\n\n{r['text']}\n\n---\n"
            )
            extracted_files.extend(extract_code_blocks_to_files(r["text"], protein_dir))
        else:
            log_error(telemetry, r.get("node_id", "unknown"), f"CODON_FAIL_{r.get('status', 0)}", r.get("error", ""))

    (protein_dir / "SYNTHESIS_REPORT.md").write_text("\n".join(synthesis_sections), encoding="utf-8")

    telemetry["kpis"]["agent_tokens_saved"] = telemetry["kpis"].get("agent_tokens_saved", 0) + total_saved
    telemetry["kpis"]["remote_tokens_generated"] = telemetry["kpis"].get("remote_tokens_generated", 0) + total_gen
    telemetry["kpis"]["total_bytes_processed"] = telemetry["kpis"].get("total_bytes_processed", 0) + total_bytes
    telemetry["kpis"]["total_tasks_completed"] = telemetry["kpis"].get("total_tasks_completed", 0) + sum(1 for r in results if r.get("ok"))
    telemetry["kpis"]["total_synthesized_projects"] = telemetry["kpis"].get("total_synthesized_projects", 0) + 1

    thread_summary = {
        "job_id": job_id,
        "mode": "SAFE_PARALLEL_CODONS",
        "timestamp": now_iso(),
        "task": task_description,
        "codons_total": len(codons),
        "codons_ok": sum(1 for r in results if r.get("ok")),
        "nodes_used": list({r["node_id"] for r in results if r.get("ok")}),
        "tokens_saved": total_saved,
        "tokens_generated": total_gen,
        "mrna_folder": f"mesh/mrna/{job_id}",
        "protein_folder": str(protein_dir.relative_to(BASE_DIR)).replace("\\", "/") if protein_dir.is_relative_to(BASE_DIR) else str(protein_dir),
        "extracted_files": [str(Path(f).name) for f in extracted_files],
    }
    telemetry.setdefault("threads", []).insert(0, thread_summary)
    telemetry.setdefault("proteins", []).insert(0, thread_summary)
    save_telemetry(telemetry)
    return thread_summary


def send_single_message(prompt: str, node_id: str = None, codon_label: str = "MSG_DIRECT") -> dict:
    telemetry = load_telemetry()
    workers = select_best_online_llm_nodes(telemetry, include_preserved=False)
    all_online = select_best_online_llm_nodes(telemetry, include_preserved=True)
    if not all_online:
        telemetry = scan_huggingface_mesh(limit_spaces=10)
        workers = select_best_online_llm_nodes(telemetry, include_preserved=False)
        all_online = select_best_online_llm_nodes(telemetry, include_preserved=True)

    chosen = workers[0] if workers else all_online[0]
    if node_id and node_id in telemetry.get("nodes", {}):
        chosen = telemetry["nodes"][node_id]

    mesh_status_str = build_mesh_status_header(telemetry)
    full_prompt = f"{SWARM_COOPERATIVE_DIRECTIVE}\n{mesh_status_str}\nTask: {prompt}"

    ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    job_id = f"lora_{ts}_{sanitize_folder_name(codon_label, 18)}"
    thread_dir = MRNA_DIR / job_id
    thread_dir.mkdir(parents=True, exist_ok=True)

    res = dispatch_single_codon(thread_dir, 1, codon_label, full_prompt, chosen, all_online)
    if res.get("ok"):
        nid = res["node_id"]
        if nid in telemetry["nodes"]:
            n_entry = telemetry["nodes"][nid]
            n_entry["tasks_completed"] = n_entry.get("tasks_completed", 0) + 1
            n_entry["tokens_processed"] = n_entry.get("tokens_processed", 0) + res["tokens_generated"]
            n_entry["bytes_processed"] = n_entry.get("bytes_processed", 0) + res["bytes"]
            n_entry["latency_ms"] = res["latency_ms"]
            n_entry["last_response"] = res["text"][:90]
            n_entry["last_seen"] = now_iso()
            write_node_folder(n_entry)

        telemetry["kpis"]["agent_tokens_saved"] = telemetry["kpis"].get("agent_tokens_saved", 0) + res["tokens_saved"]
        telemetry["kpis"]["remote_tokens_generated"] = telemetry["kpis"].get("remote_tokens_generated", 0) + res["tokens_generated"]
        telemetry["kpis"]["total_bytes_processed"] = telemetry["kpis"].get("total_bytes_processed", 0) + res["bytes"]
        telemetry["kpis"]["total_tasks_completed"] = telemetry["kpis"].get("total_tasks_completed", 0) + 1

        summary = {
            "job_id": job_id,
            "mode": "SAFE_DIRECT_ROUTING",
            "timestamp": now_iso(),
            "task": prompt,
            "codons_total": 1,
            "codons_ok": 1,
            "nodes_used": [nid],
            "tokens_saved": res["tokens_saved"],
            "tokens_generated": res["tokens_generated"],
            "mrna_folder": f"mesh/mrna/{job_id}",
            "pkt_path": res["pkt_path"],
            "response_preview": res["text"][:350],
        }
        telemetry.setdefault("threads", []).insert(0, summary)
        save_telemetry(telemetry)
        return summary
    else:
        log_error(telemetry, chosen["id"], f"TX_ERR_{res.get('status', 0)}", res.get("error", ""))
        save_telemetry(telemetry)
        return res

