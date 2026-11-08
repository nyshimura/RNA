#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
lora_mesh.py — Ponto de Entrada Modular da Sociedade Hierárquica RNA (100% Local / Self-Hosted)
================================================================================================
Executa 100% localmente na máquina do usuário, comunicando-se diretamente com IAs públicas no
Hugging Face Spaces e com o servidor local em `http://localhost/rna/api.php` (ou via Python puro).
"""

import argparse
import json

from core.cloud_bridge import call_cloud_hq, stream_cloud_mission, sync_protein_file_from_cloud
from core.config import mask_token, save_secret
from core.discovery import recruit_from_hf_catalog, scan_huggingface_mesh
from core.mailbox import load_telemetry, save_telemetry
from core.orchestrator import (
    execute_cooperative_relay,
    send_single_message,
    synthesize_rna_project,
)


def main():
    parser = argparse.ArgumentParser(description="RNA Bio-LoRa Mesh & AI Swarm CLI v3.0 (100% Local / Self-Hosted)")
    parser.add_argument("--set-token", type=str, help="Define ou atualiza o HF_TOKEN localmente")
    parser.add_argument("--scan", action="store_true", help="Escaneia unidades de IA públicas no Hugging Face")
    parser.add_argument("--force-scan", action="store_true", help="Força novo scan na Sociedade RNA")
    parser.add_argument("--recruit", type=int, default=0, help="Recruta autonomamente N novas máquinas públicas")
    parser.add_argument("--limit", type=int, default=15, help="Limite de Spaces públicos no recrutamento por ciclo")
    parser.add_argument("--send", type=str, help="Envia pacote LoRa diretamente para uma IA pública")
    parser.add_argument("--node", type=str, default=None, help="ID específico da unidade para envio")
    parser.add_argument("--synthesize", type=str, help="Divide missão em códons e sintetiza arquivos localmente")
    parser.add_argument("--relay", type=str, help="Executa Decreto Autocrático Multi-Hop (Hop 1 READ -> Hop 2 WRITE)")
    parser.add_argument("--target-dir", type=str, default=None, help="Pasta destino para os arquivos sintetizados")
    parser.add_argument("--status", action="store_true", help="Exibe resumo do status local da malha")
    parser.add_argument("--local", action="store_true", help="Força execução 100%% local sem chamar a API web")

    args = parser.parse_args()

    if args.set_token:
        save_secret(args.set_token)
        cloud_res = None if args.local else call_cloud_hq("save_token", {"hf_token": args.set_token})
        print(json.dumps({"ok": True, "masked": mask_token(args.set_token), "saved_locally": True}))
        return

    if args.recruit > 0:
        if not args.local:
            cloud_res = call_cloud_hq("recruit", {"count": args.recruit})
            if cloud_res and cloud_res.get("ok"):
                r = cloud_res.get("result", {})
                k = cloud_res.get("telemetry", {}).get("kpis", {})
                print(
                    f"[RNA RECRUITER] +{r.get('recruited', 0)} novas maquinas! "
                    f"Exercito Total: {k.get('scanned_nodes_count', r.get('nodes_total'))} unidades de IA "
                    f"({k.get('active_nodes_count', 0)} ONLINE)"
                )
                return
        tel = load_telemetry()
        added = recruit_from_hf_catalog(tel, max_new=args.recruit)
        save_telemetry(tel)
        k = tel["kpis"]
        print(
            f"[RNA RECRUITER] +{added} novas maquinas! "
            f"Exercito Total: {k['scanned_nodes_count']} unidades ({k['active_nodes_count']} ONLINE)"
        )
        return

    if args.scan or args.force_scan:
        if not args.local:
            cloud_res = call_cloud_hq("scan", {"limit": args.limit})
            if cloud_res and cloud_res.get("ok"):
                k = cloud_res.get("telemetry", {}).get("kpis", {})
                print(
                    f"[RNA SCAN] Online: {k.get('active_nodes_count')}/{k.get('scanned_nodes_count')} | "
                    f"Soberanos Tier S: {k.get('preserved_apex_nodes_count', 0)} | "
                    f"Outlook Msgs: {k.get('mailbox_messages_count', 0)}"
                )
                return
        tel = scan_huggingface_mesh(limit_spaces=args.limit, force=args.force_scan)
        k = tel["kpis"]
        print(
            f"[RNA SCAN] Online: {k['active_nodes_count']}/{k['scanned_nodes_count']} | "
            f"Outlook Msgs: {k.get('mailbox_messages_count', 0)}"
        )
        return

    if args.send:
        if not args.local:
            res = stream_cloud_mission(args.send, mode="send", node=args.node or "", target_dir=args.target_dir)
            if res and res.get("ok"):
                res["executed_on"] = "RNA Engine (Streaming Direct)"
                print(json.dumps(res, ensure_ascii=False, indent=2))
                return
        res = send_single_message(args.send, node_id=args.node)
        print(json.dumps(res, ensure_ascii=False, indent=2))
        return

    if args.synthesize:
        if not args.local:
            res = stream_cloud_mission(args.synthesize, mode="synthesize", target_dir=args.target_dir)
            if res and res.get("ok"):
                res["executed_on"] = "RNA Engine (Streaming M2M)"
                print(json.dumps(res, ensure_ascii=False, indent=2))
                return
        res = synthesize_rna_project(args.synthesize, target_dir=args.target_dir)
        print(json.dumps(res, ensure_ascii=False, indent=2))
        return

    if args.relay:
        if not args.local:
            res = stream_cloud_mission(args.relay, mode="relay", target_dir=args.target_dir)
            if res and res.get("ok"):
                res["executed_on"] = "RNA Engine (Streaming M2M)"
                print(json.dumps(res, ensure_ascii=False, indent=2))
                return
        res = execute_cooperative_relay(args.relay, target_dir=args.target_dir)
        print(json.dumps(res, ensure_ascii=False, indent=2))
        return

    if args.status:
        if not args.local:
            cloud_tel = call_cloud_hq("telemetry")
            if cloud_tel and "kpis" in cloud_tel:
                k = cloud_tel["kpis"]
                print(
                    f"[RNA STATUS] Army={k['active_nodes_count']}/{k['scanned_nodes_count']} "
                    f"(SovereignTierS={k.get('preserved_apex_nodes_count', 0)}) | "
                    f"Mailbox={k.get('mailbox_messages_count', 0)} | TokensSaved={k['agent_tokens_saved']} | "
                    f"MySQL={cloud_tel.get('db_connected')}"
                )
                return
        tel = load_telemetry()
        save_telemetry(tel)
        k = tel["kpis"]
        print(
            f"[RNA STATUS] Army={k['active_nodes_count']}/{k['scanned_nodes_count']} | "
            f"Mailbox={k.get('mailbox_messages_count', 0)} | TokensSaved={k['agent_tokens_saved']}"
        )
        return

    parser.print_help()


if __name__ == "__main__":
    main()
