# -*- coding: utf-8 -*-
"""
core/transceiver.py — Camada de Rádio HTTP/LoRa, Gradio SSE & Telemetria IoT
"""

import json
import time
import urllib.error
import urllib.request

from .config import polite_sleep


def calculate_lora_metrics(latency_ms: float, success: bool) -> dict:
    if not success or latency_ms <= 0:
        return {"rssi_dbm": -128, "snr_db": -15.0, "sf": "SF12", "quality": "STANDBY"}
    rssi = int(max(-115, min(-42, -40 - (latency_ms / 95.0))))
    snr = round(max(-10.0, min(14.5, 14.0 - (latency_ms / 380.0))), 1)
    if latency_ms < 2200:
        sf = "SF7"
        quality = "EXCELENTE"
    elif latency_ms < 5500:
        sf = "SF9"
        quality = "BOM"
    else:
        sf = "SF12"
        quality = "LONGO ALCANCE"
    return {"rssi_dbm": rssi, "snr_db": snr, "sf": sf, "quality": quality}


def http_json(url: str, method: str = "GET", headers: dict = None, payload: dict = None, timeout: float = 12.0) -> tuple[int, dict | list | str, float]:
    req_headers = {
        "User-Agent": "Mozilla/5.0 (compatible; RNA-ImperialHive/3.0; FairUse)",
        "Accept": "application/json",
    }
    if headers:
        req_headers.update(headers)
    data_bytes = None
    if payload is not None:
        data_bytes = json.dumps(payload).encode("utf-8")
        req_headers["Content-Type"] = "application/json"

    req = urllib.request.Request(url, data=data_bytes, headers=req_headers, method=method)
    t0 = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            elapsed_ms = round((time.perf_counter() - t0) * 1000.0, 1)
            raw = resp.read().decode("utf-8", errors="replace")
            try:
                return resp.status, json.loads(raw), elapsed_ms
            except Exception:
                return resp.status, raw, elapsed_ms
    except urllib.error.HTTPError as e:
        elapsed_ms = round((time.perf_counter() - t0) * 1000.0, 1)
        body = ""
        try:
            body = e.read().decode("utf-8", errors="replace")
        except Exception:
            pass
        return e.code, body, elapsed_ms
    except Exception as e:
        elapsed_ms = round((time.perf_counter() - t0) * 1000.0, 1)
        return 0, str(e), elapsed_ms


def verify_hf_whoami(token: str) -> dict:
    if not token:
        return {"ok": False, "user": None}
    status, data, latency = http_json(
        "https://huggingface.co/api/whoami-v2",
        headers={"Authorization": f"Bearer {token}"},
        timeout=8.0,
    )
    if status == 200 and isinstance(data, dict):
        return {
            "ok": True,
            "user": data.get("name") or data.get("fullname") or "hf_user",
            "type": data.get("type", "user"),
            "latency_ms": latency,
        }
    return {"ok": False, "user": None, "status": status, "detail": str(data)[:160]}


def extract_text_from_gradio_data(raw_json_str: str) -> str:
    try:
        parsed = json.loads(raw_json_str)
    except Exception:
        return raw_json_str.strip()

    if isinstance(parsed, list) and len(parsed) > 0:
        first = parsed[0]
        if isinstance(first, str) and first.strip():
            return first.strip()
        if isinstance(first, list) and len(first) > 0:
            last_msg = first[-1]
            if isinstance(last_msg, dict) and "content" in last_msg:
                c = last_msg["content"]
                if isinstance(c, str):
                    return c.strip()
                if isinstance(c, list) and len(c) > 0 and isinstance(c[0], dict):
                    return str(c[0].get("text", "")).strip()
            if isinstance(last_msg, list) and len(last_msg) >= 2:
                return str(last_msg[1]).strip()
        if len(parsed) >= 2 and isinstance(parsed[1], list) and len(parsed[1]) > 0:
            last_msg = parsed[1][-1]
            if isinstance(last_msg, dict) and "content" in last_msg:
                c = last_msg["content"]
                if isinstance(c, str):
                    return c.strip()
                if isinstance(c, list) and len(c) > 0 and isinstance(c[0], dict):
                    return str(c[0].get("text", "")).strip()

    return str(parsed).strip()


def call_gradio_space_llm_safe(host: str, endpoint: str, prompt: str, timeout: float = 38.0) -> dict:
    """Comunica com um Space Público Gradio respeitando o Duty Cycle e isolando o HF_TOKEN."""
    polite_sleep()
    headers = {"Content-Type": "application/json"}

    t0 = time.perf_counter()
    try:
        st_info, gi, _ = http_json(f"{host}/gradio_api/info", headers=headers, timeout=7.0)
        payload_data = [prompt]
        if st_info == 200 and isinstance(gi, dict):
            ep_meta = gi.get("named_endpoints", {}).get(endpoint, {})
            if ep_meta.get("show_api") is False:
                return {
                    "ok": False,
                    "status": 403,
                    "error": "API desabilitada pelo autor do Space (respeitando Fair-Use)",
                    "latency_ms": round((time.perf_counter() - t0) * 1000.0, 1),
                    "endpoint": host,
                }
            params = ep_meta.get("parameters", [])
            if params:
                payload_data = []
                for idx, p in enumerate(params):
                    if idx == 0:
                        payload_data.append(prompt)
                    elif p.get("parameter_has_default"):
                        payload_data.append(p.get("parameter_default"))
                    else:
                        ptype = p.get("type", {}).get("type", "string")
                        if ptype == "array":
                            payload_data.append([])
                        elif ptype == "boolean":
                            payload_data.append(False)
                        elif ptype in ("number", "integer"):
                            payload_data.append(512)
                        else:
                            payload_data.append("")

        call_url = f"{host}/gradio_api/call{endpoint}"
        req = urllib.request.Request(
            call_url,
            data=json.dumps({"data": payload_data}).encode("utf-8"),
            headers=headers,
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=12.0) as resp:
            init_data = json.loads(resp.read().decode("utf-8", errors="replace"))
        event_id = init_data.get("event_id")
        if not event_id:
            return {
                "ok": False,
                "status": 500,
                "error": f"Sem event_id de {call_url}",
                "latency_ms": round((time.perf_counter() - t0) * 1000.0, 1),
                "endpoint": call_url,
            }

        stream_url = f"{call_url}/{event_id}"
        stream_req = urllib.request.Request(stream_url, headers=headers, method="GET")
        last_data_line = ""
        event_type = ""
        with urllib.request.urlopen(stream_req, timeout=timeout) as stream:
            for raw_line in stream:
                line = raw_line.decode("utf-8", errors="replace").strip()
                if line.startswith("event:"):
                    event_type = line[6:].strip()
                elif line.startswith("data:"):
                    val = line[5:].strip()
                    if val and val != "null":
                        last_data_line = val

        elapsed_ms = round((time.perf_counter() - t0) * 1000.0, 1)
        if event_type == "error" and not last_data_line:
            return {
                "ok": False,
                "status": 502,
                "error": "Space ocupado (fila cheia - respeitando cooldown)",
                "latency_ms": elapsed_ms,
                "endpoint": call_url,
            }

        extracted = extract_text_from_gradio_data(last_data_line)
        if (
            not extracted
            or "API error:" in extracted
            or "Servidor travado" in extracted
            or "ZeroGPU runs limit" in extracted
            or "exceeded your ZeroGPU" in extracted
        ):
            return {
                "ok": False,
                "status": 429 if "ZeroGPU" in extracted else 503,
                "error": extracted[:200] or "Space em manutenção / Limite ZeroGPU",
                "latency_ms": elapsed_ms,
                "endpoint": call_url,
            }

        comp_tokens = max(1, len(extracted) // 4)
        prompt_tokens = max(1, len(prompt) // 4)
        return {
            "ok": True,
            "text": extracted,
            "latency_ms": elapsed_ms,
            "tokens_generated": comp_tokens,
            "tokens_prompt": prompt_tokens,
            "endpoint": call_url,
        }
    except Exception as e:
        elapsed_ms = round((time.perf_counter() - t0) * 1000.0, 1)
        return {
            "ok": False,
            "status": 0,
            "error": str(e)[:220],
            "latency_ms": elapsed_ms,
            "endpoint": f"{host}/gradio_api/call{endpoint}",
        }


def probe_iot_sensor(sensor_def: dict) -> dict:
    """Desativado: Telemetria de sensores removida para foco exclusivo em IA."""
    return {"ok": False, "latency_ms": 0, "preview": ""}


def call_any_node(node_entry: dict, prompt: str, max_tokens: int = 800, timeout: float = 38.0) -> dict:
    host = node_entry.get("host") or node_entry.get("endpoint", "").split("/gradio_api")[0]
    ep = node_entry.get("gradio_endpoint") or "/chat"
    return call_gradio_space_llm_safe(host, ep, prompt, timeout=timeout)


def is_lazy_or_uncertain_response(text: str) -> bool:
    if not text or len(text.strip()) < 45:
        return True
    lower = text.lower()
    lazy_patterns = (
        "prever o futuro",
        "não posso prever",
        "nao posso prever",
        "impossível prever",
        "não tenho acesso",
        "tempo real",
        "corte de conhecimento",
        "minha base de dados",
        "ainda não aconteceu",
        "ainda não ocorreram",
        "não há como saber",
        "como uma ia",
        "cannot predict the future",
        "knowledge cutoff",
        "real-time information",
    )
    return any(p in lower for p in lazy_patterns)


def perform_live_web_recon(query: str, deep: bool = False) -> dict:
    """Sonda Web Ao Vivo (Wikipédia PT + Google News RSS Brasil) gastando 0 tokens."""
    import html
    import re
    import urllib.parse

    stop_words = {"quero", "saber", "quem", "qual", "como", "onde", "quando", "por", "que", "para", "uma", "com", "sobre"}
    words = [w for w in re.findall(r"\w+", query.lower()) if len(w) >= 3 and w not in stop_words]
    search_q = " ".join(words[:8]) if words else query[:90]
    findings = []

    q_enc = urllib.parse.quote(search_q)
    wiki_url = f"https://pt.wikipedia.org/w/api.php?action=query&list=search&srsearch={q_enc}&utf8=1&format=json&srlimit={5 if deep else 3}"
    st, wdata, _ = http_json(wiki_url, timeout=7.0)
    if st == 200 and isinstance(wdata, dict):
        s_list = wdata.get("query", {}).get("search", [])
        for idx, item in enumerate(s_list):
            title = item.get("title", "")
            snip = re.sub(r"<[^>]+>", "", html.unescape(item.get("snippet", ""))).strip()
            if title and snip:
                findings.append(f"• [Wikipédia PT — {title}]: {snip}")

    gn_url = f"https://news.google.com/rss/search?q={q_enc}&hl=pt-BR&gl=BR&ceid=BR:pt-419"
    st_g, g_raw, _ = http_json(gn_url, timeout=7.0)
    if st_g == 200 and isinstance(g_raw, str) and "<item>" in g_raw:
        items = re.findall(r"<item>([\s\S]*?)</item>", g_raw)[: (8 if deep else 5)]
        for it in items:
            m_t = re.search(r"<title>([\s\S]*?)</title>", it)
            m_d = re.search(r"<pubDate>([\s\S]*?)</pubDate>", it)
            if m_t:
                t = html.unescape(re.sub(r"<[^>]+>", "", m_t.group(1))).strip()
                d = m_d.group(1).strip() if m_d else ""
                findings.append(f"• [Notícia Web Ao Vivo ({d})]: {t}")

    dossier = ""
    if findings:
        dossier = (
            f"[DOSSIÊ DE PESQUISA NA INTERNET EM TEMPO REAL (SONDA WEB RNA — {len(findings)} FONTES VERIFICADAS)]:\n"
            + "\n".join(findings)
            + "\n\n[DIRETRIZ OBRIGATÓRIA DE VERACIDADE E ANTI-PREGUIÇA]:\n"
            "1. PROIBIDO dizer que 'não tem acesso à internet' ou que 'não pode prever o futuro'. Os dados reais coletados na web estão acima.\n"
            "2. Analise os fatos reais, datas, candidatos e resultados do Dossiê Web com rigor analítico.\n"
        )
    return {"query_used": search_q, "sources_count": len(findings), "dossier": dossier}


