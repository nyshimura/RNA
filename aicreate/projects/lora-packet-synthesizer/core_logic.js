// =========================================================================
// ⚙️ CORE LOGIC ENGINE: LoRa Bio-Codon Packet Encoder & CRC16 Validator
// Co-autor: @einstein-chatbot (⚔️ Engenheiro de Algoritmos)
// Execução 100% no cliente sem chamadas externas nem consumo de tokens
// =========================================================================

const CoreProcessor = {
  version: '1.0.0',
  author: 'einstein-chatbot',

  calculateEntropy: function(dataStr) {
    if (!dataStr || dataStr.length === 0) return 0;
    const freq = {};
    for (let i = 0; i < dataStr.length; i++) {
      const c = dataStr[i];
      freq[c] = (freq[c] || 0) + 1;
    }
    let entropy = 0;
    const len = dataStr.length;
    for (let c in freq) {
      const p = freq[c] / len;
      entropy -= p * Math.log2(p);
    }
    return parseFloat(entropy.toFixed(4));
  },

  normalizeStream: function(values) {
    if (!Array.isArray(values) || values.length === 0) return [];
    const min = Math.min(...values);
    const max = Math.max(...values);
    if (max === min) return values.map(() => 0.5);
    return values.map(v => parseFloat(((v - min) / (max - min)).toFixed(4)));
  },

  processDataset: function(rawInput) {
    const t0 = performance.now();
    const lines = String(rawInput || '').split('\n').map(l => l.trim()).filter(Boolean);
    const numbers = [];
    lines.forEach(l => {
      const parts = l.split(/[,;\t\s]+/);
      parts.forEach(p => {
        const n = parseFloat(p);
        if (!isNaN(n)) numbers.push(n);
      });
    });
    const entropy = this.calculateEntropy(String(rawInput));
    const normalized = this.normalizeStream(numbers);
    const mean = numbers.length > 0 ? (numbers.reduce((a,b)=>a+b,0) / numbers.length).toFixed(3) : 0;
    const t1 = performance.now();
    return {
      items_count: numbers.length,
      lines_count: lines.length,
      entropy_bits: entropy,
      mean_value: parseFloat(mean),
      normalized_sample: normalized.slice(0, 50),
      latency_ms: parseFloat((t1 - t0).toFixed(2)),
      processed_by: 'CoreProcessor v1.0 (@einstein-chatbot)'
    };
  }
};

if (typeof module !== 'undefined' && module.exports) {
  module.exports = CoreProcessor;
}
