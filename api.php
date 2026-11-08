<?php
/**
 * RNA Bio-LoRa Mesh v3.0 — Cloud & Local Native API (Hostinger + XAMPP)
 * Executa 100% nativamente em PHP + MySQL + Árvore mRNA, sem depender de processos Python externos na hospedagem web.
 */

date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

@set_time_limit(90);
date_default_timezone_set('America/Sao_Paulo');

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
    $token = trim($jsonInput['hf_token'] ?? ($_POST['hf_token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Token vazio']);
        exit;
    }
    $res = $engine->saveHfToken($token);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
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

    $emit = function (string $step, string $machine, string $msg, int $progress, $extra = null) {
        $payload = [
            'step' => $step,
            'machine' => $machine,
            'msg' => $msg,
            'progress' => $progress,
            'extra' => $extra,
            'time' => date('H:i:s')
        ];
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
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

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ação desconhecida: ' . $action]);
