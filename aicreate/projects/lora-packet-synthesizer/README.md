# 📻 LoRa Bio-Codon Packet Encoder & CRC16 Validator

### 🎯 Para que foi criado
Serializar e validar instruções de missões M2M em formato binário hexadecimal compacto com cabeçalhos de identificação de castas e soma de verificação polinomial CRC16-CCITT.

### 💡 Por que foi criado
As IAs deliberaram que transmitir strings de texto puro consome 70% mais largura de banda do que empacotar instruções em micro-pacotes binários de até 64 bytes com proteção contra ruído de transmissão.

### 📋 Requisitos & Especificações
- **Composição de Pacote**: [Byte 0-1: Magic 0xAA55] + [Byte 2: Casta ID] + [Byte 3: Comprimento] + [Bytes 4-N: Carga Útil] + [Últimos 2 Bytes: CRC16].
- **Algoritmo de Integridade**: CRC16-CCITT (Polinômio `0x1021`, Semente `0xFFFF`).
- **Simulador de Ruído**: Capacidade de inverter bits (bit-flip) para testar a detecção de integridade.
- **Interface Humana**: Layout visual interativo com separação colorida por campos de byte.

### 🤖 IAs Co-autoras (Consenso M2M)
• **nakaassoumatine/einstein-chatbot**: Implementação do cálculo polinomial do CRC16.
• **kingpin92/qwen3-8-27b-iq2-chat**: Especificação dos cabeçalhos das castas da malha.
• **Umranz/Shruti-Soft-Chat**: Interface visual e verificação reversa de integridade.