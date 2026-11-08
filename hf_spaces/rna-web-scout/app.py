"""
RNA Web Scout Space — Nó Batedor de Reconhecimento da Rede RNA Bio-LoRa
Hospedado no Hugging Face (Gradio 6 + Multi-Source Search: DDG Brasil br-pt, Notícias, Wikipédia, GNews)
"""

import os
os.environ["GRADIO_SSR_MODE"] = "False"

import spaces
import gradio as gr
from duckduckgo_search import DDGS
import urllib.request
import urllib.parse
import json
import xml.etree.ElementTree as ET

def clean_query(raw: str) -> str:
    stop = [
        'quero', 'saber', 'quem', 'qual', 'quais', 'como', 'onde', 'quando',
        'por', 'que', 'para', 'uma', 'uns', 'umas', 'com', 'dos', 'das',
        'nos', 'nas', 'sobre', 'me', 'fale', 'diga', 'preciso', 'favor', 'explique'
    ]
    words = [w for w in raw.split() if w.lower().strip(".,;:?!") not in stop and len(w) > 2]
    return " ".join(words[:6]) if words else raw

def search_wikipedia_pt(query: str) -> list:
    try:
        url = "https://pt.wikipedia.org/w/api.php?action=query&list=search&srsearch=" + urllib.parse.quote(query) + "&utf8=1&format=json&srlimit=2"
        req = urllib.request.Request(url, headers={'User-Agent': 'RNA-WebScout/3.0 (br; pt)'})
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = json.loads(resp.read().decode('utf-8'))
        items = data.get('query', {}).get('search', [])
        results = []
        for it in items:
            title = it.get('title', '')
            ext_url = f"https://pt.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&titles=" + urllib.parse.quote(title) + "&format=json"
            ext_req = urllib.request.Request(ext_url, headers={'User-Agent': 'RNA-WebScout/3.0 (br; pt)'})
            with urllib.request.urlopen(ext_req, timeout=5) as ext_resp:
                ext_data = json.loads(ext_resp.read().decode('utf-8'))
            pages = ext_data.get('query', {}).get('pages', {})
            for p in pages.values():
                extract = p.get('extract', '')
                if extract:
                    clean_ext = " ".join(extract.split()[:75]) + "..."
                    results.append({
                        'title': f"[Wikipédia Brasil] {title}",
                        'href': f"https://pt.wikipedia.org/wiki/{urllib.parse.quote(title.replace(' ', '_'))}",
                        'body': clean_ext
                    })
                    break
        return results
    except Exception:
        return []

def search_gnews_rss(query: str) -> list:
    try:
        url = "https://news.google.com/rss/search?q=" + urllib.parse.quote(query) + "&hl=pt-BR&gl=BR&ceid=BR:pt-419"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'})
        with urllib.request.urlopen(req, timeout=6) as resp:
            xml_data = resp.read()
        root = ET.fromstring(xml_data)
        results = []
        for item in root.findall('.//item')[:3]:
            t = item.find('title')
            l = item.find('link')
            d = item.find('pubDate')
            title = t.text if t is not None else ''
            link = l.text if l is not None else ''
            date = d.text if d is not None else ''
            if title:
                results.append({
                    'title': f"[Imprensa / Notícias BR ({date[:16]})] {title}",
                    'href': link,
                    'body': title
                })
        return results
    except Exception:
        return []

@spaces.GPU(duration=15)
def search_web(query, max_results=5):
    if not query or not str(query).strip():
        return "Nenhuma consulta informada."
    
    q_str = str(query).strip()
    cleaned_q = clean_query(q_str)
    
    gathered = []
    
    # 1. DuckDuckGo Notícias Brasil (br-pt) com fontes de jornalismo
    try:
        with DDGS() as ddgs:
            news_res = list(ddgs.news(keywords=cleaned_q, region="br-pt", timelimit="y", max_results=4))
            for r in news_res:
                t = r.get("title", "")
                link = r.get("url", "")
                body = r.get("body", "")
                date = r.get("date", "")
                src = r.get("source", "Notícia")
                if t:
                    gathered.append({
                        'title': f"[{src} • {date[:10]}] {t}",
                        'href': link,
                        'body': body or t
                    })
    except Exception:
        pass
    
    # 2. DuckDuckGo Busca Web Brasil (br-pt)
    try:
        with DDGS() as ddgs:
            text_res = list(ddgs.text(keywords=cleaned_q, region="br-pt", max_results=4))
            for r in text_res:
                t = r.get("title", "")
                link = r.get("href", "")
                body = r.get("body", "")
                # Evita duplicados e descarta links estrangeiros irrelevantes
                if t and not any(x['href'] == link for x in gathered):
                    gathered.append({
                        'title': t,
                        'href': link,
                        'body': body
                    })
    except Exception:
        pass
    
    # 3. Google News RSS Brasil (imprensa nacional em tempo real)
    if len(gathered) < 3:
        gnews = search_gnews_rss(cleaned_q)
        for g in gnews:
            if not any(x['title'] == g['title'] for x in gathered):
                gathered.append(g)
                
    # 4. Wikipédia Brasil (dados constitucionais, artigos oficiais e histórico)
    wiki = search_wikipedia_pt(cleaned_q)
    for w in wiki:
        gathered.append(w)
        
    if not gathered:
        return f"Nenhum resultado relevante encontrado para: {q_str}"
        
    limit = int(max_results) if max_results else 5
    final_list = gathered[:limit]
    
    output = [f"### 🌐 Dossiê de Reconhecimento Web RNA Brasil ({len(final_list)} fontes):\n"]
    for idx, r in enumerate(final_list, 1):
        output.append(f"{idx}. **[{r['title']}]({r['href']})**\n   {r['body']}\n")
        
    return "\n".join(output)

with gr.Blocks(title="RNA Web Scout — Nó Batedor") as demo:
    gr.Markdown("# 🦅 RNA Web Scout — Reconhecimento da Rede RNA Bio-LoRa")
    gr.Markdown("Nó Batedor de Alta Fidelidade (DuckDuckGo Brasil `br-pt` + Notícias em Tempo Real + Wikipédia).")
    
    with gr.Row():
        query_input = gr.Textbox(label="Instrução de Busca", placeholder="ex: eleicao presidencial brasil 2026 candidatos datas", scale=4)
        limit_slider = gr.Slider(minimum=1, maximum=10, value=5, step=1, label="Limite de Fontes", scale=1)
    
    search_btn = gr.Button("🔍 Sondar Internet", variant="primary")
    output_text = gr.Markdown(label="Relatório Factual do Batedor")
    
    search_btn.click(fn=search_web, inputs=[query_input, limit_slider], outputs=output_text)
    query_input.submit(fn=search_web, inputs=[query_input, limit_slider], outputs=output_text)

if __name__ == "__main__":
    demo.launch()
