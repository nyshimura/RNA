# -*- coding: utf-8 -*-
"""
core/mycelium.py — Roteamento Micelial de Fungos (Physarum polycephalum)
========================================================================
Sintetizado cooperativamente pelo Conselho Imperial (kingpin92/qwen3-8-27b-iq2-chat)
e integrado à Sociedade Hierárquica RNA.

Conceito Biológico:
- Cada conexão até uma máquina é uma HIFA FÚNGICA com Condutância/Espessura (hypha_mass).
- Caminho de Menor Resistência (Path of Least Resistance):
  Resistência R = (latência_ms / 1000) / condutância_hifa.
- Reforço (Espessamento da Hifa): Quando uma máquina responde rápido e sem erro,
  o fluxo de nutrientes aumenta a massa da hifa (hypha_mass += reforço).
- Atrofia / Desvio de Obstáculo: Se uma máquina bate em limite de ZeroGPU, fila cheia
  ou timeout, a hifa atrofia imediatamente (hypha_mass *= 0.25) e o fungo ramifica
  para o vizinho mais livre!
"""

import math


def update_hypha_conductance(node_entry: dict, success: bool, latency_ms: float, error_msg: str = "") -> dict:
    """
    Atualiza a espessura da hifa micelial (hypha_mass) de uma máquina após cada tentativa.
    """
    current_mass = float(node_entry.get("hypha_mass", 1.0))

    if success and latency_ms > 0:
        # Nutriente encontrado! A hifa engrossa inversamente proporcional à latência
        nutrient_boost = max(0.15, min(1.5, 2500.0 / max(200.0, latency_ms)))
        new_mass = min(10.0, round(current_mass + nutrient_boost, 3))
        state = "THICKENING (Fluxo Nutriente Ativo)"
    else:
        # Obstáculo no solo (ex: ZeroGPU limit, timeout, fila cheia): a hifa atrofia rapidamente
        decay_factor = 0.20 if "ZeroGPU" in error_msg else 0.45
        new_mass = max(0.05, round(current_mass * decay_factor, 3))
        state = "ATROPHYING (Desviando de Obstáculo)"

    # Resistência efetiva R (quanto MENOR, mais fácil o caminho para o fungo)
    eff_latency = latency_ms if (success and latency_ms > 0) else 15000.0
    resistance = round((eff_latency / 1000.0) / max(0.05, new_mass), 3)

    node_entry["hypha_mass"] = new_mass
    node_entry["mycelium_resistance"] = resistance
    node_entry["mycelium_state"] = state
    return node_entry


def sort_by_least_resistance(nodes: list[dict]) -> list[dict]:
    """
    Ordena as máquinas pelo Caminho de Menor Resistência Micelial (menor R = maior condutância + menor latência).
    """
    def resistance_key(n: dict) -> float:
        if "mycelium_resistance" in n:
            return float(n["mycelium_resistance"])
        mass = float(n.get("hypha_mass", 1.0))
        lat = float(n.get("latency_ms", 3000.0)) or 3000.0
        return (lat / 1000.0) / max(0.05, mass)

    return sorted(nodes, key=resistance_key)

