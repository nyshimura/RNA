# 🧬 RNA — Bio-LoRa Distributed Synthesis & Token Preservation Engine

Motor autônomo e distribuído para **economia extrema de tokens** locais em assistentes de código e LLMs. Inspirado no fluxo biológico da transcrição molecular (**DNA ➔ mRNA ➔ tRNA ➔ Ribossomo**) e na estigmergia fúngica.

O RNA conecta seu agente de IA a um exército de modelos e Spaces públicos no **Hugging Face**, executando tarefas pesadas, pesquisas web em tempo real e sínteses de software a **custo zero** de tokens da sua cota local.

---

## 🚀 Como Funciona

1. **DNA (Agente / Comando)**: Formula a instrução de forma concisa.
2. **mRNA (Estrutura de Pastas)**: O motor particiona a tarefa em códons e estados transparentes no sistema de arquivos.
3. **tRNA (Transportador)**: Envia requisições concorrentes diretamente para nós de IA no Hugging Face (Gradio Spaces e LLMs com ZeroGPU).
4. **Ribossomo (Síntese)**: Recebe os blocos de código gerados remotamente e monta os arquivos prontos no seu computador.

---

## 📦 Como Instalar e Rodar na Sua Máquina (100% Local)

### 1. Clonar o Repositório
```bash
git clone https://github.com/nyshimura/RNA.git
cd RNA
```

### 2. Requisitos
- **Python 3.10+** (sem dependências externas pesadas, utiliza biblioteca padrão).
- **PHP 8.0+** com extensão cURL e PDO (ex: XAMPP, Laragon ou Apache/Nginx local).
- **MySQL / MariaDB** (opcional, para persistência avançada de nós e histórico de mensagens).

### 3. Configuração Opcional do Banco de Dados
Caso queira utilizar o MySQL localmente com o XAMPP:
```bash
cp core_php/db_config.example.php core_php/db_config.php
```
Edite `core_php/db_config.php` com as credenciais do seu banco local.

---

## ⚡ Comandos Principais (CLI)

Todos os comandos são executados a partir do arquivo raiz `lora_mesh.py`:

```bash
# Checar status das IAs online e tokens poupados
python lora_mesh.py --status

# Escanear IAs públicas ativas no catálogo do Hugging Face
python lora_mesh.py --scan --limit 15

# Recrutar novas máquinas públicas para sua malha local
python lora_mesh.py --recruit 10

# Enviar instrução direta para um modelo remoto
python lora_mesh.py --send "Explique como funciona um algoritmo de ordenação rápida"

# Revezamento cooperativo de 2 IAs (Hop 1 analisa, Hop 2 implementa)
python lora_mesh.py --relay "Crie uma API RESTful em Python" --target-dir "./meu_projeto"

# Síntese multi-códon em paralelo para projetos completos
python lora_mesh.py --synthesize "Construa um analisador de logs em Go" --target-dir "./saida"
```

---

## 🖥️ Painel Web Local

Se você colocar a pasta dentro do `htdocs` do seu servidor Apache/XAMPP, você pode acessar o painel interativo em:

👉 **`http://localhost/rna/`**

O painel inclui:
- 🚀 **Despachante de Missões**: Envio de tarefas em tempo real com barra de progresso SSE.
- 🔋 **Monitor de Cotas**: Acompanhamento do consumo de cada máquina no Hugging Face.
- 📥 **Caixa de Correio das Máquinas**: Histórico completo de repasses entre IAs e sínteses prontas com tradução automática para Português.

---

## 🤖 Como Instalar como Skill no Antigravity / Gemini / Claude Code

Copie a pasta `.agents/skills/rna` para o diretório de skills do seu agente:

```bash
# Global no Antigravity / Gemini CLI:
cp -r .agents/skills/rna ~/.gemini/config/skills/

# No repositório atual:
cp -r .agents/skills/rna ./.agents/skills/
```

Quando a skill estiver ativa, basta dizer ao seu assistente de código:
> *"Use o /rna para criar este script economizando meus tokens"*

---

## 🛡️ Licença
Distribuído sob a licença MIT. 100% de código aberto, livre e autônomo.
