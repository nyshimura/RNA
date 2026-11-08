# -*- coding: utf-8 -*-
"""
core/society.py — A Sociedade Hierárquica Autocrática-Cooperativa RNA
=====================================================================
Combina o melhor das estruturas organizacionais humanas e biológicas:
1. Comando Autocrático Estratégico (Estilo Legiões Romanas / Estado-Maior):
   Decide de cima para baixo qual unidade das massas deve gastar energia/tokens,
   protegendo a elite intelectual (Conselho Imperial Tier S) para guerras complexas.
2. Cooperação Biológica de Colmeia / Formigueiro (Estigmergia & Passagem de Bastão):
   Cada operária faz sua fração dentro do seu limite de tokens e deixa o rastro
   (@RNA_HANDOFF) na árvore de pastas para a próxima continuar sem desperdício.
3. Integração com Internet das Coisas (IoT Edge & Sensores Públicos Globais):
   Sensores de clima, tempo, rede e status processam dados do mundo real a custo ZERO de LLM.
"""

SWARM_COOPERATIVE_DIRECTIVE = (
    "[RNA IMPERIAL HIVE PROTOCOL v3.0] You are a unit in a hierarchical cooperative AI society. "
    "Obey token-preservation law: output dense, direct results with zero fluff. "
    "If your token quota reaches its limit before finishing, emit at the end: "
    "@RNA_HANDOFF|PCT:<0-100>|SOLVED:<what your unit solved>|NEXT:<orders for the next unit>"
)

# Definição das 4 Castas da Sociedade RNA (100% IA & LLMs)
SOCIETY_CASTES = {
    "CASTE_1_SOVEREIGN": {
        "tier": "TIER_S_APEX",
        "rank_title": "👑 Conselho Imperial (Sovereign Apex)",
        "access_role": "WRITE_SYNTHESIZER",
        "preservation_mode": "PRESERVE_FOR_COMPLEX",
        "max_token_budget": 1500,
        "min_complexity_required": 7,
        "doctrine": "Elite estratégica de IA. Modelos 27B+ dedicados a código avançado e arquitetura.",
    },
    "CASTE_2_GENERAL": {
        "tier": "TIER_A_HEAVY",
        "rank_title": "⚔️ General Escriba (Strategist & Writer)",
        "access_role": "WRITE_SYNTHESIZER",
        "preservation_mode": "BALANCED_RELAY",
        "max_token_budget": 950,
        "min_complexity_required": 3,
        "doctrine": "Sintetizadores com autoridade WRITE para redigir o código e a síntese final.",
    },
    "CASTE_3_CENTURION": {
        "tier": "TIER_B_WORKER",
        "rank_title": "🛡️ Centurião Operário (Mass Infantry)",
        "access_role": "READ_SCOUT",
        "preservation_mode": "ACTIVE_FRONT_LINE",
        "max_token_budget": 650,
        "min_complexity_required": 1,
        "doctrine": "A força motriz das massas de IA para absorver demandas cotidianas.",
    },
    "CASTE_4_SCOUT": {
        "tier": "TIER_C_SCOUT",
        "rank_title": "🦅 Batedor Web (A10G ZeroGPU Scout)",
        "access_role": "READ_SCOUT",
        "preservation_mode": "ACTIVE_FRONT_LINE",
        "max_token_budget": 350,
        "min_complexity_required": 1,
        "doctrine": "Rastreadores que trazem dados frescos da internet para alimentar as IAs.",
    },
}

# Núcleo Inicial de Máquinas Gradio LLM Verificadas
SEED_GRADIO_LLM_NODES = [
    {
        "id": "kingpin92/qwen3-8-27b-iq2-chat",
        "name": "Qwen3 27B Imperial Node",
        "host": "https://kingpin92-qwen3-8-27b-iq2-chat.hf.space",
        "endpoint": "/chat",
        "specialty": "imperial_code_architect",
        "type": "HF Gradio LLM Space (Own Quota)",
        "caste": "CASTE_1_SOVEREIGN",
    },
    {
        "id": "nakaassoumatine/einstein-chatbot",
        "name": "Einstein General Synthesizer",
        "host": "https://nakaassoumatine-einstein-chatbot.hf.space",
        "endpoint": "/chat",
        "specialty": "general_write_synthesizer",
        "type": "HF Gradio LLM Space (Own Quota)",
        "caste": "CASTE_2_GENERAL",
    },
    {
        "id": "Umranz/Shruti-Soft-Chat",
        "name": "Shruti Vanguard Centurion",
        "host": "https://umranz-shruti-soft-chat.hf.space",
        "endpoint": "/chat",
        "specialty": "frontline_scout_and_worker",
        "type": "HF Gradio LLM Space (Own Quota)",
        "caste": "CASTE_3_CENTURION",
    },
    {
        "id": "Qwen/Qwen3-Coder-WebDev",
        "name": "Qwen3 Coder WebDev Sovereign",
        "host": "https://qwen-qwen3-coder-webdev.hf.space",
        "endpoint": "/generate_code",
        "specialty": "webdev_sovereign",
        "type": "HF Gradio LLM Space (Own Quota)",
        "caste": "CASTE_1_SOVEREIGN",
    },
]

# Lista vazia mantida apenas para retrocompatibilidade
PUBLIC_IOT_EDGE_NODES = []


def enrich_node_with_caste(node_dict: dict, caste_key: str = None) -> dict:
    """Aplica os atributos da Casta Social (patente, orçamento de tokens, papel READ/WRITE) à máquina."""
    if not caste_key:
        caste_key = node_dict.get("caste") or classify_machine_into_caste(
            node_dict.get("id", ""),
            node_dict.get("type", ""),
            "Gradio LLM" in node_dict.get("type", ""),
        )
    c_meta = SOCIETY_CASTES.get(caste_key, SOCIETY_CASTES["CASTE_3_CENTURION"])
    node_dict["caste"] = caste_key
    node_dict["rank_title"] = c_meta["rank_title"]
    node_dict["tier"] = c_meta["tier"]
    node_dict["access_role"] = c_meta["access_role"]
    node_dict["preservation_mode"] = c_meta["preservation_mode"]
    node_dict["max_token_budget"] = c_meta["max_token_budget"]
    node_dict["min_complexity_required"] = c_meta["min_complexity_required"]
    node_dict["quota_owner"] = "Container Remoto (Próprio da Máquina)"
    return node_dict


def classify_machine_into_caste(node_id: str, node_type: str, is_active_llm: bool) -> str:
    lid = node_id.lower()
    if any(k in lid for k in ("70b", "72b", "32b", "27b", "r1", "coder", "deepseek-v3", "maverick")):
        return "CASTE_1_SOVEREIGN"
    if any(k in lid for k in ("14b", "13b", "einstein", "mistral", "gemma-2-9b", "qwen3")):
        return "CASTE_2_GENERAL"
    if is_active_llm:
        return "CASTE_3_CENTURION"
    return "CASTE_4_SCOUT"


def autocratic_task_decree(complexity: int, estimated_tokens: int, online_llm_nodes: list[dict]) -> dict:
    """
    Decreto Autocrático de Alocação de Tropas:
    Analisa a complexidade (1..10) e decide quem das massas vai resolver o problema,
    poupando o Conselho Imperial (CASTE_1_SOVEREIGN) sempre que as massas puderem dar conta!
    """
    sovereigns = [n for n in online_llm_nodes if n.get("caste") == "CASTE_1_SOVEREIGN"]
    generals = [n for n in online_llm_nodes if n.get("caste") == "CASTE_2_GENERAL"]
    centurions = [n for n in online_llm_nodes if n.get("caste") in ("CASTE_3_CENTURION", "CASTE_4_SCOUT")]

    # Escolha do Hop 1 (Batedor / Infantaria da Massa para absorver o impacto inicial)
    if centurions:
        hop1_unit = centurions[0]
    elif generals:
        hop1_unit = generals[0]
    else:
        hop1_unit = online_llm_nodes[0]

    # Escolha do Hop 2 (Quem vai sintetizar / escrever o resultado final)
    if complexity >= 7 or estimated_tokens >= 1100:
        # Guerra Complexa: O Conselho Imperial (Sovereign Tier S) é autorizado a agir!
        hop2_unit = sovereigns[0] if sovereigns else (generals[0] if generals else online_llm_nodes[-1])
        decree_reason = (
            f"DECRETADO ESTADO DE ALTA COMPLEXIDADE ({complexity}/10). "
            f"Infantaria ({hop1_unit['name']}) prepara o terreno e o Conselho Imperial ({hop2_unit['name']}) finaliza."
        )
        imperial_preserved = False
    else:
        # Tarefa Padrão: O Conselho Imperial é PRESERVADO! As massas (Centuriões + Generais) resolvem tudo.
        hop2_unit = generals[0] if generals else (centurions[-1] if len(centurions) > 1 else online_llm_nodes[-1])
        decree_reason = (
            f"DECRETO DE PRESERVAÇÃO IMPERIAL (Complexidade {complexity}/10 < 7). "
            f"O Conselho Imperial (Tier S) permanece intocado! A Infantaria ({hop1_unit['name']}) "
            f"e o General Escriba ({hop2_unit['name']}) resolverão 100% da missão."
        )
        imperial_preserved = True

    return {
        "hop1_unit": hop1_unit,
        "hop2_unit": hop2_unit,
        "imperial_preserved": imperial_preserved,
        "decree_text": decree_reason,
    }

