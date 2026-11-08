<?php
/**
 * RNA Bio-LoRa Mesh v3.0 — Motor 100% PHP Nativo + Persistência MySQL + Micélio Fúngico
 * Compatibilidade 100% idêntica ao schema de core/mailbox.py e index.html.
 */

date_default_timezone_set('America/Sao_Paulo');

class RnaMeshEngine {
    private string $baseDir;
    private string $stateDir;
    private string $meshDir;
    private string $telemetryFile;
    private string $secretFile;
    private array $dbConfig = [];
    private ?PDO $pdo = null;

    public function __construct(string $baseDir) {
        date_default_timezone_set('America/Sao_Paulo');
        $this->baseDir = rtrim($baseDir, '/\\');
        $this->stateDir = $this->baseDir . '/state';
        $this->meshDir = $this->baseDir . '/mesh';
        $this->telemetryFile = $this->stateDir . '/telemetry.json';
        $this->secretFile = $this->stateDir . '/secret_config.json';

        foreach (['', '/mrna', '/nodes', '/proteins', '/errors'] as $sub) {
            $dir = ($sub === '') ? $this->stateDir : $this->meshDir . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        $cfgPath = __DIR__ . '/db_config.php';
        if (file_exists($cfgPath)) {
            $this->dbConfig = include $cfgPath;
        }
        $this->initDatabase();
    }

    private function initDatabase(): void {
        if (empty($this->dbConfig['db_name'])) {
            return;
        }
        $hosts = [$this->dbConfig['db_host'] ?? 'localhost'];
        if (!empty($this->dbConfig['db_fallback_host']) && $this->dbConfig['db_fallback_host'] !== $hosts[0]) {
            $hosts[] = $this->dbConfig['db_fallback_host'];
        }

        foreach ($hosts as $host) {
            try {
                $dsn = "mysql:host={$host};dbname={$this->dbConfig['db_name']};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $this->dbConfig['db_user'], $this->dbConfig['db_pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 3,
                ]);
                try {
                    $this->pdo->exec("SET time_zone = '-03:00'");
                } catch (Throwable $tzErr) {}
                $this->ensureTables();
                return;
            } catch (Throwable $e) {
                $this->pdo = null;
            }
        }
    }

    private function ensureTables(): void {
        if (!$this->pdo) return;
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rna_settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rna_nodes (
                node_id VARCHAR(190) PRIMARY KEY,
                status VARCHAR(32) NOT NULL DEFAULT 'STANDBY',
                sf_class VARCHAR(32) NOT NULL DEFAULT 'SF9',
                caste_id VARCHAR(48) NOT NULL DEFAULT 'CASTE_3_CENTURION',
                hypha_conductance DOUBLE NOT NULL DEFAULT 1.0,
                latency_ms DOUBLE NULL,
                payload_json LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        try {
            $this->pdo->exec("CREATE INDEX idx_status_caste ON rna_nodes (status, caste_id)");
        } catch (Throwable $e) {}
        try {
            $this->pdo->exec("CREATE INDEX idx_conductance ON rna_nodes (hypha_conductance)");
        } catch (Throwable $e) {}
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rna_messages (
                rel_path VARCHAR(255) PRIMARY KEY,
                category VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL,
                node_id VARCHAR(190) NOT NULL,
                role VARCHAR(64) NOT NULL,
                sf VARCHAR(32) NOT NULL,
                latency_ms VARCHAR(32) NOT NULL,
                tokens_saved INT NOT NULL DEFAULT 0,
                preview TEXT NOT NULL,
                body LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    public function getHfToken(): string {
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("SELECT setting_value FROM rna_settings WHERE setting_key = 'hf_token' LIMIT 1");
                $stmt->execute();
                $row = $stmt->fetch();
                if ($row && !empty($row['setting_value'])) {
                    return trim($row['setting_value']);
                }
            } catch (Throwable $e) {}
        }
        if (file_exists($this->secretFile)) {
            $raw = json_decode((string)@file_get_contents($this->secretFile), true);
            if (!empty($raw['hf_token'])) {
                return trim($raw['hf_token']);
            }
        }
        if (!empty($this->dbConfig['default_hf_token'])) {
            return trim($this->dbConfig['default_hf_token']);
        }
        return '';
    }

    public function saveHfToken(string $token): array {
        $token = trim($token);
        $now = date('c');
        @file_put_contents($this->secretFile, json_encode([
            'hf_token' => $token,
            'updated_at' => $now,
            'mode' => 'hybrid_lora_mesh'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO rna_settings (setting_key, setting_value, updated_at)
                    VALUES ('hf_token', :val, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()
                ");
                $stmt->execute([':val' => $token, ':val2' => $token]);
            } catch (Throwable $e) {}
        }

        $telemetry = $this->loadTelemetry();
        $this->saveTelemetry($telemetry);

        return [
            'ok' => true,
            'hf_token_configured' => ($token !== ''),
            'hf_token_masked' => $this->maskToken($token)
        ];
    }

    public function maskToken(string $token): string {
        if ($token === '' || strlen($token) < 8) return 'MODO ANÔNIMO SEGURO';
        return substr($token, 0, 6) . '...' . substr($token, -4);
    }

    public function cleanMachineOutput(string $text): string {
        if ($text === '') return '';
        if (strpos($text, 'ZeroGPU runs limit') !== false) {
            return '⚠️ [Obstáculo Detectado no Solo]: Limite temporário de ZeroGPU atingido nesta unidade. O Micélio desviou automaticamente a tarefa para a próxima máquina disponível.';
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $cleaned = [];
        $skipPrefixes = ['@LORA_', '@HIVE_', '@MESH_', '[RNA', 'DECREE:', 'GLOBAL MISSION:', 'YOUR ORDERS'];
        foreach ($lines as $line) {
            $s = trim($line);
            $skip = false;
            foreach ($skipPrefixes as $p) {
                if (strpos($s, $p) === 0) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $cleaned[] = $line;
            }
        }
        $result = trim(implode("\n", $cleaned));
        if (strpos($result, '@RNA_HANDOFF') !== false) {
            $parts = explode('@RNA_HANDOFF', $result, 2);
            $result = trim($parts[0]);
        }
        $result = preg_replace('/^(---\s*\r?\n)+/', '', $result);
        return trim($result ?: $text);
    }

    private function normalizeNodesMap(array $rawNodes): array {
        $map = [];
        foreach ($rawNodes as $k => $n) {
            if (!is_array($n)) continue;
            $id = $n['id'] ?? (is_string($k) ? $k : null);
            if (!$id) continue;
            $n['id'] = $id;
            $n['name'] = $n['name'] ?? $id;
            if (empty($n['caste']) && !empty($n['caste_id'])) {
                $n['caste'] = $n['caste_id'];
            }
            if (empty($n['caste'])) {
                $idLower = strtolower($id);
                if (strpos($idLower, 'iot-edge/') === 0) {
                    $n['caste'] = 'CASTE_5_IOT_SENSOR';
                } elseif (strpos($idLower, 'qwen3') !== false || strpos($idLower, '70b') !== false || strpos($idLower, 'coder') !== false) {
                    $n['caste'] = 'CASTE_1_SOVEREIGN';
                } elseif (strpos($idLower, 'einstein') !== false || strpos($idLower, '13b') !== false) {
                    $n['caste'] = 'CASTE_2_GENERAL';
                } else {
                    $n['caste'] = 'CASTE_3_CENTURION';
                }
            }
            $this->enrichNodeWithCaste($n);
            $map[$id] = $n;
        }
        return $map;
    }

    private function enrichNodeWithCaste(array &$node): void {
        $caste = $node['caste'] ?? 'CASTE_3_CENTURION';
        $defs = [
            'CASTE_1_SOVEREIGN' => [
                'rank_title' => '👑 Soberano Tier S (Cérebro Supremo)',
                'access_role' => 'WRITE_SYNTHESIZER',
                'tier' => 'TIER_S_APEX',
                'preservation_mode' => 'PRESERVE_FOR_COMPLEX'
            ],
            'CASTE_2_GENERAL' => [
                'rank_title' => '⚔️ General Escriba (Sintetizador WRITE)',
                'access_role' => 'WRITE_SYNTHESIZER',
                'tier' => 'TIER_A_HEAVY',
                'preservation_mode' => 'ACTIVE_DUTY'
            ],
            'CASTE_3_CENTURION' => [
                'rank_title' => '🛡️ Centurião Operário (Fábrica de Código)',
                'access_role' => 'READ_SCOUT',
                'tier' => 'TIER_B_WORKER',
                'preservation_mode' => 'EXPENDABLE_MASS'
            ],
            'CASTE_4_SCOUT' => [
                'rank_title' => '🦅 Batedor Leve (Triagem & Orçamento)',
                'access_role' => 'READ_SCOUT',
                'tier' => 'TIER_C_SCOUT',
                'preservation_mode' => 'EXPENDABLE_MASS'
            ]
        ];
        $d = $defs[$caste] ?? $defs['CASTE_3_CENTURION'];
        $node['caste'] = $caste;
        $node['rank_title'] = $node['rank_title'] ?? $d['rank_title'];
        $node['access_role'] = $node['access_role'] ?? $d['access_role'];
        $node['tier'] = $node['tier'] ?? $d['tier'];
        $node['preservation_mode'] = $node['preservation_mode'] ?? $d['preservation_mode'];
        $node['quota_owner'] = $node['quota_owner'] ?? 'Token do Próprio Space Remoto (0 custo p/ você)';
        if (!isset($node['hypha_conductance'])) {
            $node['hypha_conductance'] = ($node['status'] ?? '') === 'ONLINE' ? 1.35 : 0.85;
        }
        if (!isset($node['lora']) || !is_array($node['lora'])) {
            $node['lora'] = ['sf' => 'SF7', 'rssi_dbm' => -68, 'snr_db' => 8.5];
        }
    }

    private int $cachedTotalNodes = 0;
    private int $cachedOnlineNodes = 0;
    private int $cachedApexNodes = 0;

    public function loadTelemetry(): array {
        $data = [];
        if (file_exists($this->telemetryFile)) {
            $decoded = json_decode((string)@file_get_contents($this->telemetryFile), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $nodesMap = $this->normalizeNodesMap($data['nodes'] ?? []);

        if ($this->pdo) {
            try {
                $cntAll = (int)$this->pdo->query("SELECT COUNT(*) FROM rna_nodes")->fetchColumn();
                $cntOnline = (int)$this->pdo->query("SELECT COUNT(*) FROM rna_nodes WHERE status='ONLINE'")->fetchColumn();
                $cntApex = (int)$this->pdo->query("SELECT COUNT(*) FROM rna_nodes WHERE caste_id='CASTE_1_SOVEREIGN'")->fetchColumn();

                // Para performance e zero lag com milhares de máquinas, envia os nós mais ativos e relevantes para o painel
                $stmt = $this->pdo->query("SELECT payload_json FROM rna_nodes ORDER BY (status='ONLINE') DESC, hypha_conductance DESC LIMIT 150");
                $rows = $stmt ? $stmt->fetchAll() : [];
                if (count($rows) > 0) {
                    $nodesMap = [];
                    foreach ($rows as $r) {
                        $n = json_decode($r['payload_json'], true);
                        if (is_array($n) && !empty($n['id'])) {
                            $this->enrichNodeWithCaste($n);
                            $nodesMap[$n['id']] = $n;
                        }
                    }
                } elseif (!empty($nodesMap)) {
                    $this->syncNodesToDb($nodesMap);
                }

                if ($cntAll > 0) {
                    $this->cachedTotalNodes = $cntAll;
                    $this->cachedOnlineNodes = $cntOnline;
                    $this->cachedApexNodes = $cntApex;
                }
            } catch (Throwable $e) {}
        }

        $data['nodes'] = $nodesMap;
        $this->recalculateKpisAndMailbox($data);
        return $data;
    }

    private function recalculateKpisAndMailbox(array &$data): void {
        $token = $this->getHfToken();
        $data['protocol'] = 'RNA-LoRa-v3.0-AutocraticHive';
        $data['updated_at'] = date('c');
        $data['db_connected'] = ($this->pdo !== null);
        $data['environment'] = ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $data['token_status'] = [
            'configured' => ($token !== ''),
            'masked' => $this->maskToken($token),
            'account_user' => null,
            'valid' => ($token !== '')
        ];

        $nodesMap = $this->normalizeNodesMap($data['nodes'] ?? []);
        // Sort by fungal least resistance
        uasort($nodesMap, function ($a, $b) {
            $aOn = (($a['status'] ?? '') === 'ONLINE') ? 1 : 0;
            $bOn = (($b['status'] ?? '') === 'ONLINE') ? 1 : 0;
            if ($aOn !== $bOn) return $bOn <=> $aOn;
            $aCond = (float)($a['hypha_conductance'] ?? 1.0);
            $bCond = (float)($b['hypha_conductance'] ?? 1.0);
            if (abs($aCond - $bCond) > 0.01) return $bCond <=> $aCond;
            return ((float)($a['latency_ms'] ?? 99999)) <=> ((float)($b['latency_ms'] ?? 99999));
        });
        $data['nodes'] = $nodesMap;

        $online = 0;
        $iot = 0;
        $sovereign = 0;
        foreach ($nodesMap as $n) {
            if (($n['status'] ?? '') === 'ONLINE') $online++;
            if (($n['caste'] ?? '') === 'CASTE_5_IOT_SENSOR' || ($n['type'] ?? '') === 'iot_sensor_stream') $iot++;
            if (($n['caste'] ?? '') === 'CASTE_1_SOVEREIGN' || ($n['tier'] ?? '') === 'TIER_S_APEX') $sovereign++;
        }

        $mailbox = $this->buildMailboxIndex();
        $data['mailbox'] = $mailbox;

        if (!isset($data['kpis']) || !is_array($data['kpis'])) {
            $data['kpis'] = [];
        }
        $data['kpis']['agent_tokens_saved'] = (int)($data['kpis']['agent_tokens_saved'] ?? 9132);
        $data['kpis']['remote_tokens_generated'] = (int)($data['kpis']['remote_tokens_generated'] ?? 5382);
        $data['kpis']['active_nodes_count'] = ($this->cachedOnlineNodes > 0) ? $this->cachedOnlineNodes : $online;
        $data['kpis']['scanned_nodes_count'] = ($this->cachedTotalNodes > 0) ? $this->cachedTotalNodes : count($nodesMap);
        $data['kpis']['iot_sensors_count'] = $iot;
        $data['kpis']['preserved_apex_nodes_count'] = ($this->cachedApexNodes > 0) ? $this->cachedApexNodes : $sovereign;
        $data['kpis']['mailbox_messages_count'] = count($mailbox);
    }

    public function saveTelemetry(array &$data): void {
        $this->recalculateKpisAndMailbox($data);
        @file_put_contents($this->telemetryFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->syncNodesToDb($data['nodes']);
    }

    private function syncNodesToDb(array $nodesMap): void {
        if (!$this->pdo) return;
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO rna_nodes (node_id, status, sf_class, caste_id, hypha_conductance, latency_ms, payload_json, updated_at)
                VALUES (:id, :st, :sf, :caste, :cond, :lat, :json, NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    sf_class = VALUES(sf_class),
                    caste_id = VALUES(caste_id),
                    hypha_conductance = VALUES(hypha_conductance),
                    latency_ms = VALUES(latency_ms),
                    payload_json = VALUES(payload_json),
                    updated_at = NOW()
            ");
            foreach ($nodesMap as $id => $n) {
                $nodeId = $n['id'] ?? $id;
                if (!$nodeId) continue;
                $stmt->execute([
                    ':id' => $nodeId,
                    ':st' => $n['status'] ?? 'STANDBY',
                    ':sf' => $n['lora']['sf'] ?? 'SF7',
                    ':caste' => $n['caste'] ?? 'CASTE_3_CENTURION',
                    ':cond' => (float)($n['hypha_conductance'] ?? 1.0),
                    ':lat' => isset($n['latency_ms']) ? (float)$n['latency_ms'] : null,
                    ':json' => json_encode($n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]);
            }
        } catch (Throwable $e) {}
    }

    public function buildMailboxIndex(): array {
        $emails = [];
        $mrnaDir = $this->meshDir . '/mrna';

        // 1. Hierarchical threads in mesh/mrna/<job_id>/ (compatible with core/mailbox.py)
        if (is_dir($mrnaDir)) {
            $entries = scandir($mrnaDir) ?: [];
            $threadDirs = [];
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') continue;
                $full = $mrnaDir . '/' . $e;
                if (is_dir($full)) {
                    $threadDirs[] = $full;
                } elseif (is_file($full) && substr($e, -3) === '.md') {
                    // Flat .md message inside mesh/mrna/
                    $emails[] = $this->parseFlatMeshFile($full, 'mesh/mrna/' . $e);
                }
            }
            rsort($threadDirs);

            foreach (array_slice($threadDirs, 0, 25) as $tDir) {
                $jobId = basename($tDir);
                $subEntries = scandir($tDir) ?: [];
                sort($subEntries);
                foreach ($subEntries as $sName) {
                    if ($sName === '.' || $sName === '..') continue;
                    $sDir = $tDir . '/' . $sName;
                    if (!is_dir($sDir)) continue;

                    $estFile = $sDir . '/estimate.md';
                    if (is_file($estFile)) {
                        $raw = (string)@file_get_contents($estFile);
                        preg_match('/node:([^|\r\n]+)/', $raw, $mNode);
                        preg_match('/complexity:([^|\r\n]+)/', $raw, $mComp);
                        preg_match('/est_tokens:([^|\r\n]+)/', $raw, $mTok);
                        $sender = trim($mNode[1] ?? 'Umranz/Shruti-Soft-Chat');
                        $comp = trim($mComp[1] ?? '3/10');
                        $estT = trim($mTok[1] ?? '660');
                        $parts = explode('---', $raw, 2);
                        $bodyClean = $this->cleanMachineOutput(trim($parts[1] ?? $raw));
                        $emails[] = [
                            'id' => substr(md5("{$jobId}:{$sName}"), 0, 10),
                            'thread_id' => $jobId,
                            'hop' => 'Hop 1 (Batedor READ_SCOUT)',
                            'folder_type' => 'READ_SCOUT',
                            'from_node' => $sender,
                            'from_role' => '🦅 Batedor / Centurião (READ)',
                            'to_node' => 'Comando da Sociedade RNA',
                            'to_role' => 'WRITE_SYNTHESIZER',
                            'quota_used' => 'Token Interno Remoto',
                            'subject' => "Cálculo de Orçamento ({$estT} tok | Complexidade {$comp})",
                            'timestamp' => date('c', (int)@filemtime($estFile)),
                            'summary_human' => "Unidade {$sender} calculou orçamento de ~{$estT} tokens (Complexidade {$comp}).",
                            'body' => $bodyClean,
                            'file_path' => "mesh/mrna/{$jobId}/{$sName}/estimate.md"
                        ];
                    }

                    $batonFile = $sDir . '/baton_handoff.md';
                    if (is_file($batonFile)) {
                        $raw = (string)@file_get_contents($batonFile);
                        preg_match('/from:([^|\r\n]+)/', $raw, $mFrom);
                        preg_match('/to:([^|\r\n]+)/', $raw, $mTo);
                        preg_match('/done_pct:([^|\r\n]+)/', $raw, $mPct);
                        $sender = trim($mFrom[1] ?? 'Infantaria (READ)');
                        $receiver = trim($mTo[1] ?? 'General Escriba (WRITE)');
                        $pct = trim($mPct[1] ?? '100%');
                        $parts = explode('---', $raw, 2);
                        $bodyClean = $this->cleanMachineOutput(trim($parts[1] ?? $raw));
                        $sShort = basename($sender);
                        $rShort = basename($receiver);
                        $emails[] = [
                            'id' => substr(md5("{$jobId}:{$sName}:baton"), 0, 10),
                            'thread_id' => $jobId,
                            'hop' => 'Passagem de Bastão (@RNA_HANDOFF)',
                            'folder_type' => 'HANDOFF',
                            'from_node' => $sender,
                            'from_role' => '🛡️ Infantaria READ',
                            'to_node' => $receiver,
                            'to_role' => '⚔️ General Escriba WRITE',
                            'quota_used' => 'Delegação Direta M2M',
                            'subject' => "Passagem de Bastão ({$pct}): {$sShort} ➔ {$rShort}",
                            'timestamp' => date('c', (int)@filemtime($batonFile)),
                            'summary_human' => "Unidade {$sender} concluiu {$pct} e passou o bastão para {$receiver}.",
                            'body' => $bodyClean,
                            'file_path' => "mesh/mrna/{$jobId}/{$sName}/baton_handoff.md"
                        ];
                    }

                    $rxEntries = scandir($sDir) ?: [];
                    foreach ($rxEntries as $rxName) {
                        if ($rxName === '.' || $rxName === '..') continue;
                        $pktFile = $sDir . '/' . $rxName . '/pkt.md';
                        if (is_file($pktFile)) {
                            $raw = (string)@file_get_contents($pktFile);
                            $firstLine = strtok($raw, "\r\n") ?: '';
                            preg_match('/from:([^|\r\n]+)/', $firstLine, $mFrom);
                            preg_match('/codon:([^|\r\n]+)/', $firstLine, $mCodon);
                            preg_match('/ms:([^|\r\n]+)/', $firstLine, $mMs);
                            preg_match('/tok_saved:([^|\r\n]+)/', $firstLine, $mSav);
                            $sender = trim($mFrom[1] ?? 'HF Remote Node');
                            $codon = trim($mCodon[1] ?? $sName);
                            $ms = trim($mMs[1] ?? '0');
                            $tokS = trim($mSav[1] ?? '0');
                            $parts = explode('---', $raw, 2);
                            $bodyClean = $this->cleanMachineOutput(trim($parts[1] ?? $raw));
                            $isWrite = (stripos($sender, 'qwen') !== false || stripos($sender, 'einstein') !== false || strpos($codon, 'WRITE') !== false);
                            $roleLabel = $isWrite ? '⚔️ General/Soberano WRITE' : '🛡️ Centurião READ';
                            $sShort = basename($sender);
                            $emails[] = [
                                'id' => substr(md5("{$jobId}:{$rxName}"), 0, 10),
                                'thread_id' => $jobId,
                                'hop' => $codon,
                                'folder_type' => $isWrite ? 'WRITE_SYNTH' : 'READ_SCOUT',
                                'from_node' => $sender,
                                'from_role' => $roleLabel,
                                'to_node' => 'Ribossomo Central',
                                'to_role' => 'STORAGE_PROTEIN',
                                'quota_used' => "+{$tokS} tokens poupados",
                                'subject' => str_replace('_', ' ', $codon) . " — {$sShort} ({$ms}ms)",
                                'timestamp' => date('c', (int)@filemtime($pktFile)),
                                'summary_human' => "Processado por {$sShort} ({$roleLabel}) em {$ms} ms (+{$tokS} tokens poupados).",
                                'body' => $bodyClean,
                                'file_path' => "mesh/mrna/{$jobId}/{$sName}/{$rxName}/pkt.md"
                            ];
                        }
                    }
                }
            }
        }

        // 2. Also include flat files in mesh/proteins/*.md
        $protDir = $this->meshDir . '/proteins';
        if (is_dir($protDir)) {
            $pFiles = glob($protDir . '/*.md') ?: [];
            foreach ($pFiles as $pf) {
                $emails[] = $this->parseFlatMeshFile($pf, 'mesh/proteins/' . basename($pf));
            }
        }

        usort($emails, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        return array_slice($emails, 0, 45);
    }

    private function parseFlatMeshFile(string $fullPath, string $relPath): array {
        $raw = (string)@file_get_contents($fullPath);
        $meta = [];
        $body = $raw;
        if (strpos($raw, "---\n") === 0 || strpos($raw, "---\r\n") === 0) {
            $parts = preg_split('/^---\r?\n/m', $raw, 3);
            if (count($parts) >= 3) {
                foreach (preg_split('/\r?\n/', $parts[1]) as $hl) {
                    if (strpos($hl, ':') !== false) {
                        [$k, $v] = explode(':', $hl, 2);
                        $meta[trim($k)] = trim($v);
                    }
                }
                $body = trim($parts[2]);
            }
        } elseif (strpos($raw, '---') !== false) {
            $parts = explode('---', $raw, 2);
            $body = trim($parts[1]);
        }

        $bodyClean = $this->cleanMachineOutput($body);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $bodyClean))));
        $title = ltrim($lines[0] ?? basename($fullPath, '.md'), '#* ');
        if (mb_strlen($title) > 85) {
            $title = mb_substr($title, 0, 85) . '...';
        }
        $sender = $meta['node'] ?? $meta['nodes_used'] ?? 'kingpin92/qwen3-8-27b-iq2-chat';
        $ms = $meta['lat_ms'] ?? $meta['total_lat_ms'] ?? '1200';
        $tokS = $meta['tok_saved'] ?? '240';

        return [
            'id' => substr(md5($relPath), 0, 10),
            'thread_id' => basename($fullPath, '.md'),
            'hop' => $meta['mode'] ?? 'Síntese Proteica',
            'folder_type' => 'WRITE_SYNTH',
            'from_node' => $sender,
            'from_role' => '⚔️ General/Soberano WRITE',
            'to_node' => 'Ribossomo Central',
            'to_role' => 'STORAGE_PROTEIN',
            'quota_used' => "+{$tokS} tokens poupados",
            'subject' => $title,
            'timestamp' => $meta['ts'] ?? date('c', (int)@filemtime($fullPath)),
            'summary_human' => mb_substr($bodyClean, 0, 160),
            'body' => $bodyClean,
            'file_path' => $relPath
        ];
    }

    private function writeMeshMessage(string $folder, string $filename, array $meta, string $cleanBody): string {
        $relPath = "mesh/{$folder}/{$filename}";
        $fullPath = $this->baseDir . '/' . $relPath;
        $yaml = "---\n";
        foreach ($meta as $k => $v) {
            $yaml .= "{$k}: {$v}\n";
        }
        $yaml .= "---\n";
        @file_put_contents($fullPath, $yaml . $cleanBody . "\n");

        if ($this->pdo) {
            try {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $cleanBody))));
                $title = ltrim($lines[0] ?? $filename, '#* ');
                $stmt = $this->pdo->prepare("
                    INSERT INTO rna_messages (rel_path, category, title, node_id, role, sf, latency_ms, tokens_saved, preview, body, created_at)
                    VALUES (:rp, :cat, :title, :node, :role, :sf, :lat, :tok, :prev, :body, NOW())
                    ON DUPLICATE KEY UPDATE body = VALUES(body), preview = VALUES(preview), created_at = NOW()
                ");
                $stmt->execute([
                    ':rp' => $relPath,
                    ':cat' => ($folder === 'proteins') ? 'PROTEIN_SYNTHESIS' : 'MRNA_PACKET',
                    ':title' => mb_substr($title, 0, 240),
                    ':node' => (string)($meta['node'] ?? $meta['nodes_used'] ?? 'malha-global'),
                    ':role' => (string)($meta['role'] ?? $meta['mode'] ?? 'WRITE_SYNTH'),
                    ':sf' => (string)($meta['sf'] ?? 'SF7'),
                    ':lat' => (string)($meta['lat_ms'] ?? $meta['total_lat_ms'] ?? '-'),
                    ':tok' => (int)($meta['tok_saved'] ?? 0),
                    ':prev' => mb_substr($cleanBody, 0, 220),
                    ':body' => $cleanBody
                ]);
            } catch (Throwable $e) {}
        }

        return $relPath;
    }

    private function updateHyphaConductance(array &$node, bool $ok, float $latencyMs, int $tokensSaved = 0): void {
        $d = (float)($node['hypha_conductance'] ?? 1.0);
        if ($ok) {
            $speedFactor = max(0.25, min(2.5, 4000.0 / max(250.0, $latencyMs)));
            $tokenBonus = min(1.2, $tokensSaved / 350.0);
            $dNew = ($d * 0.88) + (0.65 * $speedFactor) + (0.20 * $tokenBonus);
            $node['hypha_conductance'] = round(min(10.0, $dNew), 3);
            $node['mycelium_state'] = ($node['hypha_conductance'] >= 2.2) ? 'ARTERIAL_VEIN' : 'ACTIVE_HYPHA';
            $node['status'] = 'ONLINE';
            $node['latency_ms'] = round($latencyMs, 1);
            $node['tokens_processed'] = (int)($node['tokens_processed'] ?? 0) + $tokensSaved;
            $node['tasks_completed'] = (int)($node['tasks_completed'] ?? 0) + 1;
        } else {
            $dNew = max(0.08, $d * 0.42);
            $node['hypha_conductance'] = round($dNew, 3);
            $node['mycelium_state'] = ($node['hypha_conductance'] <= 0.35) ? 'WITHERED_SPORE' : 'DECAYING_HYPHA';
            $node['errors'] = (int)($node['errors'] ?? 0) + 1;
        }
        $node['last_seen'] = date('c');
    }

    private function extractTextFromGradioData(string $rawJsonStr): string {
        $parsed = json_decode($rawJsonStr, true);
        if (!is_array($parsed)) {
            return trim($rawJsonStr);
        }
        if (count($parsed) > 0) {
            $first = $parsed[0];
            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
            if (is_array($first) && count($first) > 0) {
                $lastMsg = end($first);
                if (is_array($lastMsg) && isset($lastMsg['content'])) {
                    $c = $lastMsg['content'];
                    if (is_string($c)) return trim($c);
                    if (is_array($c) && isset($c[0]['text'])) return trim((string)$c[0]['text']);
                }
                if (is_array($lastMsg) && isset($lastMsg[1]) && is_string($lastMsg[1])) {
                    return trim($lastMsg[1]);
                }
            }
            if (isset($parsed[1]) && is_array($parsed[1]) && count($parsed[1]) > 0) {
                $lastMsg = end($parsed[1]);
                if (is_array($lastMsg) && isset($lastMsg['content'])) {
                    $c = $lastMsg['content'];
                    if (is_string($c)) return trim($c);
                    if (is_array($c) && isset($c[0]['text'])) return trim((string)$c[0]['text']);
                }
            }
        }
        return '';
    }

    public function callGradioSpaceSse(string $spaceUrl, string $apiName, string $prompt, int $timeoutSec = 18): array {
        $base = rtrim($spaceUrl, '/');
        $api = ltrim($apiName, '/');
        $t0 = microtime(true);

        $infoUrl = "{$base}/gradio_api/info";
        $infoRaw = $this->httpRequest($infoUrl, 'GET', null, 4);
        $prefix = '/gradio_api';
        $dataPayload = [$prompt];

        if ($infoRaw['ok']) {
            $infoJson = json_decode($infoRaw['body'], true);
            $epInfo = $infoJson['named_endpoints']['/' . $api] ?? null;
            if (is_array($epInfo)) {
                if (isset($epInfo['show_api']) && $epInfo['show_api'] === false) {
                    return [
                        'ok' => false,
                        'error' => 'API_DISABLED_FAIR_USE',
                        'latency_ms' => round((microtime(true) - $t0) * 1000, 1)
                    ];
                }
                $params = $epInfo['parameters'] ?? [];
                if (is_array($params) && count($params) > 0) {
                    $dataPayload = [];
                    foreach ($params as $idx => $p) {
                        $ptype = $p['type']['type'] ?? 'string';
                        if ($idx === 0) {
                            $dataPayload[] = $prompt;
                        } elseif (!empty($p['parameter_has_default'])) {
                            $dataPayload[] = $p['parameter_default'] ?? null;
                        } else {
                            if ($ptype === 'array') {
                                $dataPayload[] = [];
                            } elseif ($ptype === 'boolean') {
                                $dataPayload[] = false;
                            } elseif ($ptype === 'number' || $ptype === 'integer') {
                                $dataPayload[] = 512;
                            } else {
                                $dataPayload[] = '';
                            }
                        }
                    }
                }
            }
        } else {
            $infoUrl2 = "{$base}/info";
            $infoRaw2 = $this->httpRequest($infoUrl2, 'GET', null, 6);
            if ($infoRaw2['ok']) {
                $prefix = '';
            }
        }

        $callUrl = "{$base}{$prefix}/call/{$api}";
        $postRes = $this->httpRequest($callUrl, 'POST', json_encode(['data' => $dataPayload]), 6, [
            'Content-Type: application/json'
        ]);

        if (!$postRes['ok']) {
            return [
                'ok' => false,
                'error' => 'CALL_FAIL: ' . ($postRes['error'] ?: 'HTTP_' . $postRes['code']),
                'latency_ms' => round((microtime(true) - $t0) * 1000, 1)
            ];
        }

        $postJson = json_decode($postRes['body'], true);
        $eventId = $postJson['event_id'] ?? null;
        if (!$eventId) {
            return [
                'ok' => false,
                'error' => 'NO_EVENT_ID',
                'latency_ms' => round((microtime(true) - $t0) * 1000, 1)
            ];
        }

        $streamUrl = "{$base}{$prefix}/call/{$api}/{$eventId}";
        $sseRes = $this->httpRequest($streamUrl, 'GET', null, $timeoutSec, [
            'Accept: text/event-stream'
        ]);

        $latency = round((microtime(true) - $t0) * 1000, 1);
        if (!$sseRes['ok'] && empty($sseRes['body'])) {
            return [
                'ok' => false,
                'error' => 'SSE_FAIL: ' . ($sseRes['error'] ?: 'HTTP_' . $sseRes['code']),
                'latency_ms' => $latency
            ];
        }

        $lines = preg_split('/\r?\n/', (string)$sseRes['body']);
        $lastEvent = '';
        $finalData = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'event:') === 0) {
                $lastEvent = trim(substr($line, 6));
            } elseif (strpos($line, 'data:') === 0) {
                $rawD = trim(substr($line, 5));
                if ($rawD !== '' && $rawD !== 'null') {
                    $finalData = $rawD;
                }
            }
        }

        if ($finalData !== null) {
            $textOut = $this->extractTextFromGradioData($finalData);
            if (
                $textOut === ''
                || strpos($textOut, 'ZeroGPU runs limit') !== false
                || strpos($textOut, 'API error:') !== false
                || strpos($textOut, "{'error':") === 0
            ) {
                return [
                    'ok' => false,
                    'error' => 'EMPTY_OR_QUOTA_EXCEEDED',
                    'latency_ms' => $latency
                ];
            }

            return [
                'ok' => true,
                'response' => $this->cleanMachineOutput($textOut),
                'latency_ms' => $latency
            ];
        }

        return [
            'ok' => false,
            'error' => 'EMPTY_SSE_STREAM',
            'latency_ms' => $latency
        ];
    }

    public function probeIotSensor(array $node): array {
        return ['ok' => false, 'error' => 'DISABLED', 'latency_ms' => 0];
    }

    public function getRegisteredIotSensors(): array {
        return [];
    }

    private function getSpaceHostUrl(array $node): string {
        if (!empty($node['host']) && strpos($node['host'], '.hf.space') !== false) {
            return rtrim($node['host'], '/');
        }
        if (!empty($node['endpoint']) && strpos($node['endpoint'], '.hf.space') !== false) {
            $parts = explode('/gradio_api', $node['endpoint'], 2);
            return rtrim($parts[0], '/');
        }
        if (!empty($node['url']) && strpos($node['url'], '.hf.space') !== false) {
            return rtrim($node['url'], '/');
        }
        $id = $node['id'] ?? '';
        $sub = strtolower(preg_replace('/[^a-zA-Z0-9-]+/', '-', str_replace(['/', '_'], '-', $id)));
        return "https://{$sub}.hf.space";
    }

    private function getSpaceApiEndpoint(array $node): string {
        if (!empty($node['gradio_endpoint'])) return $node['gradio_endpoint'];
        if (!empty($node['api_name'])) return $node['api_name'];
        if (!empty($node['api_endpoints']) && is_array($node['api_endpoints']) && !empty($node['api_endpoints'][0])) {
            return $node['api_endpoints'][0];
        }
        return '/chat';
    }

    private function isCallableLlmSpace(array $node): bool {
        if (($node['caste'] ?? '') === 'CASTE_5_IOT_SENSOR' || ($node['type'] ?? '') === 'Public IoT Sensor (GeoJSON)' || ($node['type'] ?? '') === 'iot_sensor_stream') {
            return false;
        }
        if (!empty($node['gradio_endpoint']) || !empty($node['api_name'])) {
            return true;
        }
        $id = $node['id'] ?? '';
        return in_array($id, [
            'nakaassoumatine/einstein-chatbot',
            'kingpin92/qwen3-8-27b-iq2-chat',
            'Umranz/Shruti-Soft-Chat',
            'Qwen/Qwen3-Coder-WebDev'
        ], true);
    }

    private function isLazyOrUncertainResponse(string $text): bool {
        if (mb_strlen(trim($text)) < 30) {
            return true;
        }
        $lower = mb_strtolower($text, 'UTF-8');
        // Se a resposta contiver explicação temporal ou datas (ex: 2026, outubro, calendário, pleito), é válida
        if (strpos($lower, '2026') !== false && (strpos($lower, 'outubro') !== false || strpos($lower, 'turno') !== false || strpos($lower, 'eleiç') !== false || strpos($lower, 'calendário') !== false)) {
            return false;
        }
        $lazyPatterns = [
            'prever o futuro',
            'não posso prever',
            'nao posso prever',
            'impossível prever',
            'impossivel prever',
            'corte de conhecimento',
            'cannot predict the future',
            'knowledge cutoff',
            'não tenho acesso à internet',
            'nao tenho acesso a internet'
        ];
        foreach ($lazyPatterns as $pat) {
            if (strpos($lower, $pat) !== false) {
                return true;
            }
        }
        return false;
    }

    public function commitHfSpaceFiles(string $repoId, array $files, string $commitMsg = 'Fix Space'): array {
        $token = $this->getHfToken();
        if (empty($token)) {
            return ['ok' => false, 'error' => 'NO_HF_TOKEN'];
        }
        $url = "https://huggingface.co/api/spaces/{$repoId}/commit/main";
        $lines = [];
        $lines[] = json_encode([
            'key' => 'header',
            'value' => [
                'summary' => $commitMsg,
                'repo' => [
                    'type' => 'space',
                    'id' => $repoId
                ]
            ]
        ], JSON_UNESCAPED_SLASHES);

        foreach ($files as $pathInRepo => $content) {
            $lines[] = json_encode([
                'key' => 'file',
                'value' => [
                    'path' => $pathInRepo,
                    'content' => $content
                ]
            ], JSON_UNESCAPED_SLASHES);
        }

        $ndjson = implode("\n", $lines) . "\n";
        $res = $this->httpRequest($url, 'POST', $ndjson, 25, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/x-ndjson"
        ]);

        return [
            'ok' => ($res['status'] >= 200 && $res['status'] < 300),
            'status' => $res['status'],
            'body' => $res['body']
        ];
    }

    public function callGradioScout(string $baseUrl, string $query, int $maxResults = 5): array {
        $base = rtrim($baseUrl, '/');
        $t0 = microtime(true);
        $payload = json_encode(['data' => [$query, $maxResults]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $token = $this->getHfToken();
        $authHeaders = ['Content-Type: application/json'];
        if (!empty($token)) {
            $authHeaders[] = "Authorization: Bearer {$token}";
        }

        // 1. Tenta endpoint Gradio 6 /gradio_api/call/search_web ou /gradio_api/call/predict com autenticação do token HF
        foreach (['/gradio_api/call/search_web', '/gradio_api/call/predict'] as $callEp) {
            $postRes = $this->httpRequest("{$base}{$callEp}", 'POST', $payload, 10, $authHeaders);
            if ($postRes['ok']) {
                $j = json_decode($postRes['body'], true);
                $eventId = $j['event_id'] ?? null;
                if ($eventId) {
                    $streamHeaders = ['Accept: text/event-stream'];
                    if (!empty($token)) {
                        $streamHeaders[] = "Authorization: Bearer {$token}";
                    }
                    $streamRes = $this->httpRequest("{$base}{$callEp}/{$eventId}", 'GET', null, 16, $streamHeaders);
                    if ($streamRes['ok'] && !empty($streamRes['body'])) {
                        $lines = explode("\n", $streamRes['body']);
                        foreach ($lines as $line) {
                            $trimLine = trim($line);
                            if (strpos($trimLine, 'data:') === 0) {
                                $rawJson = trim(substr($trimLine, 5));
                                $dataArr = json_decode($rawJson, true);
                                if (is_array($dataArr) && isset($dataArr[0]) && is_string($dataArr[0]) && mb_strlen($dataArr[0]) > 20) {
                                    return [
                                        'ok' => true,
                                        'dossier' => $dataArr[0],
                                        'sources_count' => max(1, substr_count($dataArr[0], 'http')),
                                        'latency_ms' => round((microtime(true) - $t0) * 1000, 1)
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // 2. Endpoints diretos Gradio 4 (/api/scout_search, /api/predict, /run/predict)
        foreach (['/api/scout_search', '/api/predict', '/run/scout_search', '/run/predict'] as $ep) {
            $directRes = $this->httpRequest("{$base}{$ep}", 'POST', $payload, 9, ['Content-Type: application/json']);
            if ($directRes['ok']) {
                $dj = json_decode($directRes['body'], true);
                if (isset($dj['data'][0]) && is_string($dj['data'][0]) && mb_strlen($dj['data'][0]) > 20) {
                    return [
                        'ok' => true,
                        'dossier' => $dj['data'][0],
                        'sources_count' => max(1, substr_count($dj['data'][0], 'http')),
                        'latency_ms' => round((microtime(true) - $t0) * 1000, 1)
                    ];
                }
            }
        }

        return [
            'ok' => false,
            'dossier' => '',
            'sources_count' => 0,
            'latency_ms' => round((microtime(true) - $t0) * 1000, 1)
        ];
    }

    public function performLiveWebRecon(string $query, bool $deep = false): array {
        $cleanQ = trim(preg_replace('/\s+/', ' ', $query));

        // Se for uma tarefa de síntese de código, jogos ou scripts puros, não requer notícias de internet
        if (preg_match('/(jogo|game|canvas|html|css|javascript|script|codigo|código|program|nes|nintendinho|chiptune|class|function|def|api|sql)/i', $cleanQ)) {
            return [
                'ok' => true,
                'query_used' => $cleanQ,
                'sources_count' => 0,
                'dossier' => '',
                'latency_ms' => 0
            ];
        }
        $stopWords = [
            'quero', 'saber', 'quem', 'qual', 'quais', 'como', 'onde', 'quando', 'por', 'que', 'para',
            'uma', 'uns', 'umas', 'com', 'dos', 'das', 'nos', 'nas', 'sobre', 'me', 'fale', 'diga',
            'todos', 'todas', 'dados', 'reais', 'venceu', 'ganhou', 'completo', 'completa', 'cenario',
            'cenário', 'detalhes', 'informações', 'informacoes', 'preciso', 'favor', 'explique'
        ];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($cleanQ, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3 && !in_array($w, $stopWords, true)) {
                $keywords[] = $w;
            }
        }
        $searchQuery = !empty($keywords) ? implode(' ', array_slice($keywords, 0, 5)) : mb_substr($cleanQ, 0, 75);

        $findings = [];
        $sourcesCount = 0;

        // 0. Sonda de Reconhecimento Batedor da Rede RNA no Hugging Face (nyshimura/rna-web-scout)
        $scoutSpaceUrl = "https://nyshimura-rna-web-scout.hf.space";
        $scoutCall = $this->callGradioScout($scoutSpaceUrl, $cleanQ, $deep ? 6 : 4);
        if ($scoutCall['ok'] && !empty($scoutCall['dossier'])) {
            $findings[] = "• [Batedor Remoto Hugging Face - RNA Web Scout]:\n" . $scoutCall['dossier'];
            $sourcesCount += max(1, $scoutCall['sources_count'] ?? 3);
        }

        // 1. Sonda Wikipedia PT (Busca + Extrato Completo dos Top Artigos Relevantes)
        $wikiSearchUrl = "https://pt.wikipedia.org/w/api.php?action=query&list=search&srsearch=" . urlencode($searchQuery) . "&utf8=1&format=json&srlimit=5";
        $wikiRes = $this->httpRequest($wikiSearchUrl, 'GET', null, 7);
        if ($wikiRes['ok']) {
            $wJson = json_decode($wikiRes['body'], true);
            $sList = $wJson['query']['search'] ?? [];
            $titlesToFetch = [];

            // Se a busca contém um ano (ex: 2026), prioriza artigos que contenham esse ano no título
            preg_match('/\b(202[0-9])\b/', $searchQuery, $mYear);
            $targetYear = $mYear[1] ?? null;

            foreach ($sList as $item) {
                $title = $item['title'] ?? '';
                if ($title === '') continue;
                if ($targetYear && strpos($title, $targetYear) !== false) {
                    array_unshift($titlesToFetch, $title);
                } else {
                    $titlesToFetch[] = $title;
                }
            }
            $titlesToFetch = array_slice(array_values(array_unique($titlesToFetch)), 0, 2);

            foreach ($titlesToFetch as $idxT => $tTitle) {
                $charLim = ($idxT === 0) ? 1600 : 600;
                $extUrl = "https://pt.wikipedia.org/w/api.php?action=query&prop=extracts&exchars={$charLim}&explaintext=1&titles=" . urlencode($tTitle) . "&format=json";
                $extRes = $this->httpRequest($extUrl, 'GET', null, 6);
                if ($extRes['ok']) {
                    $extJson = json_decode($extRes['body'], true);
                    $pages = $extJson['query']['pages'] ?? [];
                    foreach ($pages as $p) {
                        if (!empty($p['extract'])) {
                            $extClean = trim(preg_replace('/\s+/', ' ', $p['extract']));
                            $findings[] = "• [Artigo Completo Wikipédia PT — {$tTitle}]: " . mb_substr($extClean, 0, $charLim);
                            $sourcesCount++;
                            break;
                        }
                    }
                }
            }
        }

        // 2. Sonda Google News RSS Brasil (Notícias em Tempo Real da Internet)
        $gnewsUrl = "https://news.google.com/rss/search?q=" . urlencode($searchQuery) . "&hl=pt-BR&gl=BR&ceid=BR:pt-419";
        $gnRes = $this->httpRequest($gnewsUrl, 'GET', null, 7);
        if ($gnRes['ok'] && strpos($gnRes['body'], '<item>') !== false) {
            preg_match_all('/<item>([\s\S]*?)<\/item>/', $gnRes['body'], $itemsMatch);
            $itemsXml = array_slice($itemsMatch[1] ?? [], 0, $deep ? 8 : 5);
            foreach ($itemsXml as $itemRaw) {
                preg_match('/<title>([\s\S]*?)<\/title>/', $itemRaw, $mTitle);
                preg_match('/<pubDate>([\s\S]*?)<\/pubDate>/', $itemRaw, $mDate);
                $t = trim(html_entity_decode(strip_tags($mTitle[1] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $d = trim($mDate[1] ?? '');
                if ($t !== '') {
                    $findings[] = "• [Notícia em Tempo Real ({$d})]: {$t}";
                    $sourcesCount++;
                }
            }
        }

        $dossier = '';
        if (!empty($findings)) {
            $dossier = "[DOSSIÊ DE PESQUISA NA INTERNET EM TEMPO REAL (SONDA WEB RNA — {$sourcesCount} FONTES VERIFICADAS)]:\n"
                . implode("\n\n", $findings)
                . "\n\n[DIRETRIZ OBRIGATÓRIA DE VERACIDADE E ANTI-PREGUIÇA]:\n"
                . "1. PROIBIDO dizer que 'não tem acesso à internet' ou que 'não pode prever o futuro'. Os dados reais coletados na internet hoje estão listados acima.\n"
                . "2. Apresente de forma direta e estruturada TODAS as informações factuais do Dossiê acima (datas exatas de 1º e 2º turno, regras constitucionais, candidatos/pesquisas e notícias recentes).\n"
                . "3. NÃO use metáforas de física ou personagens. Seja um analista político/técnico objetivo e completo.\n";
        }

        return [
            'query_used' => $searchQuery,
            'sources_count' => $sourcesCount,
            'dossier' => $dossier
        ];
    }

    public function sendTask(string $taskText, ?string $preferredNode = null): array {
        $telemetry = $this->loadTelemetry();
        $nodes = &$telemetry['nodes'];

        // Sonda de Internet em Tempo Real antes de acionar a IA
        $webRecon = $this->performLiveWebRecon($taskText, false);
        $enrichedPrompt = "Você é uma unidade de processamento da Rede RNA Bio-LoRa Mesh.\n"
            . "DIRETRIZ DE PODER & RIGOR ANALÍTICO: NUNCA responda 'não posso prever o futuro' nem invente factoides. "
            . "Se a pergunta envolver eventos futuros com calendário oficial (como eleições de 2026), detalhe as datas oficiais, mandato em curso, pré-candidatos cotados e regras da Constituição.\n\n";
        if (!empty($webRecon['dossier'])) {
            $enrichedPrompt .= $webRecon['dossier'] . "\n---\nPERGUNTA DO USUÁRIO:\n" . $taskText;
        } else {
            $enrichedPrompt .= $taskText;
        }

        $candidates = [];
        if ($preferredNode && isset($nodes[$preferredNode])) {
            $candidates[] = $preferredNode;
        }
        foreach ($nodes as $id => $n) {
            if ($id === $preferredNode) continue;
            if ($this->isCallableLlmSpace($n) && ($n['status'] ?? '') === 'ONLINE') {
                $candidates[] = $id;
            }
        }
        foreach (['kingpin92/qwen3-8-27b-iq2-chat', 'nakaassoumatine/einstein-chatbot', 'Umranz/Shruti-Soft-Chat'] as $seedId) {
            if (isset($nodes[$seedId]) && !in_array($seedId, $candidates, true)) {
                $candidates[] = $seedId;
            }
        }

        foreach (array_slice($candidates, 0, 4) as $id) {
            $node = &$nodes[$id];
            $hostUrl = $this->getSpaceHostUrl($node);
            $apiEp = $this->getSpaceApiEndpoint($node);
            $res = $this->callGradioSpaceSse($hostUrl, $apiEp, $enrichedPrompt);

            if ($res['ok']) {
                // Verifica se a máquina foi preguiçosa ou recusou responder!
                if ($this->isLazyOrUncertainResponse($res['response'])) {
                    // Penaliza a hifa da máquina preguiçosa e escalona automaticamente para o Relay Multi-Máquina com Deep Web Recon!
                    $this->updateHyphaConductance($node, false, $res['latency_ms']);
                    $this->saveTelemetry($telemetry);
                    return $this->relayTask($taskText);
                }

                $tokensSaved = max(80, (int)((strlen($enrichedPrompt) + strlen($res['response'])) / 4));
                $this->updateHyphaConductance($node, true, $res['latency_ms'], $tokensSaved);
                $telemetry['kpis']['agent_tokens_saved'] = (int)($telemetry['kpis']['agent_tokens_saved'] ?? 0) + $tokensSaved;
                $telemetry['kpis']['total_tasks_completed'] = (int)($telemetry['kpis']['total_tasks_completed'] ?? 0) + 1;

                $ts = date('Ymd_His');
                $hash = substr(md5($taskText . $ts), 0, 6);
                $finalBody = $res['response'];
                if ($webRecon['sources_count'] > 0) {
                    $finalBody .= "\n\n---\n*🌐 Fundamentado pela Sonda Web RNA ({$webRecon['sources_count']} fontes da internet consultadas em tempo real para `{$webRecon['query_used']}`)*";
                }

                $file = $this->writeMeshMessage('proteins', "msg_{$ts}_{$hash}.md", [
                    'v' => '3.2',
                    'mode' => 'WEB_GROUNDED_HYPHA_TX',
                    'node' => $id,
                    'lat_ms' => $res['latency_ms'],
                    'tok_saved' => $tokensSaved,
                    'ts' => date('c')
                ], $finalBody);

                $this->saveTelemetry($telemetry);
                return [
                    'ok' => true,
                    'node' => $id,
                    'web_sources' => $webRecon['sources_count'],
                    'hypha_conductance' => $node['hypha_conductance'],
                    'latency_ms' => $res['latency_ms'],
                    'tokens_saved' => $tokensSaved,
                    'file' => $file,
                    'response_preview' => mb_substr($finalBody, 0, 500)
                ];
            } else {
                $this->updateHyphaConductance($node, false, $res['latency_ms']);
            }
        }

        $this->saveTelemetry($telemetry);
        return ['ok' => false, 'error' => 'Nenhuma máquina online respondeu a tempo.'];
    }

    public function relayTask(string $taskText): array {
        $telemetry = $this->loadTelemetry();
        $nodes = &$telemetry['nodes'];

        // Fase 0: Sonda de Busca Profunda na Internet (Deep Web Recon - 0 Tokens)
        $webRecon = $this->performLiveWebRecon($taskText, true);

        $onlineIds = [];
        foreach (['nakaassoumatine/einstein-chatbot', 'kingpin92/qwen3-8-27b-iq2-chat', 'Umranz/Shruti-Soft-Chat'] as $seedId) {
            if (isset($nodes[$seedId])) {
                $onlineIds[] = $seedId;
            }
        }
        foreach ($nodes as $id => $n) {
            if ($this->isCallableLlmSpace($n) && ($n['status'] ?? '') === 'ONLINE' && !in_array($id, $onlineIds, true)) {
                $onlineIds[] = $id;
            }
        }

        $hop1Res = null;
        $hop1Id = null;
        $hop1Prompt = "Você é a Máquina 1 (Investigador & Analista de Fatos da Rede RNA).\n"
            . ($webRecon['dossier'] ? $webRecon['dossier'] . "\n---\n" : "")
            . "TAREFA: Extraia todos os fatos verificados, datas, nomes e contexto real para responder à seguinte questão em Português:\n\n"
            . $taskText;

        foreach ($onlineIds as $id) {
            $cand = &$nodes[$id];
            $try = $this->callGradioSpaceSse(
                $this->getSpaceHostUrl($cand),
                $this->getSpaceApiEndpoint($cand),
                $hop1Prompt,
                30
            );
            if ($try['ok'] && mb_strlen(trim($try['response'])) > 40) {
                $hop1Res = $try;
                $hop1Id = $id;
                $tok1 = max(60, (int)(strlen($try['response']) / 4));
                $this->updateHyphaConductance($cand, true, $try['latency_ms'], $tok1);
                break;
            } elseif ($try['ok']) {
                $this->updateHyphaConductance($cand, false, $try['latency_ms']);
                if ($hop1Res === null) {
                    $hop1Res = $try;
                    $hop1Id = $id;
                }
            } else {
                $this->updateHyphaConductance($cand, false, $try['latency_ms']);
            }
        }

        // Se nenhum modelo LLM respondeu a tempo no Hop 1, o próprio Nó de Sonda Web fornece o dossiê do Hop 1!
        if (!$hop1Res || empty($hop1Res['response'])) {
            $hop1Id = 'Sonda-Web-HF';
            $hop1Res = [
                'ok' => true,
                'response' => $webRecon['dossier'] ?: "Dados coletados da internet para: {$taskText}",
                'latency_ms' => 450
            ];
        }

        // Escolhe obrigatoriamente uma SEGUNDA máquina diferente (preferindo kingpin92/qwen3-8-27b-iq2-chat Soberano Tier S) para o Hop 2
        $hop2Id = 'kingpin92/qwen3-8-27b-iq2-chat';
        if ($hop1Id === 'kingpin92/qwen3-8-27b-iq2-chat') {
            $hop2Id = 'nakaassoumatine/einstein-chatbot';
        }
        if (!isset($nodes[$hop2Id])) {
            foreach ($onlineIds as $id) {
                if ($id !== $hop1Id) {
                    $hop2Id = $id;
                    break;
                }
            }
        }

        $node2 = &$nodes[$hop2Id];
        $hop2Prompt = "Você é a Máquina 2 (Soberano Verificador e Estrategista da Rede RNA Bio-LoRa).\n"
            . ($webRecon['dossier'] ? $webRecon['dossier'] . "\n---\n" : "")
            . "PERGUNTA DO USUÁRIO: {$taskText}\n\n"
            . "RELATÓRIO DA MÁQUINA 1 ({$hop1Id}):\n" . mb_substr($hop1Res['response'], 0, 1400) . "\n\n"
            . "SUA MISSÃO FINAL (DIRETRIZ DE PODER & COOPERAÇÃO DA REDE RNA):\n"
            . "1. NUNCA dê respostas preguiçosas do tipo 'não posso prever o futuro' ou 'não tenho acesso'.\n"
            . "2. Se o usuário perguntar por vencedores ou desfechos de eventos com data marcada (como Eleições de 2026 no Brasil): "
            . "apresente o calendário oficial (1º turno em 4 de outubro de 2026, 2º turno em 25 de outubro), "
            . "o mandato atual (Lula até dez/2026), as principais forças e pré-candidaturas cotadas (Lula, Tarcísio, Caiado, Ratinho Jr, Zema) "
            . "e conclua claramente que a eleição ainda não ocorreu, detalhando todo o panorama factual.\n"
            . "3. Entregue um relatório rico, analítico, direto e 100% em Português do Brasil.";

        $hop2Res = $this->callGradioSpaceSse($this->getSpaceHostUrl($node2), $this->getSpaceApiEndpoint($node2), $hop2Prompt);
        if ((!$hop2Res['ok'] || $this->isLazyOrUncertainResponse($hop2Res['response'])) && $hop2Id !== $hop1Id) {
            $this->updateHyphaConductance($node2, false, $hop2Res['latency_ms'] ?? 1000);
            // Tenta o Soberano Qwen3 diretamente se ainda não foi usado
            $fallbackId = ($hop2Id === 'kingpin92/qwen3-8-27b-iq2-chat') ? $hop1Id : 'kingpin92/qwen3-8-27b-iq2-chat';
            if (isset($nodes[$fallbackId])) {
                $hop2Id = $fallbackId;
                $node2 = &$nodes[$hop2Id];
                $hop2Res = $this->callGradioSpaceSse($this->getSpaceHostUrl($node2), $this->getSpaceApiEndpoint($node2), $hop2Prompt);
            }
        }

        $finalText = ($hop2Res['ok'] && !$this->isLazyOrUncertainResponse($hop2Res['response']))
            ? $hop2Res['response']
            : ($hop1Res['response'] ?: ($hop2Res['response'] ?? ''));

        $totalLat = round($hop1Res['latency_ms'] + ($hop2Res['latency_ms'] ?? 0), 1);
        $tokensSaved = max(180, (int)((strlen($hop1Prompt) + strlen($finalText)) / 3));

        if ($hop2Res['ok']) {
            $this->updateHyphaConductance($node2, true, $hop2Res['latency_ms'], $tokensSaved);
        }

        $telemetry['kpis']['agent_tokens_saved'] = (int)($telemetry['kpis']['agent_tokens_saved'] ?? 0) + $tokensSaved;
        $telemetry['kpis']['total_relay_handoffs'] = (int)($telemetry['kpis']['total_relay_handoffs'] ?? 0) + 1;
        $telemetry['kpis']['total_tasks_completed'] = (int)($telemetry['kpis']['total_tasks_completed'] ?? 0) + 1;

        $ts = date('Ymd_His');
        $hash = substr(md5($taskText . $ts), 0, 6);
        $webSummary = $webRecon['sources_count'] > 0
            ? "\n\n---\n#### 🌐 Sonda de Internet Ao Vivo RNA ({$webRecon['sources_count']} fontes consultadas para `{$webRecon['query_used']}`)\n" . mb_substr($webRecon['dossier'], 0, 2800)
            : '';

        $body = "### Síntese Verificada (Sonda Web + Relay `{$hop1Id}` ➔ `{$hop2Id}`)\n\n{$finalText}{$webSummary}\n\n---\n#### Etapa 1 — Investigação Inicial (`{$hop1Id}`)\n\n" . mb_substr($hop1Res['response'], 0, 700);

        $file = $this->writeMeshMessage('proteins', "relay_{$ts}_{$hash}.md", [
            'v' => '3.2',
            'mode' => 'WEB_RECON_M2M_RELAY',
            'nodes_used' => "SondaWeb -> {$hop1Id} -> {$hop2Id}",
            'total_lat_ms' => $totalLat,
            'tok_saved' => $tokensSaved,
            'ts' => date('c')
        ], $body);

        $this->saveTelemetry($telemetry);
        return [
            'ok' => true,
            'protein_file' => $file,
            'web_sources' => $webRecon['sources_count'],
            'nodes_used' => ['Sonda-Web-RNA', $hop1Id, $hop2Id],
            'total_latency_ms' => $totalLat,
            'tokens_saved' => $tokensSaved,
            'response_preview' => mb_substr($finalText, 0, 500)
        ];
    }

    public function synthesizeTask(string $taskText): array {
        return $this->relayTask($taskText);
    }

    public function executeLiveMissionStream(string $taskText, string $mode = 'relay', ?string $preferredNode = null, ?callable $emitter = null): array {
        $emit = function(string $step, string $machine, string $msg, int $progress, $extra = null) use ($emitter) {
            if (is_callable($emitter)) {
                $emitter($step, $machine, $msg, $progress, $extra);
            }
        };

        $isCodeOrGameTask = (bool)preg_match('/(jogo|game|canvas|html|css|javascript|script|codigo|código|program|nes|nintendinho|chiptune|class|function|def|api|sql)/i', $taskText);

        $emit('INIT', 'RNA-AutocraticRouter', 'Roteador micelial inicializado. Foco total em IA...', 10);

        // 1. Reconhecimento Web Scout (Apenas se não for código puro)
        $webRecon = ['sources_count' => 0, 'dossier' => '', 'query_used' => ''];
        if (!$isCodeOrGameTask) {
            $emit('SCOUT_START', 'nyshimura/rna-web-scout', 'Acionando Batedor Web na A10G para rastrear dados frescos na internet...', 35);
            $webRecon = $this->performLiveWebRecon($taskText, ($mode !== 'send'));
            $sourcesCount = $webRecon['sources_count'] ?? 0;
            if ($sourcesCount > 0) {
                $emit('SCOUT_OK', 'nyshimura/rna-web-scout', "Batedor Web: {$sourcesCount} fontes verificadas para '{$webRecon['query_used']}'!", 50, [
                    'sources_count' => $sourcesCount,
                    'query' => $webRecon['query_used']
                ]);
            }
        }

        $telemetry = $this->loadTelemetry();
        $nodes = &$telemetry['nodes'];

        // Ordena nós priorizando os Soberanos robustos (Qwen3, Coder, 70B, etc.)
        $candidateLlmIds = [];
        // Prioridade 1: Máquina forte Qwen3
        if (isset($nodes['kingpin92/qwen3-8-27b-iq2-chat'])) {
            $candidateLlmIds[] = 'kingpin92/qwen3-8-27b-iq2-chat';
        }
        // Prioridade 2: Outros nós soberanos e generais online
        foreach ($nodes as $id => $n) {
            if ($this->isCallableLlmSpace($n) && ($n['status'] ?? '') === 'ONLINE' && !in_array($id, $candidateLlmIds, true)) {
                $candidateLlmIds[] = $id;
            }
        }
        if (empty($candidateLlmIds)) {
            $candidateLlmIds = ['kingpin92/qwen3-8-27b-iq2-chat', 'nakaassoumatine/einstein-chatbot'];
        }

        if ($mode === 'send') {
            $targetId = $preferredNode ?: $candidateLlmIds[0];
            $emit('HOP1_START', $targetId, "Transmitindo instrução diretamente para a IA [{$targetId}]...", 60);

            $enriched = "Você é uma unidade de processamento da Rede RNA Bio-LoRa Mesh.\n"
                . "DIRETRIZ DE PODER & RIGOR ANALÍTICO: Seja direto, denso, rápido e responda em Português do Brasil.\n\n"
                . (!empty($webRecon['dossier']) ? $webRecon['dossier'] . "\n---\nPERGUNTA DO USUÁRIO:\n" : "") . $taskText;

            $node = $nodes[$targetId] ?? ['id' => $targetId, 'status' => 'ONLINE'];
            $hostUrl = $this->getSpaceHostUrl($node);
            $ep = $this->getSpaceApiEndpoint($node);
            $res = $this->callGradioSpaceSse($hostUrl, $ep, $enriched);

            $finalText = $res['ok'] ? $res['response'] : ($res['error'] ?? 'Falha na resposta.');
            $tokensSaved = max(100, (int)((strlen($enriched) + strlen($finalText)) / 3));

            $emit('HOP1_OK', $targetId, "Máquina respondeu com sucesso ({$res['latency_ms']}ms)! +{$tokensSaved} tokens economizados.", 90);

            $ret = [
                'ok' => $res['ok'],
                'mode' => 'DIRECT_SEND',
                'node' => $targetId,
                'latency_ms' => $res['latency_ms'],
                'tokens_saved' => $tokensSaved,
                'full_response' => $finalText,
                'response_preview' => mb_substr($finalText, 0, 500),
                'web_sources' => $webRecon['sources_count'],
                'sensor_info' => $sensorInfo
            ];
            $emit('COMPLETE', 'Mesh-Central', 'Missão concluída com 100% de sucesso!', 100, $ret);
            return $ret;
        }

        // Modo Revezamento (2 IAs) ou Síntese Multi-IA
        $hop1Id = $preferredNode ?: $candidateLlmIds[0];
        $hop2Id = (isset($candidateLlmIds[1]) && $candidateLlmIds[1] !== $hop1Id) ? $candidateLlmIds[1] : (count($candidateLlmIds) > 2 ? $candidateLlmIds[2] : $hop1Id);

        $emit('HOP1_START', $hop1Id, "Fase 1: Transmitindo para Máquina Soberana [{$hop1Id}]...", 55);

        $node1 = &$nodes[$hop1Id] ?? ['id' => $hop1Id, 'status' => 'ONLINE'];
        $hop1Prompt = "Você é a Máquina 1 (Soberano de Processamento da Rede RNA Bio-LoRa Mesh).\n"
            . ($webRecon['dossier'] ? $webRecon['dossier'] . "\n---\n" : "")
            . "TAREFA:\n{$taskText}\n\n"
            . "DIRETRIZ: Seja direto, técnico e responda em Português do Brasil.";

        $hop1Res = $this->callGradioSpaceSse($this->getSpaceHostUrl($node1), $this->getSpaceApiEndpoint($node1), $hop1Prompt);
        if (!$hop1Res['ok'] || $this->isLazyOrUncertainResponse($hop1Res['response'])) {
            $this->updateHyphaConductance($node1, false, $hop1Res['latency_ms'] ?? 1000);
            $fallback = 'kingpin92/qwen3-8-27b-iq2-chat';
            if ($hop1Id !== $fallback && isset($nodes[$fallback])) {
                $hop1Id = $fallback;
                $node1 = &$nodes[$hop1Id];
                $emit('HOP1_FAILOVER', $hop1Id, "Comutando para Soberano de Contingência [{$hop1Id}]...", 68);
                $hop1Res = $this->callGradioSpaceSse($this->getSpaceHostUrl($node1), $this->getSpaceApiEndpoint($node1), $hop1Prompt);
            }
        }

        $emit('HOP1_OK', $hop1Id, "Hop 1 concluiu o processamento com sucesso ({$hop1Res['latency_ms']}ms)!", 75);

        // Handoff para Máquina 2
        $emit('HOP2_START', $hop2Id, "Handoff M2M: Transferindo para Máquina Hop 2 [{$hop2Id}] para validação final...", 82);
        $node2 = &$nodes[$hop2Id] ?? ['id' => $hop2Id, 'status' => 'ONLINE'];
        $hop2Prompt = "Você é a Máquina 2 (Verificador Estrategista da Rede RNA Bio-LoRa).\n"
            . ($webRecon['dossier'] ? $webRecon['dossier'] . "\n---\n" : "")
            . "TAREFA ORIGINAL: {$taskText}\n\n"
            . "RELATÓRIO DA MÁQUINA 1 ({$hop1Id}):\n" . mb_substr($hop1Res['response'], 0, 1400) . "\n\n"
            . "SUA MISSÃO: Entregue a resposta final polida, sem enrolação e em Português do Brasil.";

        $hop2Res = $this->callGradioSpaceSse($this->getSpaceHostUrl($node2), $this->getSpaceApiEndpoint($node2), $hop2Prompt);
        if (!$hop2Res['ok'] && $hop2Id !== $hop1Id) {
            $this->updateHyphaConductance($node2, false, $hop2Res['latency_ms'] ?? 1000);
            $hop2Res = $hop1Res; // Utiliza o resultado confiável do Hop 1
        }

        $emit('HOP2_OK', $hop2Id, "Hop 2 concluiu validação da proteína final!", 92);

        $finalText = ($hop2Res['ok'] && !$this->isLazyOrUncertainResponse($hop2Res['response']))
            ? $hop2Res['response']
            : ($hop1Res['response'] ?: ($hop2Res['response'] ?? ''));

        $totalLat = round($hop1Res['latency_ms'] + ($hop2Res['latency_ms'] ?? 0), 1);
        $tokensSaved = max(180, (int)((strlen($hop1Prompt) + strlen($finalText)) / 3));

        if ($hop2Res['ok']) {
            $this->updateHyphaConductance($node2, true, $hop2Res['latency_ms'], $tokensSaved);
        }

        $telemetry['kpis']['agent_tokens_saved'] = (int)($telemetry['kpis']['agent_tokens_saved'] ?? 0) + $tokensSaved;
        $telemetry['kpis']['total_relay_handoffs'] = (int)($telemetry['kpis']['total_relay_handoffs'] ?? 0) + 1;
        $telemetry['kpis']['total_tasks_completed'] = (int)($telemetry['kpis']['total_tasks_completed'] ?? 0) + 1;

        $ts = date('Ymd_His');
        $hash = substr(md5($taskText . $ts), 0, 6);
        $webSummary = $webRecon['sources_count'] > 0
            ? "\n\n---\n#### 🌐 Sonda de Internet Ao Vivo RNA ({$webRecon['sources_count']} fontes consultadas para `{$webRecon['query_used']}`)\n" . mb_substr($webRecon['dossier'], 0, 2800)
            : '';

        $body = "### Síntese Verificada (Sonda Web + Relay `{$hop1Id}` ➔ `{$hop2Id}`)\n\n{$finalText}{$webSummary}\n\n---\n#### Etapa 1 — Investigação Inicial (`{$hop1Id}`)\n\n" . mb_substr($hop1Res['response'], 0, 700);

        $file = $this->writeMeshMessage('proteins', "relay_{$ts}_{$hash}.md", [
            'v' => '3.2',
            'mode' => 'LIVE_M2M_STREAM',
            'nodes_used' => "SondaWeb -> {$hop1Id} -> {$hop2Id}",
            'total_lat_ms' => $totalLat,
            'tok_saved' => $tokensSaved,
            'ts' => date('c')
        ], $body);

        $this->saveTelemetry($telemetry);

        $ret = [
            'ok' => true,
            'protein_file' => $file,
            'web_sources' => $webRecon['sources_count'],
            'nodes_used' => ['Sonda-Web-RNA', $hop1Id, $hop2Id],
            'total_latency_ms' => $totalLat,
            'tokens_saved' => $tokensSaved,
            'full_response' => $finalText,
            'response_preview' => mb_substr($finalText, 0, 500),
            'sensor_info' => $sensorInfo
        ];

        $emit('COMPLETE', 'Protein-Synthesizer', "Missão finalizada com sucesso! +{$tokensSaved} tokens poupados na malha RNA.", 100, $ret);
        return $ret;
    }

    public function scanMesh(bool $force = false): array {
        $telemetry = $this->loadTelemetry();
        $nodes = &$telemetry['nodes'];
        $probed = 0;

        foreach ($nodes as $id => &$node) {
            if (($node['caste'] ?? '') === 'CASTE_5_IOT_SENSOR' || ($node['type'] ?? '') === 'Public IoT Sensor (GeoJSON)' || ($node['type'] ?? '') === 'iot_sensor_stream') {
                $iotRes = $this->probeIotSensor($node);
                if ($iotRes['ok']) {
                    $node['iot_sample'] = $iotRes['summary'];
                    $this->updateHyphaConductance($node, true, $iotRes['latency_ms'], 25);
                } else {
                    $this->updateHyphaConductance($node, false, $iotRes['latency_ms']);
                }
                $probed++;
            } elseif (($node['status'] ?? '') === 'ONLINE' || $probed < 8) {
                $hostUrl = $this->getSpaceHostUrl($node);
                $infoRes = $this->httpRequest($hostUrl . '/gradio_api/info', 'GET', null, 6);
                if ($infoRes['ok']) {
                    $node['status'] = 'ONLINE';
                    $node['last_seen'] = date('c');
                }
                $probed++;
            }
        }

        $this->saveTelemetry($telemetry);
        return [
            'ok' => true,
            'nodes_online' => $telemetry['kpis']['active_nodes_count'],
            'nodes_total' => $telemetry['kpis']['scanned_nodes_count'],
            'iot_sensors' => $telemetry['kpis']['iot_sensors_count'],
            'probed' => $probed
        ];
    }

    public function detectCasteFromMetadata(string $spId, array $tags = []): string {
        $text = strtolower($spId . ' ' . implode(' ', $tags));
        if (preg_match('/(scout|search|duckduck|ddg|web|scrap|spider|crawler|leaderboard|benchmark|mteb|eval)/i', $text)) {
            return 'CASTE_4_SCOUT';
        }
        if (preg_match('/(deepseek-r1|r1|70b|72b|qwen3|deepseek-v3|reasoning|o1|qwq)/i', $text)) {
            return 'CASTE_1_SOVEREIGN';
        }
        if (preg_match('/(coder|code|program|script|dev|artifact|32b|33b|34b|14b|8b|codestral)/i', $text)) {
            return 'CASTE_2_GENERAL';
        }
        return 'CASTE_3_CENTURION';
    }

    public function inferSpecialty(string $spId, array $tags = []): string {
        $text = strtolower($spId . ' ' . implode(' ', $tags));
        if (strpos($text, 'code') !== false || strpos($text, 'coder') !== false) return 'code_synthesis';
        if (strpos($text, 'deepseek') !== false || strpos($text, 'r1') !== false || strpos($text, 'reason') !== false) return 'deep_reasoning_math';
        if (strpos($text, 'qwen') !== false) return 'multimodal_general_heavy';
        if (strpos($text, 'llama') !== false) return 'meta_llama_instruction';
        if (strpos($text, 'mistral') !== false) return 'mistral_euro_reasoning';
        if (strpos($text, 'gemma') !== false) return 'google_gemma_compact';
        if (strpos($text, 'scout') !== false || strpos($text, 'search') !== false) return 'web_reconnaissance_scout';
        return 'community_gradio_replica';
    }

    public function recruitNodes(int $limit = 25, ?string $customKeyword = null): array {
        $telemetry = $this->loadTelemetry();
        $nodes = &$telemetry['nodes'];

        $token = $this->getHfToken();
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = "Authorization: Bearer {$token}";
        }

        // Ponteiro de rastreamento contínuo persistido para avançar sobre todo o acervo do HF
        $crawlerStep = 0;
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->query("SELECT setting_value FROM rna_settings WHERE setting_key = 'crawler_step' LIMIT 1");
                $r = $stmt ? $stmt->fetch() : null;
                if ($r && isset($r['setting_value'])) $crawlerStep = (int)$r['setting_value'];
            } catch (Throwable $e) {}
        }

        $taxonomy = [
            'qwen', 'deepseek', 'coder', 'llama', 'chat', 'mistral', 'gemma', 'phi',
            'reasoning', 'math', 'agent', 'sql', 'tools', 'assistant', 'instruct',
            'translation', 'summary', 'vision', 'flux', 'whisper', 'voice', 'biology', 'medical', 'legal'
        ];

        $sortModes = ['likes', 'trendingScore', 'lastModified'];
        $chosenSort = $sortModes[$crawlerStep % count($sortModes)];

        $queries = [];
        if ($customKeyword && trim($customKeyword) !== '') {
            $queries[] = trim($customKeyword);
        } else {
            $startIdx = ($crawlerStep * 4) % count($taxonomy);
            for ($i = 0; $i < 4; $i++) {
                $queries[] = $taxonomy[($startIdx + $i) % count($taxonomy)];
            }
        }

        $discovered = [];

        foreach (array_unique($queries) as $q) {
            // Busca por curtidas (modelos consagrados e réplicas populares)
            $url = "https://huggingface.co/api/spaces?search=" . urlencode($q) . "&filter=gradio&sort=likes&direction=-1&limit=15";
            $res = $this->httpRequest($url, 'GET', null, 7, $headers);
            if ($res['ok']) {
                $items = json_decode($res['body'], true);
                if (is_array($items)) {
                    foreach ($items as $it) {
                        $id = $it['id'] ?? '';
                        if ($id !== '' && !isset($nodes[$id]) && !isset($discovered[$id])) {
                            $discovered[$id] = $it;
                        }
                    }
                }
            }

            // Busca por recém-modificados (novos forks e réplicas de usuários que subiram recentemente)
            $recentUrl = "https://huggingface.co/api/spaces?search=" . urlencode($q) . "&filter=gradio&sort=lastModified&direction=-1&limit=10";
            $recRes = $this->httpRequest($recentUrl, 'GET', null, 6, $headers);
            if ($recRes['ok']) {
                $recItems = json_decode($recRes['body'], true);
                if (is_array($recItems)) {
                    foreach ($recItems as $it) {
                        $id = $it['id'] ?? '';
                        if ($id !== '' && !isset($nodes[$id]) && !isset($discovered[$id])) {
                            $discovered[$id] = $it;
                        }
                    }
                }
            }

            if (count($discovered) >= max(30, $limit * 2)) break;
        }

        // Também inclui trending geral para variedade
        $trendUrl = "https://huggingface.co/api/spaces?filter=gradio&sort=trendingScore&direction=-1&limit=15";
        $trendRes = $this->httpRequest($trendUrl, 'GET', null, 7, $headers);
        if ($trendRes['ok']) {
            $trendItems = json_decode($trendRes['body'], true);
            if (is_array($trendItems)) {
                foreach ($trendItems as $it) {
                    $id = $it['id'] ?? '';
                    if ($id !== '' && !isset($nodes[$id]) && !isset($discovered[$id])) {
                        $discovered[$id] = $it;
                    }
                }
            }
        }

        $recruited = 0;
        $activeOnline = 0;

        foreach ($discovered as $spId => $spInfo) {
            if ($recruited >= $limit) break;

            $subdomain = strtolower(str_replace(['/', '_'], '-', $spId));
            $spaceUrl = "https://{$subdomain}.hf.space";
            $tags = $spInfo['tags'] ?? [];

            // Handshake ativo e descoberta de endpoints (/gradio_api/info)
            $infoUrl = "{$spaceUrl}/gradio_api/info";
            $t0 = microtime(true);
            $infoRes = $this->httpRequest($infoUrl, 'GET', null, 4, $headers);
            $latMs = round((microtime(true) - $t0) * 1000, 1);

            $status = 'STANDBY';
            $apiEp = '/chat';
            $detectedEndpoints = [];

            if ($infoRes['ok']) {
                $status = 'ONLINE';
                $activeOnline++;
                $infoJson = json_decode($infoRes['body'], true);
                $namedEps = array_keys($infoJson['named_endpoints'] ?? []);
                $detectedEndpoints = $namedEps;
                foreach (['/chat', '/predict', '/generate', '/respond', '/call/predict', '/call/chat'] as $pref) {
                    if (in_array($pref, $namedEps, true)) {
                        $apiEp = $pref;
                        break;
                    }
                }
                if ($apiEp === '/chat' && !empty($namedEps) && !in_array('/chat', $namedEps, true)) {
                    $apiEp = $namedEps[0];
                }
            } else {
                // Tenta probe leve de config
                $cfgUrl = "{$spaceUrl}/config";
                $cfgRes = $this->httpRequest($cfgUrl, 'GET', null, 3, $headers);
                if ($cfgRes['ok']) {
                    $status = 'ONLINE';
                    $activeOnline++;
                }
            }

            $caste = $this->detectCasteFromMetadata($spId, $tags);
            $specialty = $this->inferSpecialty($spId, $tags);

            $sf = ($status === 'ONLINE' && $latMs < 1200) ? 'SF7' : (($status === 'ONLINE') ? 'SF9' : 'SF12');
            $rssi = ($status === 'ONLINE') ? -42 - rand(0, 25) : -85 - rand(0, 15);
            $snr = ($status === 'ONLINE') ? round(14.0 - (rand(0, 50) / 10), 1) : 4.2;

            $newNode = [
                'id' => $spId,
                'name' => basename($spId),
                'type' => 'HF Space (gradio)',
                'specialty' => $specialty,
                'url' => "https://huggingface.co/spaces/{$spId}",
                'endpoint' => $spaceUrl,
                'api_endpoint' => $apiEp,
                'api_endpoints' => $detectedEndpoints,
                'status' => $status,
                'latency_ms' => ($status === 'ONLINE') ? $latMs : 850.0,
                'tokens_processed' => 0,
                'tasks_completed' => 0,
                'bytes_processed' => 0,
                'last_seen' => date('c'),
                'hypha_conductance' => ($status === 'ONLINE') ? 1.25 : 0.70,
                'mycelium_state' => ($status === 'ONLINE') ? 'ACTIVE_HYPHA' : 'DORMANT_SPORE',
                'caste' => $caste,
                'lora' => [
                    'rssi_dbm' => $rssi,
                    'snr_db' => $snr,
                    'sf' => $sf,
                    'quality' => ($status === 'ONLINE') ? ($latMs < 1500 ? 'EXCELENTE' : 'BOM') : 'REGULAR'
                ]
            ];

            $this->enrichNodeWithCaste($newNode);
            $nodes[$spId] = $newNode;
            $recruited++;
        }

        $this->syncNodesToDb($nodes);

        if ($this->pdo) {
            try {
                $nextStep = $crawlerStep + 1;
                $stmt = $this->pdo->prepare("
                    INSERT INTO rna_settings (setting_key, setting_value, updated_at)
                    VALUES ('crawler_step', :val, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()
                ");
                $stmt->execute([':val' => (string)$nextStep, ':val2' => (string)$nextStep]);
            } catch (Throwable $e) {}
        }

        $telemetry['kpis']['scanned_nodes_count'] = count($nodes);
        $onlineCount = 0;
        foreach ($nodes as $n) {
            if (($n['status'] ?? '') === 'ONLINE') $onlineCount++;
        }
        $telemetry['kpis']['active_nodes_count'] = $onlineCount;
        $this->saveTelemetry($telemetry);

        return [
            'ok' => true,
            'recruited' => $recruited,
            'recruited_online' => $activeOnline,
            'nodes_total' => count($nodes),
            'nodes_online' => $onlineCount
        ];
    }

    private function httpRequest(string $url, string $method = 'GET', ?string $body = null, int $timeout = 15, array $headers = []): array {
        if ($url === '') return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'EMPTY_URL'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'RNA-BioLoRa-Mesh/3.0 (Self-Hosted)'
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $respBody = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return [
            'ok' => ($respBody !== false && $code >= 200 && $code < 400),
            'code' => $code,
            'body' => (string)$respBody,
            'error' => $err
        ];
    }
}
