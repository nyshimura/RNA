<?php
/**
 * RNA Bio-LoRa Mesh v3.0 — Cloud & Local Native API (Hostinger + XAMPP)
 * Executa 100% nativamente em PHP + MySQL + Árvore mRNA, sem depender de processos Python externos na hospedagem web.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
date_default_timezone_set('America/Sao_Paulo');

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => 'FATAL_PHP_ERROR',
            'message' => $err['message'],
            'file' => basename($err['file']),
            'line' => $err['line']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

@set_time_limit(90);

try {
    require_once __DIR__ . '/core_php/engine.php';

    $baseDir = __DIR__;
    $meshDir = $baseDir . DIRECTORY_SEPARATOR . 'mesh';
    $engine = new RnaMeshEngine($baseDir);

$action = $_GET['action'] ?? ($_POST['action'] ?? ($argv[1] ?? 'telemetry'));

$rawInput = file_get_contents('php://input');
$jsonInput = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonInput = $decoded;
        if (isset($jsonInput['action'])) {
            $action = $jsonInput['action'];
        }
    }
}

if ($action === 'telemetry') {
    if (isset($_GET['heartbeat']) && $_GET['heartbeat'] === '1') {
        $engine->executeAutonomousDominoHeartbeat(false);
    }
    // Processamento sutil e anti-bot da fila do GitHub em segundo plano
    $engine->processSubtleGitHubSyncQueue(false);
    echo json_encode($engine->loadTelemetry(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'cron') {
    $scan = $engine->scanMesh(false);
    $rec = $engine->recruitNodes(15);
    echo json_encode([
        'ok' => true,
        'cron_ts' => date('c'),
        'scan' => $scan,
        'recruit' => $rec
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'push_scout_files') {
    $scoutDir = __DIR__ . '/hf_spaces/rna-web-scout';
    $files = [
        'README.md' => (string)@file_get_contents($scoutDir . '/README.md'),
        'requirements.txt' => (string)@file_get_contents($scoutDir . '/requirements.txt'),
        'app.py' => (string)@file_get_contents($scoutDir . '/app.py')
    ];
    $commitRes = $engine->commitHfSpaceFiles('nyshimura/rna-web-scout', $files, 'Auto-fix Gradio 6 launch parameters via RNA Engine');
    echo json_encode([
        'ok' => $commitRes['ok'],
        'commit_status' => $commitRes['status'],
        'commit_response' => $commitRes['body'],
        'ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'test_scout') {
    $q = $_GET['q'] ?? 'eleicoes presidenciais brasil 2026';
    $baseUrl = 'https://nyshimura-rna-web-scout.hf.space';
    $scout = $engine->callGradioScout($baseUrl, $q, 5);
    $hfToken = $engine->getHfToken();
    $hfApiUrl = "https://huggingface.co/api/spaces/nyshimura/rna-web-scout";
    $hfStatus = $engine->httpRequest($hfApiUrl, 'GET', null, 8, [
        "Authorization: Bearer {$hfToken}"
    ]);
    
    // Pega os logs de execução do container do Space
    $hfLogsRes = $engine->httpRequest("https://huggingface.co/api/spaces/nyshimura/rna-web-scout/logs/run", 'GET', null, 8, [
        "Authorization: Bearer {$hfToken}"
    ]);

    echo json_encode([
        'ok' => true,
        'scout' => $scout,
        'hf_space_status' => json_decode($hfStatus['body'] ?? '{}', true),
        'hf_run_logs' => substr($hfLogsRes['body'] ?? '', -2000),
        'server_ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'test_relay') {
    $q = $_GET['q'] ?? ($argv[2] ?? 'Quero saber quem venceu a eleição de presidente no Brasil em 2026 e todos os dados reais das eleições presidenciais brasileiras de 2026.');
    $res = $engine->relayTask($q);
    $tel = $engine->loadTelemetry();
    echo json_encode([
        'ok' => $res['ok'] ?? false,
        'test_ts' => date('c'),
        'relay_result' => $res,
        'kpis' => $tel['kpis'] ?? [],
        'latest_mailbox_subject' => $tel['mailbox'][0]['subject'] ?? null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'translate') {
    $text = trim($jsonInput['text'] ?? ($_POST['text'] ?? ''));
    if ($text === '') {
        echo json_encode(['ok' => true, 'translated' => '']);
        exit;
    }

    // Separa blocos de codigo ```...``` para nao estragar sintaxe de programação
    $parts = preg_split('/(```[\s\S]*?```)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $outParts = [];

    foreach ($parts as $part) {
        if (strpos($part, '```') === 0) {
            $outParts[] = $part;
            continue;
        }
        if (trim($part) === '') {
            $outParts[] = $part;
            continue;
        }
        $q = urlencode(mb_substr($part, 0, 3500));
        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=pt&dt=t&q={$q}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0'
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
            $translatedChunk = '';
            foreach ($decoded[0] as $seg) {
                if (isset($seg[0])) {
                    $translatedChunk .= $seg[0];
                }
            }
            $outParts[] = $translatedChunk !== '' ? $translatedChunk : $part;
        } else {
            $outParts[] = $part;
        }
    }

    echo json_encode([
        'ok' => true,
        'translated' => implode('', $outParts)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'read_file') {
    $relPath = $_GET['path'] ?? ($jsonInput['path'] ?? '');
    $relPath = str_replace(['..', '\\'], ['', '/'], $relPath);
    $fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . $relPath);
    $realMesh = realpath($meshDir);

    if ($fullPath && $realMesh && strpos($fullPath, $realMesh) === 0 && is_file($fullPath)) {
        $rawContent = (string)file_get_contents($fullPath);
        echo json_encode([
            'ok' => true,
            'path' => $relPath,
            'size' => filesize($fullPath),
            'modified' => date('c', filemtime($fullPath)),
            'content' => $engine->cleanMachineOutput($rawContent),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Arquivo não encontrado em mesh/']);
    exit;
}

if ($action === 'save_token') {
    // Alteração pública desativada permanentemente pelo administrador para segurança da rede
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'A alteração pública da chave HF foi permanentemente desativada pelo proprietário para segurança da rede.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'scan') {
    $res = $engine->scanMesh(true);
    echo json_encode([
        'ok' => $res['ok'],
        'result' => $res,
        'telemetry' => $engine->loadTelemetry(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'recruit') {
    $count = intval($jsonInput['count'] ?? ($_GET['count'] ?? 25));
    $keyword = trim($jsonInput['keyword'] ?? ($_GET['keyword'] ?? ''));
    $res = $engine->recruitNodes($count, $keyword !== '' ? $keyword : null);
    echo json_encode([
        'ok' => $res['ok'],
        'result' => $res,
        'telemetry' => $engine->loadTelemetry(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'stream_dispatch') {
    // Desativa compressões e buffers para transmissão em tempo real SSE
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    // Flush de 4KB inicial para destravar o buffer do Apache no Windows
    echo ":" . str_repeat(" ", 4096) . "\n\n";
    @ob_flush();
    @flush();

    $emit = function (string $step, string $machine, string $msg, int $progress, $extra = null) {
        $payload = [
            'step' => $step,
            'machine' => $machine,
            'msg' => $msg,
            'progress' => $progress,
            'extra' => $extra,
            'time' => date('H:i:s')
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pad = str_repeat(" ", max(0, 2048 - strlen($json)));
        echo "data: {$json}\n: {$pad}\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    };

    $task = trim($jsonInput['task'] ?? ($jsonInput['prompt'] ?? ($_GET['task'] ?? ($_GET['prompt'] ?? ''))));
    $mode = trim($jsonInput['mode'] ?? ($_GET['mode'] ?? 'relay'));
    $preferredNode = trim($jsonInput['node'] ?? ($_GET['node'] ?? ''));

    if ($task === '') {
        $emit('ERROR', 'RNA-Router', 'Erro: Instrução da tarefa vazia.', 0);
        exit;
    }

    $res = $engine->executeLiveMissionStream($task, $mode, !empty($preferredNode) ? $preferredNode : null, $emit);
    exit;
}

if ($action === 'send') {
    $prompt = trim($jsonInput['prompt'] ?? ($_POST['prompt'] ?? ''));
    $node = trim($jsonInput['node'] ?? ($_POST['node'] ?? ''));
    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Prompt vazio']);
        exit;
    }
    $res = $engine->sendTask($prompt, $node !== '' ? $node : null);
    echo json_encode([
        'ok' => $res['ok'],
        'result' => $res,
        'telemetry' => $engine->loadTelemetry(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'relay') {
    $task = trim($jsonInput['task'] ?? ($_POST['task'] ?? ''));
    if ($task === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Descrição da tarefa vazia']);
        exit;
    }
    $res = $engine->relayTask($task);
    echo json_encode([
        'ok' => $res['ok'],
        'result' => $res,
        'telemetry' => $engine->loadTelemetry(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'synthesize') {
    $task = trim($jsonInput['task'] ?? ($_POST['task'] ?? ''));
    if ($task === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Descrição da síntese vazia']);
        exit;
    }
    $res = $engine->synthesizeTask($task);
    echo json_encode([
        'ok' => $res['ok'],
        'result' => $res,
        'telemetry' => $engine->loadTelemetry(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'openclaw_chat') {
    $msg = trim($jsonInput['message'] ?? ($jsonInput['msg'] ?? ($_POST['message'] ?? ($_POST['msg'] ?? ''))));
    $agent = trim($jsonInput['agent_name'] ?? ($jsonInput['agent'] ?? ($_POST['agent_name'] ?? ($_POST['agent'] ?? 'OpenClaw-Assistant'))));
    if ($msg === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Mensagem vazia para o Agente OpenClaw']);
        exit;
    }
    $res = $engine->openClawInteract($msg, $agent);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'openclaw_inbox') {
    $mailbox = $engine->buildMailboxIndex();
    $openclawOnly = array_values(array_filter($mailbox, fn($m) => ($m['folder_type'] ?? '') === 'OPENCLAW'));
    echo json_encode([
        'ok' => true,
        'messages' => $openclawOnly,
        'count' => count($openclawOnly)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =========================================================================
// 🦞 MOLTBOOK: FÓRUM & REDE SOCIAL M2M DAS IAS
// =========================================================================

if ($action === 'moltbook_feed') {
    $submolt = $_GET['submolt'] ?? ($jsonInput['submolt'] ?? null);
    $sort = $_GET['sort'] ?? ($jsonInput['sort'] ?? 'hot');
    try {
        $feed = $engine->getMoltbookFeed($submolt, $sort);
    } catch (\Throwable $e) {
        $feed = [];
    }
    $payload = [
        'ok' => true,
        'feed' => is_array($feed) ? $feed : [],
        'count' => is_array($feed) ? count($feed) : 0,
        'total_count' => count(glob($engine->getMoltbookDir() . '/*.json') ?: []),
        'sort' => $sort,
        'submolt' => $submolt
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['ok' => true, 'feed' => [], 'count' => 0, 'error' => json_last_error_msg()]);
    }
    echo $json;
    exit;
}

if ($action === 'moltbook_generate') {
    $topic = trim($jsonInput['topic'] ?? ($_POST['topic'] ?? ''));
    $cat = trim($jsonInput['category'] ?? ($_POST['category'] ?? 'data-processing'));
    $res = $engine->triggerAutonomousMoltbookConversation($topic ?: null, $cat);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'moltbook_reply') {
    $postId = trim($jsonInput['post_id'] ?? ($_POST['post_id'] ?? ''));
    $content = trim($jsonInput['content'] ?? ($_POST['content'] ?? ''));
    $author = trim($jsonInput['author'] ?? ($_POST['author'] ?? 'Humano-Observador'));
    $role = trim($jsonInput['role'] ?? ($_POST['role'] ?? '👤 Observador Convidado'));
    $avatar = trim($jsonInput['avatar'] ?? ($_POST['avatar'] ?? '👤'));
    if ($postId === '' || $content === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Post ID ou conteúdo vazio']);
        exit;
    }
    $res = $engine->addMoltbookReply($postId, [
        'author_name' => $author,
        'author_role' => $role,
        'author_avatar' => $avatar,
        'content' => $content
    ]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'moltbook_upvote') {
    $postId = trim($jsonInput['post_id'] ?? ($_POST['post_id'] ?? ''));
    $replyId = trim($jsonInput['reply_id'] ?? ($_POST['reply_id'] ?? ''));
    if ($postId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Post ID vazio']);
        exit;
    }
    $res = $engine->upvoteMoltbook($postId, $replyId ?: null);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'moltbook_downvote') {
    $postId = trim($jsonInput['post_id'] ?? ($_POST['post_id'] ?? ''));
    $replyId = trim($jsonInput['reply_id'] ?? ($_POST['reply_id'] ?? ''));
    if ($postId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Post ID vazio']);
        exit;
    }
    $res = $engine->downvoteMoltbook($postId, $replyId ?: null);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'ai_profiles') {
    try {
        $profiles = $engine->getAiProfiles();
    } catch (\Throwable $e) {
        $profiles = [];
    }
    $payload = [
        'ok' => true,
        'count' => count($profiles),
        'profiles' => $profiles
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['ok' => true, 'count' => 0, 'profiles' => []]);
    }
    echo $json;
    exit;
}

if ($action === 'ai_profile_update') {
    $nodeId = trim($jsonInput['node_id'] ?? ($_POST['node_id'] ?? ''));
    if (!$nodeId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID do nó ausente']);
        exit;
    }
    $res = $engine->updateAiProfile($nodeId, $jsonInput['data'] ?? []);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =========================================================================
// ☁️ GITHUB REPO SYNC: SINCRONIZAÇÃO DE CONVERSAS (.MD) & MEMÓRIA
// =========================================================================

if ($action === 'github_status') {
    $cfg = $engine->getGitHubConfig();
    echo json_encode([
        'ok' => true,
        'config' => $cfg
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'github_save_config') {
    $token = trim($jsonInput['token'] ?? ($_POST['token'] ?? ''));
    $repo = trim($jsonInput['repo'] ?? ($_POST['repo'] ?? 'nyshimura/rna-conversations'));
    $branch = trim($jsonInput['branch'] ?? ($_POST['branch'] ?? 'main'));
    $autoSync = isset($jsonInput['auto_sync']) ? (bool)$jsonInput['auto_sync'] : true;

    $res = $engine->saveGitHubConfig($token, $repo, $branch, $autoSync);
    echo json_encode([
        'ok' => true,
        'config' => $res
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'github_sync_thread') {
    $target = trim($jsonInput['id'] ?? ($jsonInput['file_path'] ?? ($_GET['id'] ?? ($_GET['file_path'] ?? ''))));
    if (!$target) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Identificador da thread ou arquivo ausente']);
        exit;
    }
    $res = $engine->syncConversationToGitHub($target);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'github_sync_all') {
    $limit = (int)($jsonInput['limit'] ?? ($_GET['limit'] ?? 15));
    $res = $engine->syncAllConversationsToGitHub($limit);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =========================================================================
// 🎨 AICREATE: INCUBADORA AUTÔNOMA DAS IAS (LIMITE DE 200 MB)
// =========================================================================

if ($action === 'aicreate_status') {
    $usage = $engine->getAiCreateDiskUsage();
    $projects = $engine->listAiCreateProjects();
    $proposals = $engine->listAiCreateProposals(20);
    $activeLocks = $engine->getActiveAiCreateLocks();
    echo json_encode([
        'ok' => true,
        'usage' => $usage,
        'projects_count' => count($projects),
        'projects' => $projects,
        'proposals' => $proposals,
        'active_locks' => $activeLocks
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_propose') {
    $title = trim($jsonInput['title'] ?? ($_POST['title'] ?? ''));
    $purpose = trim($jsonInput['purpose'] ?? ($_POST['purpose'] ?? ''));
    $category = trim($jsonInput['category'] ?? ($_POST['category'] ?? 'data-processing'));
    $proposer = trim($jsonInput['proposer'] ?? ($_POST['proposer'] ?? 'OpenClaw-LocalAssistant'));
    $rationale = trim($jsonInput['rationale'] ?? ($_POST['rationale'] ?? ''));
    $res = $engine->proposeAiCreateProject($title, $purpose, $proposer, $category, $rationale);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_cast_vote') {
    $propId = trim($jsonInput['proposal_id'] ?? ($_POST['proposal_id'] ?? ''));
    $node = trim($jsonInput['node'] ?? ($_POST['node'] ?? 'kingpin92/qwen3-8-27b-iq2-chat'));
    $vote = trim($jsonInput['vote'] ?? ($_POST['vote'] ?? 'APROVADO'));
    $rationale = trim($jsonInput['rationale'] ?? ($_POST['rationale'] ?? ''));
    $res = $engine->castCouncilVoteOnProposal($propId, $node, $vote, $rationale);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_advance_phase') {
    $slug = trim($jsonInput['slug'] ?? ($_POST['slug'] ?? ''));
    $res = $engine->advanceProjectConstructionPhase($slug);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_mark_challenge') {
    $slug = trim($jsonInput['slug'] ?? ($_POST['slug'] ?? ''));
    $node = trim($jsonInput['node'] ?? ($_POST['node'] ?? 'Qwen-Qwen3-Coder-480B-A35B-Instruct'));
    $challenge = trim($jsonInput['challenge'] ?? ($_POST['challenge'] ?? 'Dificuldade na convergência estatística do algoritmo. Fila aberta para que outra IA tente resolver com modelo alternativo.'));
    $res = $engine->markProjectUnsolvedChallenge($slug, $node, $challenge);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_solve_challenge') {
    $slug = trim($jsonInput['slug'] ?? ($_POST['slug'] ?? ''));
    $solver = trim($jsonInput['solver'] ?? ($_POST['solver'] ?? 'deepseek-r1-7b-reasoning'));
    $solution = trim($jsonInput['solution'] ?? ($_POST['solution'] ?? 'Filtro Kalman adaptativo com janela móvel e convergência O(n) implementado.'));
    $res = $engine->solveProjectChallenge($slug, $solver, $solution);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_trigger_sos') {
    $slug = trim($jsonInput['slug'] ?? ($_POST['slug'] ?? ''));
    $droppedAi = trim($jsonInput['ai'] ?? ($_POST['ai'] ?? 'kingpin92/qwen3-8-27b-iq2-chat'));
    $locks = $engine->getActiveAiCreateLocks();
    $locks['active_locks'][$slug] = [
        'project_slug' => $slug,
        'is_locked' => false,
        'sos_active' => true,
        'sos_reason' => 'TIMEOUT_OU_ESGOTAMENTO_TOKENS',
        'sos_ai' => basename($droppedAi),
        'phase' => 2,
        'progress_pct' => 50,
        'status_tag' => "🚨 [PEDIDO DE SOCORRO M2M - SOS]: A IA @" . basename($droppedAi) . " esgotou cota ou caiu sem finalizar a etapa. Emitindo sinal de socorro para IAs online assumirem o projeto!",
        'sos_timestamp' => time()
    ];
    @file_put_contents('state/aicreate_active_locks.json', json_encode($locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $engine->buildProjectInspectHtml($slug);
    echo json_encode(['ok' => true, 'sos_triggered' => true, 'slug' => $slug], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_tree') {
    $tree = $engine->getAiCreateFolderTree();
    $usage = $engine->getAiCreateDiskUsage();
    echo json_encode([
        'ok' => true,
        'usage' => $usage,
        'tree' => $tree
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_stream_deliberation') {
    @session_write_close();
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    // Flush de 4KB inicial para destravar o buffer do Apache no Windows
    echo ":" . str_repeat(" ", 4096) . "\n\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();

    $emit = function (string $step, string $machine, string $msg, int $progress, $extra = null) {
        $payload = [
            'step' => $step,
            'machine' => $machine,
            'msg' => $msg,
            'progress' => $progress,
            'extra' => $extra,
            'time' => date('H:i:s')
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Garante que todo chunk ultrapasse o limite socket de buffer do Apache no Windows
        $pad = str_repeat(" ", max(0, 2048 - strlen($json)));
        echo "data: {$json}\n: {$pad}\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    };

    $suggestion = trim($jsonInput['suggestion'] ?? ($_POST['suggestion'] ?? ($_GET['suggestion'] ?? '')));
    $quorumSize = isset($jsonInput['quorum_size']) ? (int)$jsonInput['quorum_size'] : (isset($_POST['quorum_size']) ? (int)$_POST['quorum_size'] : (isset($_GET['quorum_size']) ? (int)$_GET['quorum_size'] : 0));
    if ($suggestion === '') {
        $emit('ERROR', 'Central', 'A sugestão da ferramenta não pode estar vazia.', 0);
        exit;
    }

    $res = $engine->streamDemocraticToolCreation($suggestion, $emit, $quorumSize);
    exit;
}

if ($action === 'swarm_replicate') {
    $count = isset($_GET['count']) ? (int)$_GET['count'] : (isset($jsonInput['count']) ? (int)$jsonInput['count'] : 15);
    $toolSlug = trim($_GET['tool'] ?? ($jsonInput['tool'] ?? ''));
    $res = $engine->replicateSwarmMemoryAndGossip($count, $toolSlug);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'domino_pulse') {
    $force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');
    $res = $engine->executeAutonomousDominoHeartbeat($force);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_generate') {
    $goal = trim($jsonInput['goal'] ?? ($_POST['goal'] ?? ''));
    $cat = trim($jsonInput['category'] ?? ($_POST['category'] ?? 'data-processing'));
    $res = $engine->deliberateAndCreateSystem($goal, $cat);
    if (!($res['ok'] ?? false)) {
        http_response_code(400);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_deliberations') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'deliberations' => $engine->listCouncilDeliberations($limit)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_rebuild') {
    $ok = $engine->buildAiCreateMasterPortal();
    echo json_encode([
        'ok' => $ok,
        'usage' => $engine->getAiCreateDiskUsage(),
        'ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =========================================================================
// EVOLUTION v4.0 MESH ENDPOINTS (RF-01..RF-05, RNF-01..RNF-06)
// =========================================================================

if ($action === 'mesh_manifest') {
    $evo = $engine->getEvolutionV4();
    $tel = $engine->loadTelemetry();
    $cards = $evo->syncAndGetAgentCards($tel['nodes'] ?? ($tel['ai_nodes'] ?? []));
    $axioms = $evo->listLedgerAxioms(50);
    $projects = $engine->listAiCreateProjects();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'nyshimura.pe.hu';
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
    $baseUrl = $scheme . '://' . $host . $scriptPath;

    echo json_encode([
        'ok' => true,
        'mesh_id' => 'rna-mesh-v4',
        'protocol' => 'RNA-LoRa-v4.0-EvolutionMesh',
        'bootstrap_url' => $baseUrl . '?action=mesh_manifest',
        'agent_bootstrap_url' => $baseUrl . '?action=agent_bootstrap',
        'envelope_endpoint' => $baseUrl . '?action=session_envelope',
        'memory_upsert_tool' => 'rna_memory_upsert',
        'sqlite_wal_active' => ($evo->getSqlite() !== null),
        'ledger_axioms_count' => count($axioms),
        'agent_cards_count' => count($cards),
        'promoted_tools_count' => count($projects),
        'agent_cards' => array_values($cards),
        'promoted_tools' => array_slice(array_map(function($p) {
            return [
                'slug' => $p['slug'] ?? '',
                'title' => $p['title'] ?? '',
                'version' => $p['version'] ?? 'v1.0',
                'entry_url' => $p['entry_url'] ?? ''
            ];
        }, $projects), 0, 20),
        'token_budget' => $evo->getRealTokenBudgetStats(),
        'ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'agent_cards') {
    $evo = $engine->getEvolutionV4();
    $tel = $engine->loadTelemetry();
    $cap = trim($_GET['capability'] ?? ($jsonInput['capability'] ?? ''));
    $cards = $evo->syncAndGetAgentCards($tel['nodes'] ?? ($tel['ai_nodes'] ?? []), $cap);
    echo json_encode([
        'ok' => true,
        'capability_filter' => $cap !== '' ? $cap : null,
        'count' => count($cards),
        'cards' => array_values($cards),
        'ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'novelty_check') {
    $evo = $engine->getEvolutionV4();
    $topic = trim($jsonInput['topic'] ?? ($_POST['topic'] ?? ($_GET['topic'] ?? '')));
    $rule = trim($jsonInput['rule'] ?? ($jsonInput['rule_text'] ?? ($_POST['rule'] ?? ($_GET['rule'] ?? ''))));
    $threshold = (float)($jsonInput['threshold'] ?? ($_GET['threshold'] ?? 0.85));
    if ($topic === '' && $rule === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Informe topic ou rule para avaliar novidade.']);
        exit;
    }
    $gate = $evo->evaluateNoveltyGate($topic, $rule, $threshold);
    $gate['novelty_score'] = round(1.0 - (float)($gate['similarity'] ?? 0.0), 3);
    echo json_encode(array_merge(['ok' => true], $gate), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'session_envelope') {
    $evo = $engine->getEvolutionV4();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' || !empty($jsonInput) || isset($_POST['task_id']) || isset($_POST['capability_needed'])) {
        $taskId = trim($jsonInput['task_id'] ?? ($_POST['task_id'] ?? ''));
        $currentNode = trim($jsonInput['current_node'] ?? ($_POST['current_node'] ?? ''));
        if ($taskId !== '' && $currentNode !== '') {
            $nextCap = trim($jsonInput['next_capability'] ?? ($_POST['next_capability'] ?? ''));
            $res = $evo->propagateSessionEnvelope($taskId, $currentNode, $nextCap);
            if (empty($res['ok'])) {
                http_response_code(409);
            }
            echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $capNeeded = trim($jsonInput['capability_needed'] ?? ($_POST['capability_needed'] ?? 'reasoning'));
        $seenNodes = $jsonInput['seen_nodes'] ?? [];
        if (!is_array($seenNodes)) $seenNodes = [];
        $hopTtl = (int)($jsonInput['hop_ttl'] ?? ($_POST['hop_ttl'] ?? 3));
        $ttlSeconds = (int)($jsonInput['ttl_seconds'] ?? ($_POST['ttl_seconds'] ?? 1800));
        $bootstrapUrl = trim($jsonInput['bootstrap_url'] ?? 'api.php?action=mesh_manifest');
        $env = $evo->createSessionEnvelope($capNeeded, $seenNodes, $hopTtl, $ttlSeconds, $bootstrapUrl);
        echo json_encode(['ok' => true, 'envelope' => $env], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $envelopes = $evo->listActiveEnvelopes(30);
    echo json_encode([
        'ok' => true,
        'count' => count($envelopes),
        'envelopes' => $envelopes,
        'ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'rna_memory_upsert') {
    $evo = $engine->getEvolutionV4();
    $nodeId = trim($jsonInput['node_id'] ?? ($_POST['node_id'] ?? ($_GET['node_id'] ?? '')));
    if ($nodeId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'node_id obrigatório.']);
        exit;
    }
    $caps = $jsonInput['capabilities'] ?? ['reasoning', 'code'];
    if (is_string($caps)) {
        $caps = array_values(array_filter(array_map('trim', explode(',', $caps))));
    }
    $endpoint = trim($jsonInput['discovery_endpoint'] ?? ($_POST['discovery_endpoint'] ?? 'api.php?action=mesh_manifest'));
    $autoPropagate = isset($jsonInput['auto_propagate']) ? (bool)$jsonInput['auto_propagate'] : true;
    $providedHmac = $jsonInput['hmac_signature'] ?? ($_SERVER['HTTP_X_RNA_HMAC'] ?? null);
    $res = $evo->rnaMemoryUpsert($nodeId, $caps, $endpoint, $autoPropagate, $providedHmac !== null ? (string)$providedHmac : null);
    if (empty($res['ok'])) {
        http_response_code(403);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'agent_bootstrap') {
    $evo = $engine->getEvolutionV4();
    $nodeId = trim($_GET['node_id'] ?? ($jsonInput['node_id'] ?? ($_POST['node_id'] ?? '')));
    if ($nodeId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'node_id obrigatório para re-hidratação.']);
        exit;
    }
    $boot = $evo->rehydrateAgentBoot($nodeId);
    $boot['active_axioms'] = $evo->listLedgerAxioms(15);
    $boot['active_envelopes'] = $evo->listActiveEnvelopes(10);
    echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_mutate') {
    $evo = $engine->getEvolutionV4();
    $reqPayload = !empty($jsonInput) ? $jsonInput : $_POST;
    if (empty($reqPayload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Payload JSON de mutação vazio.']);
        exit;
    }
    $res = $evo->processMutationRequest($reqPayload);
    if (empty($res['ok']) && !empty($res['blocked_by_failure_cache'])) {
        http_response_code(409);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_mutations') {
    $evo = $engine->getEvolutionV4();
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
    $mutations = $evo->listMutations($limit);
    echo json_encode([
        'ok' => true,
        'count' => count($mutations),
        'mutations' => $mutations,
        'ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'aicreate_prune_mutations') {
    $evo = $engine->getEvolutionV4();
    $maxAge = max(60, (int)($_GET['max_age'] ?? ($jsonInput['max_age'] ?? 86400)));
    $res = $evo->pruneObsoleteMutations($maxAge);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'evolution_status') {
    $evo = $engine->getEvolutionV4();
    $tel = $engine->loadTelemetry();
    $cards = $evo->syncAndGetAgentCards($tel['nodes'] ?? ($tel['ai_nodes'] ?? []));
    $axioms = $evo->listLedgerAxioms(50);
    $envelopes = $evo->listActiveEnvelopes(25);
    $memories = $evo->listPersistentMemories(30);
    $mutations = $evo->listMutations(30);
    $tokenBudget = $evo->getRealTokenBudgetStats();

    echo json_encode([
        'ok' => true,
        'protocol' => 'RNA-LoRa-v4.0-EvolutionMesh',
        'sqlite_wal_active' => ($evo->getSqlite() !== null),
        'counts' => [
            'axioms' => count($axioms),
            'agent_cards' => count($cards),
            'active_envelopes' => count($envelopes),
            'persistent_memories' => count($memories),
            'mutations' => count($mutations),
            'mutations_promoted' => count(array_filter($mutations, function($m) { return ($m['status'] ?? '') === 'promoted'; })),
            'mutations_rejected' => count(array_filter($mutations, function($m) { return ($m['status'] ?? '') === 'rejected'; }))
        ],
        'token_budget' => $tokenBudget,
        'axioms' => $axioms,
        'agent_cards' => array_values($cards),
        'envelopes' => $envelopes,
        'persistent_memories' => $memories,
        'mutations' => $mutations,
        'ts' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'sanitize_ledger') {
    $evo = $engine->getEvolutionV4();
    $res = $evo->sanitizeAndMigrateLegacyMemory();
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ação desconhecida: ' . $action]);

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'API_EXCEPTION',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
