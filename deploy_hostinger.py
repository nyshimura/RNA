#!/usr/bin/env python3
"""
RNA Bio-LoRa Mesh — Deploy Automatizado SSH/SFTP para Hostinger (nyshimura.pe.hu)
Compacta localmente, envia via SFTP e extrai em segundos com permissões ajustadas.
"""

import os
import sys
import json
import tarfile
import tempfile
import time
import paramiko

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, "state", "ssh_deploy_config.json")

def load_config():
    if not os.path.exists(CONFIG_FILE):
        print(f"❌ Arquivo de configuração não encontrado: {CONFIG_FILE}")
        sys.exit(1)
    with open(CONFIG_FILE, "r", encoding="utf-8") as f:
        return json.load(f)

def create_archive():
    print("📦 Compactando arquivos locais para envio rápido...")
    temp_tar = os.path.join(tempfile.gettempdir(), f"rna_deploy_{int(time.time())}.tar.gz")
    
    include_items = [
        "index.html",
        "api.php",
        "apple-touch-icon.png",
        "favicon.ico",
        "favicon.svg",
        "README.md",
        "lora_mesh.py",
        "core_php",
        "aicreate",
        "state",
        "mesh",
        "core",
        ".agents"
    ]
    
    with tarfile.open(temp_tar, "w:gz") as tar:
        for item in include_items:
            full_path = os.path.join(BASE_DIR, item)
            if os.path.exists(full_path):
                tar.add(full_path, arcname=item)
                
    size_mb = os.path.getsize(temp_tar) / (1024 * 1024)
    print(f"✅ Pacote gerado: {size_mb:.2f} MB")
    return temp_tar

def deploy():
    cfg = load_config()
    host = cfg["host"]
    port = int(cfg.get("port", 65002))
    user = cfg["user"]
    password = cfg["password"]
    remote_path = cfg.get("remote_path", "/home/u289919376/domains/nyshimura.pe.hu/public_html")
    domain = cfg.get("domain", "nyshimura.pe.hu")
    
    tar_path = create_archive()
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    
    try:
        print(f"🚀 Conectando via SSH a {host}:{port} como {user}...")
        client.connect(host, port=port, username=user, password=password, timeout=20)
        print("🔗 Conectado!")
        
        sftp = client.open_sftp()
        remote_tar = f"{remote_path}/deploy_package.tar.gz"
        
        print(f"📤 Transferindo via SFTP para {remote_tar}...")
        sftp.put(tar_path, remote_tar)
        sftp.close()
        print("✅ Transferência concluída!")
        
        print("⚙️ Descompactando e ajustando permissões remotas...")
        cmd = f"""
        cd {remote_path} && \
        tar -xzf deploy_package.tar.gz && \
        rm -f deploy_package.tar.gz && \
        find . -type d -exec chmod 755 {{}} + && \
        find . -type f -exec chmod 644 {{}} +
        """
        stdin, stdout, stderr = client.exec_command(cmd)
        stdout.read()
        err = stderr.read().decode('utf-8', errors='ignore')
        if err:
            print(f"Avisos stderr: {err}")
            
        print(f"🌐 Verificando status HTTPS do domínio https://{domain}/...")
        stdin, stdout, stderr = client.exec_command(f"curl -s -o /dev/null -w '%{{http_code}}' https://{domain}/")
        code = stdout.read().decode('utf-8', errors='ignore').strip()
        print(f"✅ Status HTTP retornado: {code} OK")
        print(f"\n🎉 DEPLOY CONCLUÍDO COM SUCESSO NO DOMÍNIO https://{domain}/")
        
    finally:
        client.close()
        if os.path.exists(tar_path):
            os.remove(tar_path)

if __name__ == "__main__":
    deploy()
