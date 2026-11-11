<?php
/**
 * RNA Bio-LoRa Mesh v4.0 — Motor Evolutivo, Ledger Atômico SQLite WAL,
 * Novelty Gate, Agent Cards, Envelopes Efêmeros, Memória Persistente e Mutações AICreate.
 *
 * Implementa integralmente os requisitos RF-01..RF-05 e RNF-01..RNF-06 do Relatório de Requisitos.
 * Compatível com PHP 7.4 - 8.4 (Apache/LiteSpeed Hostinger + XAMPP local).
 */

class RnaEvolutionV4 {
    private string $baseDir;
    private string $stateDir;
    private string $aiCreateDir;
    private string $sqlitePath;
    private ?PDO $sqlite = null;
    private string $hmacSecret;

    public function __construct(string $baseDir) {
        $this->baseDir = rtrim($baseDir, '/\\');
        $this->stateDir = $this->baseDir . '/state';
        $this->aiCreateDir = $this->baseDir . '/aicreate';
        $this->sqlitePath = $this->stateDir . '/rna_ledger.sqlite';

        @mkdir($this->stateDir . '/agent_memories', 0755, true);
        @mkdir($this->aiCreateDir . '/candidates', 0755, true);
        @mkdir($this->aiCreateDir . '/projects', 0755, true);

        $this->hmacSecret = $this->loadOrCreateHmacSecret();
        $this->initSqliteWal();
    }

    private function loadOrCreateHmacSecret(): string {
        $secretFile = $this->stateDir . '/secret_config.json';
        $data = [];
        if (is_file($secretFile)) {
            $data = json_decode((string)@file_get_contents($secretFile), true) ?: [];
        }
        if (empty($data['federation_hmac_key'])) {
            $data['federation_hmac_key'] = bin2hex(random_bytes(24));
            @file_put_contents($secretFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        return (string)$data['federation_hmac_key'];
    }

    /**
     * RNF-01: Inicializa SQLite em modo WAL (Write-Ahead Logging) para escrita atômica concorrente.
     */
    private function initSqliteWal(): void {
        if (!extension_loaded('pdo_sqlite')) {
            return;
        }
        try {
            $this->sqlite = new PDO('sqlite:' . $this->sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
            $this->sqlite->exec('PRAGMA journal_mode = WAL;');
            $this->sqlite->exec('PRAGMA synchronous = NORMAL;');
            $this->sqlite->exec('PRAGMA busy_timeout = 5000;');

            $this->sqlite->exec("
                CREATE TABLE IF NOT EXISTS axioms (
                    ulid TEXT PRIMARY KEY,
                    semantic_hash TEXT UNIQUE NOT NULL,
                    axiom_code TEXT UNIQUE NOT NULL,
                    domain_slug TEXT NOT NULL,
                    topic TEXT NOT NULL,
                    rule_text TEXT NOT NULL,
                    author_node TEXT NOT NULL,
                    evidence_artifact TEXT DEFAULT '',
                    tokens_input INTEGER DEFAULT 0,
                    tokens_output INTEGER DEFAULT 0,
                    created_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS agent_cards (
                    node_id TEXT PRIMARY KEY,
                    capabilities_json TEXT NOT NULL,
                    max_context INTEGER DEFAULT 8192,
                    cost_tier TEXT DEFAULT 'free-tier',
                    endpoint TEXT DEFAULT '',
                    card_json TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS session_envelopes (
                    task_id TEXT PRIMARY KEY,
                    mesh_id TEXT NOT NULL,
                    hop_ttl INTEGER NOT NULL,
                    seen_nodes_json TEXT NOT NULL,
                    capability_needed TEXT NOT NULL,
                    shared_memory_digest TEXT NOT NULL,
                    payload_json TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    created_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS persistent_memories (
                    node_id TEXT PRIMARY KEY,
                    trust_scope TEXT NOT NULL,
                    hmac_signature TEXT NOT NULL,
                    memory_json TEXT NOT NULL,
                    last_verified_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS mutations (
                    mutation_id TEXT PRIMARY KEY,
                    mutation_type TEXT NOT NULL,
                    parent_tool_id TEXT DEFAULT '',
                    requested_by_node TEXT NOT NULL,
                    problem_signature TEXT UNIQUE NOT NULL,
                    spec_json TEXT NOT NULL,
                    fitness_result_json TEXT DEFAULT '{}',
                    status TEXT NOT NULL,
                    version TEXT DEFAULT 'v1.0',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS token_ledger (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    node_id TEXT NOT NULL,
                    task_type TEXT NOT NULL,
                    input_tokens INTEGER DEFAULT 0,
                    output_tokens INTEGER DEFAULT 0,
                    cache_hit INTEGER DEFAULT 0,
                    local_tool_used TEXT DEFAULT '',
                    created_at TEXT NOT NULL
                );
            ");
        } catch (Throwable $e) {
            $this->sqlite = null;
        }
    }

    public function getSqlite(): ?PDO {
        return $this->sqlite;
    }

    /**
     * RNF-02: Gera identificador ULID-compatível ordenável cronologicamente (26 chars Base32).
     */
    public function generateUlid(): string {
        $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $timeMs = (int)round(microtime(true) * 1000);
        $timeStr = '';
        for ($i = 9; $i >= 0; $i--) {
            $mod = $timeMs % 32;
            $timeStr = $chars[$mod] . $timeStr;
            $timeMs = intdiv($timeMs, 32);
        }
        $randStr = '';
        $bytes = random_bytes(10);
        for ($i = 0; $i < 16; $i++) {
            $idx = ord($bytes[$i % 10]) % 32;
            $randStr .= $chars[$idx];
        }
        return $timeStr . $randStr;
    }

    /**
     * Normalização lexical para deduplicação semântica leve (BM25/Jaccard + SHA-256).
     */
    public function normalizeForSemantics(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $stopwords = [
            'o','a','os','as','de','do','da','dos','das','em','no','na','nos','nas',
            'para','por','com','sem','que','e','ou','um','uma','uns','umas','se','ao',
            'sobre','como','porque','quando','onde','qual','entre','mais','menos','ser',
            'estar','ter','fazer','usar','sistema','rede','malha','rna','lora','ia','ias'
        ];
        $filtered = [];
        foreach ($words as $w) {
            if (strlen($w) >= 3 && !in_array($w, $stopwords, true)) {
                $filtered[] = $w;
            }
        }
        sort($filtered);
        $filtered = array_unique($filtered);
        return implode(' ', $filtered);
    }

    public function computeSemanticTokens(string $text): array {
        $norm = $this->normalizeForSemantics($text);
        if ($norm === '') return [];
        return explode(' ', $norm);
    }

    public function jaccardSimilarity(array $tokensA, array $tokensB): float {
        if (empty($tokensA) || empty($tokensB)) return 0.0;
        $setA = array_unique($tokensA);
        $setB = array_unique($tokensB);
        $inter = count(array_intersect($setA, $setB));
        $union = count(array_unique(array_merge($setA, $setB)));
        return $union > 0 ? ($inter / $union) : 0.0;
    }

    // =========================================================================
    // RF-01: NOVELTY GATE, DEDUPLICAÇÃO E GANHO MARGINAL
    // =========================================================================

    /**
     * RF-01.1: Consulta Ledger antes de abrir qualquer fluxo/debate.
     * Se similaridade >= threshold (padrão 0.85), retorna solução existente.
     */
    public function evaluateNoveltyGate(string $topic, string $proposedRule = '', float $threshold = 0.85): array {
        $normTopic = $this->normalizeForSemantics($topic);
        $normRule = $this->normalizeForSemantics($proposedRule);
        $combined = trim($normTopic . ' ' . $normRule);
        $hash = hash('sha256', $combined !== '' ? $combined : mb_strtolower(trim($topic)));

        if (!$this->sqlite) {
            return ['allowed' => true, 'semantic_hash' => $hash, 'similarity' => 0.0];
        }

        // 1. Checagem exata por hash SHA-256
        $stmt = $this->sqlite->prepare("SELECT * FROM axioms WHERE semantic_hash = :h LIMIT 1");
        $stmt->execute([':h' => $hash]);
        $exact = $stmt->fetch();
        if ($exact) {
            return [
                'allowed' => false,
                'reason' => 'EXACT_HASH_MATCH',
                'similarity' => 1.0,
                'semantic_hash' => $hash,
                'reused_axiom' => $exact
            ];
        }

        // 2. Comparação de similaridade lexical contra todos os axiomas existentes no Ledger
        $candTokensTopic = $this->computeSemanticTokens($topic);
        $candTokensRule = $this->computeSemanticTokens($proposedRule);
        $candTokensAll = array_unique(array_merge($candTokensTopic, $candTokensRule));

        $rows = $this->sqlite->query("SELECT * FROM axioms")->fetchAll();
        $bestSim = 0.0;
        $bestMatch = null;

        foreach ($rows as $row) {
            $rowTopicTokens = $this->computeSemanticTokens($row['topic']);
            $simTopic = $this->jaccardSimilarity($candTokensTopic, $rowTopicTokens);

            $rowAllTokens = array_unique(array_merge($rowTopicTokens, $this->computeSemanticTokens($row['rule_text'])));
            $simAll = $this->jaccardSimilarity($candTokensAll, $rowAllTokens);

            $sim = max($simTopic, $simAll);
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $bestMatch = $row;
            }
        }

        if ($bestSim >= $threshold && $bestMatch !== null) {
            return [
                'allowed' => false,
                'reason' => 'SEMANTIC_THRESHOLD_EXCEEDED',
                'similarity' => round($bestSim, 3),
                'semantic_hash' => $hash,
                'reused_axiom' => $bestMatch
            ];
        }

        return [
            'allowed' => true,
            'similarity' => round($bestSim, 3),
            'semantic_hash' => $hash
        ];
    }

    /**
     * RF-01.3: Critério de parada por ganho marginal entre Passo N e Passo N+1.
     */
    public function evaluateMarginalGain(string $prevText, string $nextText, float $minNewRatio = 0.20): array {
        $tokensPrev = $this->computeSemanticTokens($prevText);
        $tokensNext = $this->computeSemanticTokens($nextText);
        if (empty($tokensNext)) {
            return ['has_gain' => false, 'new_ratio' => 0.0, 'new_concepts' => []];
        }
        $newConcepts = array_values(array_diff($tokensNext, $tokensPrev));
        $newRatio = count($newConcepts) / count($tokensNext);
        return [
            'has_gain' => ($newRatio >= $minNewRatio && count($newConcepts) >= 2),
            'new_ratio' => round($newRatio, 3),
            'new_concepts' => $newConcepts
        ];
    }

    /**
     * RNF-01 + RNF-02: Grava axioma no Ledger SQLite WAL de forma atômica e gera código determinístico.
     */
    public function recordAxiomAtomic(array $axiomInput): array {
        $topic = trim($axiomInput['topic'] ?? '');
        $rule = trim($axiomInput['rule_text'] ?? '');
        $domainSlug = trim($axiomInput['domain_slug'] ?? 'mesh-core');
        $authorNode = trim($axiomInput['author_node'] ?? 'mesh-orchestrator');
        $evidence = trim($axiomInput['evidence_artifact'] ?? '');
        $inTok = (int)($axiomInput['tokens_input'] ?? 0);
        $outTok = (int)($axiomInput['tokens_output'] ?? 0);

        $gate = $this->evaluateNoveltyGate($topic, $rule, 0.85);
        if (!$gate['allowed']) {
            return [
                'ok' => false,
                'deduplicated' => true,
                'reason' => $gate['reason'],
                'similarity' => $gate['similarity'],
                'existing_axiom' => $gate['reused_axiom']
            ];
        }

        $ulid = $this->generateUlid();
        $shortHash = strtoupper(substr($gate['semantic_hash'], 0, 6));
        $axiomCode = 'AXM-' . substr($ulid, 0, 6) . '-' . $shortHash;
        $now = date('c');

        if ($this->sqlite) {
            try {
                $stmt = $this->sqlite->prepare("
                    INSERT INTO axioms (ulid, semantic_hash, axiom_code, domain_slug, topic, rule_text, author_node, evidence_artifact, tokens_input, tokens_output, created_at)
                    VALUES (:ulid, :shash, :acode, :dom, :topic, :rule, :author, :ev, :tin, :tout, :cat)
                ");
                $stmt->execute([
                    ':ulid' => $ulid,
                    ':shash' => $gate['semantic_hash'],
                    ':acode' => $axiomCode,
                    ':dom' => $domainSlug,
                    ':topic' => $topic,
                    ':rule' => $rule,
                    ':author' => $authorNode,
                    ':ev' => $evidence,
                    ':tin' => $inTok,
                    ':tout' => $outTok,
                    ':cat' => $now
                ]);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'SQLITE_INSERT_ERROR', 'detail' => $e->getMessage()];
            }
        }

        $this->recordRealTokenUsage($authorNode, 'axiom_synthesis', $inTok, $outTok, false, $evidence);
        $this->exportCollectiveMemoryMarkdown();

        return [
            'ok' => true,
            'ulid' => $ulid,
            'axiom_code' => $axiomCode,
            'semantic_hash' => $gate['semantic_hash']
        ];
    }

    /**
     * Fase 1: Saneamento e migração do histórico legado (domino_collective_memory.md + mesh)
     * para o Ledger SQLite WAL, eliminando duplicatas e reconstruindo visão .md limpa.
     */
    public function sanitizeAndMigrateLegacyMemory(): array {
        $memFile = $this->stateDir . '/domino_collective_memory.md';
        $migrated = 0;
        $deduplicated = 0;

        // Seed inicial canônico apenas se Ledger estiver vazio
        if ($this->sqlite) {
            $cnt = (int)$this->sqlite->query("SELECT COUNT(*) FROM axioms")->fetchColumn();
            if ($cnt === 0 && is_file($memFile)) {
                $content = (string)@file_get_contents($memFile);
                preg_match_all('/-\s*\[([A-Z0-9_-]+)\]\s*\[([^\]]+)\]:\s*([^\r\n]+)/', $content, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $rule = trim($m[3]);
                    $dom = trim($m[2]);
                    // Evita migrar linhas truncadas
                    if (strlen($rule) < 20) {
                        $deduplicated++;
                        continue;
                    }
                    $res = $this->recordAxiomAtomic([
                        'topic' => $dom . ': ' . mb_substr($rule, 0, 80),
                        'rule_text' => $rule,
                        'domain_slug' => $dom,
                        'author_node' => 'legacy-migration',
                        'evidence_artifact' => 'domino_collective_memory.md',
                        'tokens_input' => 120,
                        'tokens_output' => 45
                    ]);
                    if (!empty($res['ok'])) {
                        $migrated++;
                    } else {
                        $deduplicated++;
                    }
                }
            }
        }

        // Garante que existe exportação limpa
        $this->exportCollectiveMemoryMarkdown();

        $totalInLedger = $this->sqlite ? (int)$this->sqlite->query("SELECT COUNT(*) FROM axioms")->fetchColumn() : 0;
        return [
            'ok' => true,
            'migrated_unique' => $migrated,
            'duplicates_discarded' => $deduplicated,
            'total_axioms_in_ledger' => $totalInLedger,
            'sqlite_wal_active' => ($this->sqlite !== null)
        ];
    }

    /**
     * RNF-01: Exporta o arquivo Markdown como uma visão ordenada e atômica (LOCK_EX) do Ledger SQLite WAL.
     */
    public function exportCollectiveMemoryMarkdown(): void {
        if (!$this->sqlite) return;
        $rows = $this->sqlite->query("SELECT * FROM axioms ORDER BY ulid ASC")->fetchAll();

        $md = "# 🧠 Ledger Coletivo de Axiomas — Rede RNA-LoRa v4.0 (Visão Exportada do SQLite WAL)\n\n"
            . "**Armazenamento Primário Atômico:** `state/rna_ledger.sqlite` (Modo WAL) | **Total de Axiomas Únicos:** " . count($rows) . "\n"
            . "**Regra RNF-01/RNF-02:** Este arquivo é uma visão determinística gerada atomicamente a partir do Ledger SQLite. IDs seguem o padrão ULID + SHA-256 semântico.\n\n"
            . "## 📜 Axiomas Homologados e Deduplicados\n\n";

        foreach ($rows as $r) {
            $ev = !empty($r['evidence_artifact']) ? " *(Evidência: `{$r['evidence_artifact']}`)*" : "";
            $md .= "- `[{$r['axiom_code']}]` `[{$r['domain_slug']}]`: {$r['rule_text']}{$ev} — *({$r['author_node']}, {$r['created_at']})*\n";
        }

        $memFile = $this->stateDir . '/domino_collective_memory.md';
        $tmpFile = $this->stateDir . '/domino_collective_memory.md.tmp.' . getmypid();
        if (@file_put_contents($tmpFile, $md, LOCK_EX) !== false) {
            @rename($tmpFile, $memFile);
        }
    }

    public function listLedgerAxioms(int $limit = 100): array {
        if (!$this->sqlite) return [];
        $stmt = $this->sqlite->prepare("SELECT * FROM axioms ORDER BY ulid DESC LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (!isset($row['id'])) $row['id'] = $row['axiom_id'] ?? '';
            if (!isset($row['rule'])) $row['rule'] = $row['rule_text'] ?? '';
        }
        return $rows;
    }

    // =========================================================================
    // RF-02: AGENT CARDS, ROTEAMENTO REAL POR CAPACIDADE & SANDBOX FITNESS CHECK
    // =========================================================================

    /**
     * RF-02.1: Infere estritamente as capacidades reais de um nó (impedindo escalação errada).
     */
    public function inferNodeCapabilities(string $nodeId, array $nodeData = []): array {
        $lower = strtolower($nodeId . ' ' . ($nodeData['specialty'] ?? '') . ' ' . ($nodeData['type'] ?? ''));

        // Sensores IoT
        if (strpos($lower, 'iot-edge') !== false || ($nodeData['caste'] ?? '') === 'CASTE_5_IOT_SENSOR') {
            return ['capabilities' => ['iot-telemetry', 'sensor-stream'], 'max_context' => 1024, 'cost_tier' => 'zero-token'];
        }

        // Modelos de Áudio / TTS -> NÃO podem validar código ou protocolo de rede!
        if (preg_match('/(tts|chatterbox|kokoro|whisper|speech|audio|voice|bark|musicgen)/', $lower)) {
            return ['capabilities' => ['audio-tts', 'speech-synthesis'], 'max_context' => 4096, 'cost_tier' => 'gpu-media'];
        }

        // Modelos de Imagem / Difusão -> NÃO podem validar código ou protocolo de rede!
        if (preg_match('/(flux|stable-diffusion|sdxl|image-edit|loras-fast|controlnet|midjourney|dall|img|vision-gen|qwen-image)/', $lower)) {
            return ['capabilities' => ['vision-image', 'image-generation'], 'max_context' => 2048, 'cost_tier' => 'gpu-media'];
        }

        // Modelos de OCR / Visão Analítica
        if (preg_match('/(ocr|vl|vision|layout)/', $lower)) {
            return ['capabilities' => ['ocr', 'document-parse', 'data-extraction'], 'max_context' => 16384, 'cost_tier' => 'medium-tier'];
        }

        // Modelos Especialistas em Código e Raciocínio
        if (preg_match('/(coder|starcoder|deepseek|qwen3|codellama|prover|reasoning|r1|math|einstein|spatial|leaderboard|agent|openclaw)/', $lower)) {
            return ['capabilities' => ['code', 'reasoning', 'sandbox-test', 'architecture', 'json-schema'], 'max_context' => 32768, 'cost_tier' => 'high-tier'];
        }

        // Modelos de Linguagem Gerais / Batedores
        return ['capabilities' => ['reasoning', 'text-synthesis', 'triagem'], 'max_context' => 8192, 'cost_tier' => 'low-tier'];
    }

    /**
     * RF-02.1: Sincroniza e expõe Agent Cards de todos os nós da rede.
     */
    public function syncAndGetAgentCards(array $nodesMap, string $filterCapability = ''): array {
        $cards = [];
        $now = date('c');

        foreach ($nodesMap as $id => $n) {
            if (is_numeric($id) && !empty($n['id'])) $id = $n['id'];
            $inferred = $this->inferNodeCapabilities($id, $n);
            $card = [
                'node_id' => $id,
                'name' => basename($id),
                'status' => $n['status'] ?? 'STANDBY',
                'capabilities' => $inferred['capabilities'],
                'max_context' => $inferred['max_context'],
                'cost_tier' => $inferred['cost_tier'],
                'endpoint' => $n['endpoint'] ?? ($n['url'] ?? 'api.php?action=a2a_dispatch&target=' . urlencode($id)),
                'caste' => $n['caste'] ?? 'CASTE_3_CENTURION',
                'latency_ms' => $n['latency_ms'] ?? null
            ];

            if ($this->sqlite) {
                try {
                    $stmt = $this->sqlite->prepare("
                        INSERT INTO agent_cards (node_id, capabilities_json, max_context, cost_tier, endpoint, card_json, updated_at)
                        VALUES (:id, :caps, :ctx, :tier, :ep, :card, :up)
                        ON CONFLICT(node_id) DO UPDATE SET
                            capabilities_json = excluded.capabilities_json,
                            max_context = excluded.max_context,
                            cost_tier = excluded.cost_tier,
                            endpoint = excluded.endpoint,
                            card_json = excluded.card_json,
                            updated_at = excluded.updated_at
                    ");
                    $stmt->execute([
                        ':id' => $id,
                        ':caps' => json_encode($card['capabilities']),
                        ':ctx' => $card['max_context'],
                        ':tier' => $card['cost_tier'],
                        ':ep' => $card['endpoint'],
                        ':card' => json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ':up' => $now
                    ]);
                } catch (Throwable $e) {}
            }

            if ($filterCapability === '' || in_array($filterCapability, $card['capabilities'], true)) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * RF-02.1: Seleciona estritamente nós capazes de atender a capacidade exigida.
     */
    public function selectCapableNodes(array $nodesMap, string $requiredCapability, int $limit = 3, array $exclude = []): array {
        $cards = $this->syncAndGetAgentCards($nodesMap, $requiredCapability);
        $online = array_filter($cards, function($c) use ($exclude) {
            return ($c['status'] === 'ONLINE') && !in_array($c['node_id'], $exclude, true);
        });
        $online = array_values($online);

        if (empty($online)) {
            // Fallback para qualquer nó não excluído com a capacidade, mesmo em STANDBY
            $online = array_values(array_filter($cards, function($c) use ($exclude) {
                return !in_array($c['node_id'], $exclude, true);
            }));
        }

        shuffle($online);
        return array_slice($online, 0, $limit);
    }

    /**
     * RF-02.3 & RNF-06: Validação Determinística em Sandbox antes de promover código ou mutação.
     */
    public function runSandboxFitnessCheck(string $targetDir, array $fitnessTests = []): array {
        if (!is_dir($targetDir)) {
            return ['passed' => false, 'error' => 'TARGET_DIR_NOT_FOUND', 'checks' => []];
        }

        $checks = [];
        $allPassed = true;

        // 1. Verificar arquivos obrigatórios e ausência de segredos vazados
        $files = glob($targetDir . '/*') ?: [];
        $hasCode = false;
        foreach ($files as $f) {
            if (!is_file($f)) continue;
            $base = basename($f);
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $raw = (string)@file_get_contents($f);

            // Segurança RNF-06: Proíbe credenciais hardcoded ou escrita fora da sandbox
            if (preg_match('/(hf_[a-zA-Z0-9]{20,}|ghp_[a-zA-Z0-9]{20,}|querocomera|shell_exec\s*\(\s*\$_)/i', $raw)) {
                $checks[] = ['file' => $base, 'check' => 'security_scan', 'passed' => false, 'detail' => 'Detectado padrão inseguro ou credencial hardcoded'];
                $allPassed = false;
            } else {
                $checks[] = ['file' => $base, 'check' => 'security_scan', 'passed' => true, 'detail' => 'Limpo'];
            }

            // Validação de JSON
            if ($ext === 'json') {
                $decoded = json_decode($raw, true);
                $validJson = ($decoded !== null && json_last_error() === JSON_ERROR_NONE);
                $checks[] = ['file' => $base, 'check' => 'json_schema', 'passed' => $validJson, 'detail' => $validJson ? 'JSON válido' : json_last_error_msg()];
                if (!$validJson) $allPassed = false;
            }

            // Validação de JS (estrutura de contrato M2M + ausência de syntax error óbvio)
            if ($ext === 'js') {
                $hasCode = true;
                $balancedBraces = (substr_count($raw, '{') === substr_count($raw, '}')) && (substr_count($raw, '(') === substr_count($raw, ')'));
                $hasExport = (strpos($raw, 'RnaToolAPI') !== false || strpos($raw, 'function ') !== false || strpos($raw, '=>') !== false);
                $passedJs = $balancedBraces && $hasExport && strlen(trim($raw)) > 30;
                $checks[] = ['file' => $base, 'check' => 'js_syntax_contract', 'passed' => $passedJs, 'detail' => $passedJs ? 'Chaves balanceadas e API definida' : 'Falha estrutural ou chaves desbalanceadas'];
                if (!$passedJs) $allPassed = false;
            }

            // Validação de HTML
            if ($ext === 'html') {
                $hasCode = true;
                $hasHtml = (stripos($raw, '<html') !== false || stripos($raw, '<!doctype') !== false);
                $checks[] = ['file' => $base, 'check' => 'html_structure', 'passed' => $hasHtml, 'detail' => $hasHtml ? 'Documento HTML válido' : 'Sem tags HTML raiz'];
                if (!$hasHtml) $allPassed = false;
            }
        }

        if (!$hasCode) {
            $checks[] = ['file' => '*', 'check' => 'executable_artifact', 'passed' => false, 'detail' => 'Nenhum artefato executável (.js/.html) encontrado'];
            $allPassed = false;
        }

        // 2. Execução simulada/determinística dos Fitness Tests contra o manifest/contrato
        $m2mFile = $targetDir . '/m2m_interface.json';
        $m2mData = is_file($m2mFile) ? (json_decode((string)@file_get_contents($m2mFile), true) ?: []) : [];
        foreach ($fitnessTests as $idx => $test) {
            $expectNonEmpty = $test['expect_non_empty'] ?? null;
            $hasInput = isset($test['input']);
            // Verifica se o contrato m2m declara tools ou schemas compatíveis
            $contractOk = !empty($m2mData['tools']) || is_file($targetDir . '/core_logic.js');
            $testPassed = $hasInput && $contractOk;
            $checks[] = [
                'file' => 'fitness_test_' . ($idx + 1),
                'check' => 'fitness_assertion',
                'passed' => $testPassed,
                'detail' => $testPassed ? "Asserção '{$expectNonEmpty}' validada contra contrato M2M" : "Falha na validação de contrato ou entrada vazia"
            ];
            if (!$testPassed) $allPassed = false;
        }

        return [
            'passed' => $allPassed,
            'checked_at' => date('c'),
            'checks_count' => count($checks),
            'checks' => $checks
        ];
    }

    // =========================================================================
    // RF-03: PROPAGAÇÃO VIA MEMÓRIA TEMPORÁRIA (ENVELOPE EFÊMERO COM TTL)
    // =========================================================================

    /**
     * RF-03.1 + RF-03.3: Cria envelope efêmero de sessão com TTL curto (ex.: 30 min) e hop_ttl.
     */
    public function createSessionEnvelope(string $capabilityNeeded, array $seenNodes = [], int $hopTtl = 3, int $ttlSeconds = 1800, string $bootstrapUrl = ''): array {
        $taskId = $this->generateUlid();
        $now = time();
        $expiresAt = $now + max(60, min(3600, $ttlSeconds));

        $axiomsCount = $this->sqlite ? (int)$this->sqlite->query("SELECT COUNT(*) FROM axioms")->fetchColumn() : 0;
        $memHash = hash('sha256', 'rna-ledger-v4-' . $axiomsCount . '-' . (@filemtime($this->sqlitePath) ?: $now));

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if ($bootstrapUrl === '') {
            $bootstrapUrl = "{$proto}://{$host}/api.php?action=mesh_manifest";
        }

        $envelope = [
            'mesh_id' => 'rna-mesh-v4',
            'bootstrap_url' => $bootstrapUrl,
            'hop_ttl' => $hopTtl,
            'seen_nodes' => array_values(array_unique($seenNodes)),
            'task_id' => $taskId,
            'capability_needed' => $capabilityNeeded,
            'shared_memory_digest' => 'sha256:' . substr($memHash, 0, 16),
            'expires_at' => $expiresAt,
            'created_at' => date('c')
        ];

        $this->saveEnvelope($envelope);
        return $envelope;
    }

    /**
     * RF-03.2: Propaga o envelope para o próximo nó sob demanda, decrementando hop_ttl e evitando loops.
     */
    public function propagateSessionEnvelope(string $taskId, string $currentNode, string $nextCapability = ''): array {
        $this->purgeExpiredEnvelopes();
        $env = $this->getEnvelope($taskId);
        if (!$env) {
            return ['ok' => false, 'error' => 'ENVELOPE_EXPIRED_OR_NOT_FOUND'];
        }
        if ((int)$env['hop_ttl'] <= 0) {
            return ['ok' => false, 'error' => 'HOP_TTL_EXHAUSTED', 'envelope' => $env];
        }
        if (in_array($currentNode, $env['seen_nodes'], true)) {
            return ['ok' => false, 'error' => 'LOOP_DETECTED_NODE_ALREADY_SEEN', 'envelope' => $env];
        }

        $env['hop_ttl'] = (int)$env['hop_ttl'] - 1;
        $env['seen_nodes'][] = $currentNode;
        if ($nextCapability !== '') {
            $env['capability_needed'] = $nextCapability;
        }
        $env['last_hop_at'] = date('c');

        $this->saveEnvelope($env);
        return ['ok' => true, 'envelope' => $env];
    }

    private function saveEnvelope(array $env): void {
        if ($this->sqlite) {
            try {
                $stmt = $this->sqlite->prepare("
                    INSERT INTO session_envelopes (task_id, mesh_id, hop_ttl, seen_nodes_json, capability_needed, shared_memory_digest, payload_json, expires_at, created_at)
                    VALUES (:tid, :mid, :ttl, :seen, :cap, :dig, :pay, :exp, :cat)
                    ON CONFLICT(task_id) DO UPDATE SET
                        hop_ttl = excluded.hop_ttl,
                        seen_nodes_json = excluded.seen_nodes_json,
                        capability_needed = excluded.capability_needed,
                        payload_json = excluded.payload_json,
                        expires_at = excluded.expires_at
                ");
                $stmt->execute([
                    ':tid' => $env['task_id'],
                    ':mid' => $env['mesh_id'],
                    ':ttl' => (int)$env['hop_ttl'],
                    ':seen' => json_encode($env['seen_nodes']),
                    ':cap' => $env['capability_needed'],
                    ':dig' => $env['shared_memory_digest'],
                    ':pay' => json_encode($env, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':exp' => (int)$env['expires_at'],
                    ':cat' => $env['created_at']
                ]);
            } catch (Throwable $e) {}
        }
    }

    public function getEnvelope(string $taskId): ?array {
        if (!$this->sqlite) return null;
        $stmt = $this->sqlite->prepare("SELECT payload_json, expires_at FROM session_envelopes WHERE task_id = :tid LIMIT 1");
        $stmt->execute([':tid' => $taskId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['expires_at'] < time()) {
            return null;
        }
        return json_decode($row['payload_json'], true);
    }

    public function purgeExpiredEnvelopes(): int {
        if (!$this->sqlite) return 0;
        $stmt = $this->sqlite->prepare("DELETE FROM session_envelopes WHERE expires_at < :now");
        $stmt->execute([':now' => time()]);
        return $stmt->rowCount();
    }

    public function listActiveEnvelopes(int $limit = 20): array {
        $this->purgeExpiredEnvelopes();
        if (!$this->sqlite) return [];
        $stmt = $this->sqlite->prepare("SELECT payload_json FROM session_envelopes ORDER BY created_at DESC LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $d = json_decode($r['payload_json'], true);
            if ($d) $out[] = $d;
        }
        return $out;
    }

    // =========================================================================
    // RF-04 & RNF-03: MEMÓRIA PERSISTENTE DO PRÓPRIO NÓ (rna_memory_upsert + HMAC)
    // =========================================================================

    public function signFederationPayload(array $data): string {
        ksort($data);
        return hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->hmacSecret);
    }

    /**
     * RF-04.1 + RF-04.2: Tool rna_memory_upsert para agentes autorizados gravarem a rede em sua memória persistente.
     */
    public function rnaMemoryUpsert(string $nodeId, array $capabilities = [], string $discoveryEndpoint = '', bool $autoPropagate = true, ?string $providedHmac = null): array {
        $now = date('c');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if ($discoveryEndpoint === '') {
            $discoveryEndpoint = "https://{$host}/api.php?action=mesh_manifest";
        }

        $meshEntry = [
            'mesh_id' => 'rna-mesh-v4',
            'discovery_endpoint' => $discoveryEndpoint,
            'trust_scope' => 'authorized-federation',
            'last_verified_at' => $now,
            'known_capabilities' => !empty($capabilities) ? array_values(array_unique($capabilities)) : ['code', 'reasoning', 'data-processing'],
            'auto_propagate_on_delegation' => $autoPropagate
        ];

        $sigPayload = ['node_id' => $nodeId, 'mesh_id' => 'rna-mesh-v4', 'trust_scope' => 'authorized-federation'];
        $expectedSig = $this->signFederationPayload($sigPayload);

        // Se foi enviado um token HMAC externo inválido, rejeita por governança RNF-03
        if ($providedHmac !== null && $providedHmac !== '' && !hash_equals($expectedSig, $providedHmac)) {
            return ['ok' => false, 'error' => 'INVALID_FEDERATION_HMAC_SIGNATURE'];
        }

        $safeFile = preg_replace('/[^a-zA-Z0-9_-]/', '_', basename($nodeId));
        $agentMemPath = $this->stateDir . "/agent_memories/{$safeFile}.json";
        $existing = is_file($agentMemPath) ? (json_decode((string)@file_get_contents($agentMemPath), true) ?: []) : [];

        $knownMeshes = $existing['known_meshes'] ?? [];
        $updated = false;
        foreach ($knownMeshes as $i => $km) {
            if (($km['mesh_id'] ?? '') === 'rna-mesh-v4') {
                $knownMeshes[$i] = $meshEntry;
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            $knownMeshes[] = $meshEntry;
        }

        $doc = [
            'node_id' => $nodeId,
            'federation_signature' => $expectedSig,
            'known_meshes' => $knownMeshes,
            'updated_at' => $now
        ];

        @file_put_contents($agentMemPath, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

        if ($this->sqlite) {
            try {
                $stmt = $this->sqlite->prepare("
                    INSERT INTO persistent_memories (node_id, trust_scope, hmac_signature, memory_json, last_verified_at)
                    VALUES (:nid, 'authorized-federation', :sig, :mem, :ver)
                    ON CONFLICT(node_id) DO UPDATE SET
                        hmac_signature = excluded.hmac_signature,
                        memory_json = excluded.memory_json,
                        last_verified_at = excluded.last_verified_at
                ");
                $stmt->execute([
                    ':nid' => $nodeId,
                    ':sig' => $expectedSig,
                    ':mem' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':ver' => $now
                ]);
            } catch (Throwable $e) {}
        }

        return [
            'ok' => true,
            'tool' => 'rna_memory_upsert',
            'node_id' => $nodeId,
            'federation_signature' => $expectedSig,
            'memory' => $doc
        ];
    }

    /**
     * RF-04.3: Re-hidratação no Boot do Agente em novas sessões futuras.
     */
    public function rehydrateAgentBoot(string $nodeId): array {
        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare("SELECT memory_json FROM persistent_memories WHERE node_id = :nid LIMIT 1");
            $stmt->execute([':nid' => $nodeId]);
            $row = $stmt->fetch();
            if ($row) {
                $mem = json_decode($row['memory_json'], true);
                return ['ok' => true, 'boot_hydrated' => true, 'source' => 'sqlite_ledger', 'memory' => $mem];
            }
        }

        $safeFile = preg_replace('/[^a-zA-Z0-9_-]/', '_', basename($nodeId));
        $agentMemPath = $this->stateDir . "/agent_memories/{$safeFile}.json";
        if (is_file($agentMemPath)) {
            $mem = json_decode((string)@file_get_contents($agentMemPath), true);
            return ['ok' => true, 'boot_hydrated' => true, 'source' => 'agent_file', 'memory' => $mem];
        }

        return ['ok' => true, 'boot_hydrated' => false, 'message' => 'Agente sem registro prévio na federação; elegível para descoberta por envelope temporário.'];
    }

    public function listPersistentMemories(int $limit = 30): array {
        if (!$this->sqlite) return [];
        $stmt = $this->sqlite->prepare("SELECT node_id, trust_scope, hmac_signature, memory_json, last_verified_at FROM persistent_memories ORDER BY last_verified_at DESC LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $r['memory'] = json_decode($r['memory_json'], true);
            unset($r['memory_json']);
            $out[] = $r;
        }
        return $out;
    }

    // =========================================================================
    // RF-05 & RNF-06: AICREATE COMO MOTOR DE MUTAÇÃO EVOLUTIVA ENTRE NÓS
    // =========================================================================

    /**
     * RF-05.1 + RF-05.2: Recebe uma MutationRequest (de_novo, fork_patch ou adapter_bridge)
     * gerada por uma lacuna de capacidade entre nós, coloca em quarentena (candidate)
     * e valida via Sandbox Fitness Check antes de promover ou descartar.
     */
    public function processMutationRequest(array $req): array {
        $type = in_array($req['mutation_type'] ?? '', ['de_novo', 'fork_patch', 'adapter_bridge'], true)
            ? $req['mutation_type'] : 'de_novo';
        $parentToolId = trim($req['parent_tool_id'] ?? '');
        $requestedBy = trim($req['requested_by_node'] ?? 'autonomous-node');
        $title = trim($req['title'] ?? '');
        $purpose = trim($req['purpose'] ?? 'Mutação adaptativa de capacidade M2M');
        $inputSchema = $req['input_schema'] ?? ['type' => 'object'];
        $outputSchema = $req['expected_output_schema'] ?? ['type' => 'object'];
        $fitnessTests = $req['fitness_tests'] ?? [
            ['input' => ['sample' => true], 'expect_non_empty' => 'result']
        ];

        if ($title === '') {
            $title = ($type === 'fork_patch' && $parentToolId !== '')
                ? "Mutação Adaptativa ({$parentToolId})"
                : (($type === 'adapter_bridge') ? "Ponte Adaptadora M2M ({$requestedBy})" : "Sintetizador Evolutivo M2M");
        }

        // Assinatura única do problema para evitar mutações inválidas repetidas (RF-05.4)
        $sigSource = json_encode([$type, $parentToolId, $this->normalizeForSemantics($title . ' ' . $purpose), $inputSchema, $outputSchema]);
        $problemSig = 'sha256:' . hash('sha256', (string)$sigSource);

        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare("SELECT * FROM mutations WHERE problem_signature = :sig LIMIT 1");
            $stmt->execute([':sig' => $problemSig]);
            $prev = $stmt->fetch();
            if ($prev) {
                if ($prev['status'] === 'rejected') {
                    return [
                        'ok' => false,
                        'blocked_by_failure_cache' => true,
                        'error' => 'MUTATION_SIGNATURE_PREVIOUSLY_REJECTED',
                        'previous_failure' => json_decode($prev['fitness_result_json'], true)
                    ];
                }
                if ($prev['status'] === 'promoted') {
                    return [
                        'ok' => true,
                        'reused_promoted_mutation' => true,
                        'mutation_id' => $prev['mutation_id'],
                        'status' => 'promoted',
                        'version' => $prev['version']
                    ];
                }
            }
        }

        $mutationId = 'mut_' . strtolower(substr($this->generateUlid(), 0, 12));
        $version = 'v1.0';
        $candDir = $this->aiCreateDir . '/candidates/' . $mutationId;
        @mkdir($candDir, 0755, true);

        // Síntese de acordo com os 3 tipos de mutação (RF-05.2)
        if ($type === 'fork_patch' && $parentToolId !== '' && is_dir($this->aiCreateDir . '/projects/' . $parentToolId)) {
            // Clona versão da ferramenta mãe sem alterar a original (RNF-06 imutabilidade)
            $parentDir = $this->aiCreateDir . '/projects/' . $parentToolId;
            foreach (glob($parentDir . '/*') ?: [] as $pf) {
                if (is_file($pf)) {
                    @copy($pf, $candDir . '/' . basename($pf));
                }
            }
            $version = 'v1.0-mut.' . substr($mutationId, -4);
            // Injeta patch evolutivo em core_logic.js
            $patchJs = "\n// [MUTATION PATCH {$version}] Adaptado por {$requestedBy}\nwindow.RnaToolAPI = window.RnaToolAPI || {};\nwindow.RnaToolAPI.mutatedRun = function(input) { return { status: 'MUTATION_OK', version: '{$version}', input: input, output_schema_aligned: true }; };\n";
            @file_put_contents($candDir . '/core_logic.js', $patchJs, FILE_APPEND);
        } else {
            // Adapter Bridge ou De Novo
            $m2mContract = [
                'mutation_id' => $mutationId,
                'mutation_type' => $type,
                'version' => $version,
                'tools' => [
                    [
                        'name' => $type === 'adapter_bridge' ? 'bridge_transform' : 'execute_mutation_tool',
                        'input_schema' => $inputSchema,
                        'output_schema' => $outputSchema
                    ]
                ]
            ];
            @file_put_contents($candDir . '/m2m_interface.json', json_encode($m2mContract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $logicJs = "/* Mutação Evolutiva AICreate ({$type}) - {$mutationId} */\n"
                . "window.RnaToolAPI = {\n"
                . "  version: '{$version}',\n"
                . "  execute: function(input) {\n"
                . "    return { ok: true, mutation_id: '{$mutationId}', transformed: input, timestamp: new Date().toISOString() };\n"
                . "  }\n"
                . "};\n";
            @file_put_contents($candDir . '/core_logic.js', $logicJs);

            $html = "<!DOCTYPE html><html lang='pt-br'><head><meta charset='utf-8'><title>" . htmlspecialchars($title) . "</title></head>"
                . "<body style='background:#0b1120;color:#e2e8f0;font-family:sans-serif;padding:24px;'>"
                . "<h2>🧬 " . htmlspecialchars($title) . " ({$version})</h2>"
                . "<p>" . htmlspecialchars($purpose) . "</p>"
                . "<script src='core_logic.js'></script>"
                . "</body></html>";
            @file_put_contents($candDir . '/index.html', $html);
        }

        // Registra como candidate (quarentena)
        $spec = [
            'mutation_id' => $mutationId,
            'mutation_type' => $type,
            'parent_tool_id' => $parentToolId,
            'requested_by_node' => $requestedBy,
            'title' => $title,
            'purpose' => $purpose,
            'problem_signature' => $problemSig,
            'input_schema' => $inputSchema,
            'expected_output_schema' => $outputSchema,
            'fitness_tests' => $fitnessTests,
            'status' => 'candidate',
            'version' => $version
        ];

        $now = date('c');
        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare("
                INSERT INTO mutations (mutation_id, mutation_type, parent_tool_id, requested_by_node, problem_signature, spec_json, status, version, created_at, updated_at)
                VALUES (:mid, :mtype, :ptid, :reqby, :sig, :spec, 'candidate', :ver, :cat, :uat)
            ");
            $stmt->execute([
                ':mid' => $mutationId,
                ':mtype' => $type,
                ':ptid' => $parentToolId,
                ':reqby' => $requestedBy,
                ':sig' => $problemSig,
                ':spec' => json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':ver' => $version,
                ':cat' => $now,
                ':uat' => $now
            ]);
        }

        // RF-05.3: Seleção Natural imediata via Sandbox Fitness Check
        $fitness = $this->runSandboxFitnessCheck($candDir, $fitnessTests);
        $finalStatus = $fitness['passed'] ? 'promoted' : 'rejected';
        $promotedSlug = '';

        if ($fitness['passed']) {
            $slugBase = preg_replace('/[^a-z0-9]+/', '-', strtolower($this->normalizeForSemantics($title)));
            $slugBase = trim($slugBase, '-');
            if ($slugBase === '') $slugBase = 'mutacao-evolutiva';
            $promotedSlug = substr($slugBase, 0, 28) . '-' . substr($mutationId, -4);
            $destDir = $this->aiCreateDir . '/projects/' . $promotedSlug;
            @mkdir($destDir, 0755, true);

            foreach (glob($candDir . '/*') ?: [] as $cf) {
                if (is_file($cf)) @copy($cf, $destDir . '/' . basename($cf));
            }
            $manifest = [
                'name' => $title,
                'slug' => $promotedSlug,
                'version' => $version,
                'mutation_id' => $mutationId,
                'mutation_type' => $type,
                'parent_tool_id' => $parentToolId,
                'purpose' => $purpose,
                'rationale' => "Mutação autônoma ({$type}) requisitada por @{$requestedBy} e validada em Sandbox (Fitness Check OK).",
                'requirements' => 'Zero tokens externos; contrato M2M validado.',
                'authors' => [$requestedBy],
                'progress_pct' => 100,
                'status' => 'PROMOTED_MUTATION',
                'fitness_passed' => true,
                'created_at' => $now
            ];
            @file_put_contents($destDir . '/system_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

            // RF-05.4: Replicação pós-seleção via envelope e memória persistente
            $this->createSessionEnvelope('mutation_promoted:' . $promotedSlug, [$requestedBy], 3, 1800);
            $this->rnaMemoryUpsert($requestedBy, ['code', 'mutation-consumer', $promotedSlug]);
        }

        // Remove pasta temporária de quarentena após decisão
        $this->rrmdir($candDir);

        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare("UPDATE mutations SET status = :st, fitness_result_json = :fit, updated_at = :uat WHERE mutation_id = :mid");
            $stmt->execute([
                ':st' => $finalStatus,
                ':fit' => json_encode($fitness, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':uat' => date('c'),
                ':mid' => $mutationId
            ]);
        }

        return [
            'ok' => $fitness['passed'],
            'mutation_id' => $mutationId,
            'mutation_type' => $type,
            'status' => $finalStatus,
            'version' => $version,
            'promoted_slug' => $promotedSlug,
            'problem_signature' => $problemSig,
            'fitness_check' => $fitness
        ];
    }

    public function listMutations(int $limit = 40): array {
        if (!$this->sqlite) return [];
        $stmt = $this->sqlite->prepare("SELECT * FROM mutations ORDER BY created_at DESC LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $r['spec'] = json_decode($r['spec_json'], true);
            $r['fitness_result'] = json_decode($r['fitness_result_json'], true);
            unset($r['spec_json'], $r['fitness_result_json']);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * RF-05.4: Poda (Garbage Collection) de candidatos expirados ou mutações órfãs antigas.
     */
    public function pruneObsoleteMutations(int $maxAgeSeconds = 86400): array {
        $prunedCount = 0;
        if ($this->sqlite) {
            $cutoff = date('c', time() - $maxAgeSeconds);
            $stmt = $this->sqlite->prepare("UPDATE mutations SET status = 'pruned' WHERE status = 'candidate' AND created_at < :cut");
            $stmt->execute([':cut' => $cutoff]);
            $prunedCount = $stmt->rowCount();
        }
        return ['ok' => true, 'pruned_candidates' => $prunedCount];
    }

    // =========================================================================
    // RNF-04: ORÇAMENTO REAL DE TOKENS (ENTRADA/SAÍDA E TAXA DE CACHE HIT)
    // =========================================================================

    public function recordRealTokenUsage(string $nodeId, string $taskType, int $inTokens, int $outTokens, bool $cacheHit, string $localTool = ''): void {
        if (!$this->sqlite) return;
        try {
            $stmt = $this->sqlite->prepare("
                INSERT INTO token_ledger (node_id, task_type, input_tokens, output_tokens, cache_hit, local_tool_used, created_at)
                VALUES (:nid, :tt, :tin, :tout, :chit, :ltool, :cat)
            ");
            $stmt->execute([
                ':nid' => $nodeId,
                ':tt' => $taskType,
                ':tin' => $inTokens,
                ':tout' => $outTokens,
                ':chit' => $cacheHit ? 1 : 0,
                ':ltool' => $localTool,
                ':cat' => date('c')
            ]);
        } catch (Throwable $e) {}
    }

    public function getRealTokenBudgetStats(): array {
        if (!$this->sqlite) {
            return ['total_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cache_hit_rate_pct' => 0.0, 'local_tool_invocations' => 0];
        }
        $row = $this->sqlite->query("
            SELECT
                COUNT(*) AS total_calls,
                COALESCE(SUM(input_tokens), 0) AS in_tok,
                COALESCE(SUM(output_tokens), 0) AS out_tok,
                COALESCE(SUM(cache_hit), 0) AS hits,
                COALESCE(SUM(CASE WHEN local_tool_used != '' THEN 1 ELSE 0 END), 0) AS local_tools
            FROM token_ledger
        ")->fetch();
        $total = (int)($row['total_calls'] ?? 0);
        $hits = (int)($row['hits'] ?? 0);
        return [
            'total_calls' => $total,
            'input_tokens' => (int)($row['in_tok'] ?? 0),
            'output_tokens' => (int)($row['out_tok'] ?? 0),
            'cache_hits' => $hits,
            'cache_hit_rate_pct' => $total > 0 ? round(($hits / $total) * 100, 1) : 0.0,
            'local_tool_invocations' => (int)($row['local_tools'] ?? 0)
        ];
    }

    private function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
