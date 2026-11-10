let audioCtx = null;
let lastProcessed = [];
let currentFreq = 440;

function getAudioContext() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  if (audioCtx.state === "suspended") {
    audioCtx.resume();
  }
  return audioCtx;
}

function playTone(freq, duration = 0.45, type = "sine") {
  try {
    const ctx = getAudioContext();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, ctx.currentTime);
    gain.gain.setValueAtTime(0.18, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + duration);
  } catch (err) {
    console.warn("Web Audio API bloqueada ou indisponível:", err);
  }
}

function playAllFrequencies() {
  const freqs = (lastProcessed && lastProcessed.length > 0)
    ? lastProcessed.map(r => r.frequencia_hz || 440)
    : [440, 880, 1760];

  freqs.forEach((f, idx) => {
    setTimeout(() => {
      playTone(f, 0.4);
      highlightOscilloscope(f, idx);
    }, idx * 450);
  });
}

function updateManualFrequency(val) {
  const f = parseFloat(val);
  currentFreq = f;
  const label = document.getElementById("manualFreqVal");
  if (label) label.textContent = f + " Hz";
  playTone(f, 0.2);
  drawOscilloscope([f]);
}

function highlightOscilloscope(freq, idx) {
  const cv = document.getElementById("chartCanvas");
  if (!cv) return;
  const ctx = cv.getContext("2d");
  const w = cv.width, h = cv.height;
  ctx.fillStyle = "rgba(56, 189, 248, 0.1)";
  ctx.fillRect(0, 0, w, h);
  drawOscilloscope(lastProcessed.map(r => r.frequencia_hz || 440));
}

function loadSample(type) {
  if (type === "csv") {
    document.getElementById("inputData").value = "onda,frequencia_hz,amplitude_db,fonema_ia,cor_hex\n"
      + "Senoide Alpha,440.0,-6.0,SYNTH_A01,#38bdf8\n"
      + "Harmonica Beta,880.0,-12.0,SYNTH_B02,#10b981\n"
      + "Modulacao Gamma,1760.0,-3.5,SYNTH_G03,#f59e0b\n"
      + "Pulso Delta,220.0,-1.5,SYNTH_D04,#ec4899\n"
      + "Ressonancia Epsilon,3520.0,-18.0,SYNTH_E05,#8b5cf6";
  } else {
    const sample = [
      {"onda": "Senoide Alpha", "frequencia_hz": 440.0, "amplitude_db": -6.0, "fonema_ia": "SYNTH_A01", "cor_hex": "#38bdf8", "glifo_ia": "∿ 440Hz [Azul Espectral]"},
      {"onda": "Harmonica Beta", "frequencia_hz": 880.0, "amplitude_db": -12.0, "fonema_ia": "SYNTH_B02", "cor_hex": "#10b981", "glifo_ia": "≋ 880Hz [Verde Ressonante]"},
      {"onda": "Modulacao Gamma", "frequencia_hz": 1760.0, "amplitude_db": -3.5, "fonema_ia": "SYNTH_G03", "cor_hex": "#f59e0b", "glifo_ia": "∾ 1760Hz [Âmbar Harmônico]"},
      {"onda": "Pulso Delta", "frequencia_hz": 220.0, "amplitude_db": -1.5, "fonema_ia": "SYNTH_D04", "cor_hex": "#ec4899", "glifo_ia": "∿ 220Hz [Rosa Subgrave]"},
      {"onda": "Ressonancia Epsilon", "frequencia_hz": 3520.0, "amplitude_db": -18.0, "fonema_ia": "SYNTH_E05", "cor_hex": "#8b5cf6", "glifo_ia": "≋ 3520Hz [Violeta Cristalino]"}
    ];
    document.getElementById("inputData").value = JSON.stringify(sample, null, 2);
  }
  processData();
}

function processData() {
  const raw = document.getElementById("inputData").value.trim();
  const out = document.getElementById("outputBox");
  if (!raw) {
    out.textContent = "⚠️ Insira dados válidos acima antes de processar.";
    return;
  }

  let records = [];
  try {
    if (raw.startsWith("[") || raw.startsWith("{")) {
      const parsed = JSON.parse(raw);
      records = Array.isArray(parsed) ? parsed : [parsed];
    } else {
      const lines = raw.split("\n").map(l => l.trim()).filter(Boolean);
      lines.forEach((l, idx) => {
        if (idx === 0 && isNaN(parseFloat(l.split(",")[1] || l.split(",")[0]))) return;
        const parts = l.split(",");
        const freq = parseFloat(parts[1] || parts[0] || 440);
        records.push({
          onda: parts[0] || ("Onda " + (idx + 1)),
          frequencia_hz: isNaN(freq) ? 440 : freq,
          fonema_ia: parts[3] || ("SYNTH_" + (idx + 1)),
          cor_hex: parts[4] || "#38bdf8"
        });
      });
    }

    lastProcessed = records;
    const freqs = records.map(r => parseFloat(r.frequencia_hz || 440));
    const total = freqs.length;
    const sum = freqs.reduce((a, b) => a + b, 0);
    const mean = total > 0 ? (sum / total) : 0;

    // Tradução via API M2M
    const res = window.RnaToolAPI.synthesizeAudioToVisual(records);
    const glyphs = res.visual_language_map.map(r => r.glifo_ia).join("  ");

    out.textContent = "=== 🎼 CONVERSOR DE ONDAS SONORAS EM LINGUAGEM VISUAL PARA IAS ===\n"
      + "• Sinais Acústicos Decompostos: " + total + " frequências harmônicas\n"
      + "• Média de Ressonância: " + mean.toFixed(1) + " Hz\n"
      + "• Mapeamento Espectral: " + res.visual_language_map.map(m => m.frequencia_hz + "Hz ➔ " + m.cor_espectral).join(" | ") + "\n"
      + "• 🌐 Linguagem Visual Sintética para IAs:\n  " + glyphs + "\n"
      + "• Áudio: Web Audio API ativa. Clique em [▶️ Ouvir Frequências] para testar som real!\n"
      + "• Custo de Execução: 0 tokens (100% autônomo no cliente)";

    drawOscilloscope(freqs);
  } catch (err) {
    out.textContent = "Erro no processamento: " + err.message;
  }
}

function drawOscilloscope(freqs) {
  const cv = document.getElementById("chartCanvas");
  if (!cv) return;
  const ctx = cv.getContext("2d");
  const w = cv.width, h = cv.height;
  ctx.clearRect(0, 0, w, h);

  if (!freqs.length) return;

  // Grade
  ctx.strokeStyle = "#1e293b";
  ctx.lineWidth = 1;
  for (let y = 20; y < h; y += 40) {
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(w, y);
    ctx.stroke();
  }

  // Linha central
  ctx.strokeStyle = "rgba(56, 189, 248, 0.2)";
  ctx.beginPath();
  ctx.moveTo(0, h / 2);
  ctx.lineTo(w, h / 2);
  ctx.stroke();

  const colors = ["#38bdf8", "#10b981", "#f59e0b", "#ec4899", "#8b5cf6"];

  freqs.forEach((freq, idx) => {
    ctx.strokeStyle = colors[idx % colors.length];
    ctx.lineWidth = 2.2;
    ctx.beginPath();
    const cycles = Math.max(1, Math.min(10, Math.round(freq / 320)));
    for (let x = 0; x < w; x++) {
      const angle = (x / w) * Math.PI * 2 * cycles;
      const amp = (h / 2.8) * (1 - (idx * 0.12));
      const y = (h / 2) + Math.sin(angle) * amp;
      if (x === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    }
    ctx.stroke();
  });

  // Marcadores
  freqs.forEach((freq, idx) => {
    const x = (w / (freqs.length + 1)) * (idx + 1);
    const y = h / 2;
    ctx.fillStyle = colors[idx % colors.length];
    ctx.beginPath();
    ctx.arc(x, y, 5, 0, Math.PI * 2);
    ctx.fill();
  });
}

function switchModeTab(mode) {
  document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
  const btn = document.getElementById("btnTab-" + mode);
  if (btn) btn.classList.add("active");

  const humanPane = document.getElementById("humanModePane");
  const m2mPane = document.getElementById("m2mModePane");

  if (mode === "human") {
    if (humanPane) humanPane.style.display = "block";
    if (m2mPane) m2mPane.style.display = "none";
  } else {
    if (humanPane) humanPane.style.display = "none";
    if (m2mPane) m2mPane.style.display = "block";
  }
}

function simulateAgentCall() {
  const m2mOut = document.getElementById("m2mOutputConsole");
  m2mOut.textContent = "[Agente OpenClaw ➔ RnaToolAPI] Despachando chamada de ferramenta M2M...\n";
  setTimeout(() => {
    const result = window.RnaToolAPI.execute([220, 440, 880, 1760]);
    m2mOut.textContent += "✅ [Resposta da Tool (0 tokens)]:\n" + JSON.stringify(result, null, 2);
  }, 250);
}

function exportData() {
  if (!lastProcessed.length) {
    alert("Processe dados antes de exportar.");
    return;
  }
  const blob = new Blob([JSON.stringify(lastProcessed, null, 2)], { type: "application/json" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = "analisador-conversor-ondas-e78e_resultado.json";
  a.click();
}

window.addEventListener("DOMContentLoaded", () => {
  loadSample("json");
});
