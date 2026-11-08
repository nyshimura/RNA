---
name: rna
description: >-
  Motor de Síntese Distribuída e Economia Extrema de Tokens (RNA Bio-LoRa Mesh).
  Inspirado na molécula polimérica de fita simples (mRNA, tRNA, rRNA) que traduz o DNA e sintetiza proteínas.
  Ative esta skill SEMPRE que o usuário mencionar "RNA", "skill RNA", "modo LoRa", "economizar tokens",
  "processamento comunitário", ou pedir para criar/processar projetos simples ou complexos gastando o mínimo
  de tokens locais através das IAs públicas do Hugging Face.
---

# RNA — Bio-LoRa Distributed Synthesis & Token Preservation Skill (100% Local / Self-Hosted)

**RNA** une o acrônimo de rede neural adaptativa à biologia molecular da síntese de proteínas (**DNA → mRNA → tRNA → Ribossomo**).

O objetivo primordial desta skill é **preservar ao máximo os tokens do seu plano de IA**, delegando o processamento pesado para modelos e Spaces públicos no **Hugging Face** através de requisições diretas em paralelo.

---

## Como Executar na Sua Máquina (100% Local)

O motor principal reside em `lora_mesh.py` e executa localmente:

- **Status da Rede**:
  ```powershell
  python lora_mesh.py --status
  ```
- **Escanear IAs Públicas Online (`--scan`)**:
  ```powershell
  python lora_mesh.py --scan --limit 15
  ```
- **Recrutar Novas Máquinas Gratuitas (`--recruit`)**:
  ```powershell
  python lora_mesh.py --recruit 10
  ```
- **Envio Direto de Instrução (`--send`)**:
  ```powershell
  python lora_mesh.py --send "Sua instrução aqui"
  ```
- **Revezamento Cooperativo Multi-Hop (`--relay`)**:
  ```powershell
  python lora_mesh.py --relay "Descrição da tarefa" --target-dir "pasta/destino"
  ```
- **Síntese de Projeto Completo (`--synthesize`)**:
  ```powershell
  python lora_mesh.py --synthesize "Descreva o software/projeto" --target-dir "pasta/destino"
  ```
- **Definir Token Pessoal do Hugging Face (Opcional)**:
  ```powershell
  python lora_mesh.py --set-token "hf_seuTokenAqui"
  ```

---

## Dashboard Web Local

Abra seu navegador no servidor local:
- `http://localhost/rna/` (caso utilize XAMPP, Apache ou PHP embutido)

## Filosofia de Preservação de Tokens
1. **Zero Fluff**: Respostas diretas e código funcional sem enrolação.
2. **Cota Remota**: O processamento consome os ciclos e GPUs gratuitas dos containers no Hugging Face, preservando sua cota pessoal de tokens.
