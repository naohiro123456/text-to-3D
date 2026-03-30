<?php

declare(strict_types=1);

const STORAGE_ROOT = __DIR__ . '/storage';
const JOBS_DIR = STORAGE_ROOT . '/jobs';
const HISTORY_DIR = STORAGE_ROOT . '/history';

function app_config(): array
{
    $configPath = __DIR__ . '/config.php';
    $config = [];
    if (file_exists($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    $apiKey = getenv('MESHY_API_KEY') ?: ($config['meshyApiKey'] ?? '');
    $baseUrl = getenv('MESHY_BASE_URL') ?: ($config['meshyBaseUrl'] ?? 'https://api.meshy.ai');

    return [
        'meshyApiKey' => (string)$apiKey,
        'meshyBaseUrl' => rtrim((string)$baseUrl, '/'),
    ];
}

function has_meshy_api_key(): bool
{
    $config = app_config();
    return trim($config['meshyApiKey']) !== '';
}

function ensure_storage_dirs(): void
{
    if (!is_dir(STORAGE_ROOT)) {
        mkdir(STORAGE_ROOT, 0775, true);
    }
    if (!is_dir(JOBS_DIR)) {
        mkdir(JOBS_DIR, 0775, true);
    }
    if (!is_dir(HISTORY_DIR)) {
        mkdir(HISTORY_DIR, 0775, true);
    }
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput ?: '{}', true);

    if (!is_array($data)) {
        json_response([
            'success' => false,
            'message' => 'Invalid JSON body.',
        ], 400);
    }

    return $data;
}

function require_post_or_options(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Allow: POST, OPTIONS');
        http_response_code(204);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response([
            'success' => false,
            'message' => 'Method Not Allowed. Use POST.',
        ], 405);
    }
}

function require_get_or_options(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Allow: GET, OPTIONS');
        http_response_code(204);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_response([
            'success' => false,
            'message' => 'Method Not Allowed. Use GET.',
        ], 405);
    }
}

function normalize_user_id(?string $rawUserId): string
{
    $candidate = trim((string)$rawUserId);
    if ($candidate === '') {
        return 'guest';
    }

    $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $candidate);
    if (!is_string($normalized) || $normalized === '') {
        return 'guest';
    }

    return substr($normalized, 0, 80);
}

function normalize_title(string $prompt): string
{
    $title = trim($prompt);
    if ($title === '') {
        return 'Untitled';
    }

    if (mb_strlen($title) > 44) {
        return mb_substr($title, 0, 44) . '...';
    }

    return $title;
}

function parse_prompt_to_params(string $prompt): array
{
    $shape = 'sphere';
    if (preg_match('/cube|box|立方体|キューブ/ui', $prompt)) {
        $shape = 'box';
    } elseif (preg_match('/cylinder|円柱|筒/ui', $prompt)) {
        $shape = 'cylinder';
    } elseif (preg_match('/torus|donut|ドーナツ|トーラス/ui', $prompt)) {
        $shape = 'torus';
    } elseif (preg_match('/cone|円すい|コーン/ui', $prompt)) {
        $shape = 'cone';
    }

    $color = 0x278aff;
    $colorName = 'blue';
    if (preg_match('/red|赤/ui', $prompt)) {
        $color = 0xff4d5e;
        $colorName = 'red';
    } elseif (preg_match('/green|緑/ui', $prompt)) {
        $color = 0x39b86f;
        $colorName = 'green';
    } elseif (preg_match('/yellow|黄/ui', $prompt)) {
        $color = 0xffcb47;
        $colorName = 'yellow';
    } elseif (preg_match('/white|白/ui', $prompt)) {
        $color = 0xf5f7fb;
        $colorName = 'white';
    } elseif (preg_match('/black|黒/ui', $prompt)) {
        $color = 0x2d3540;
        $colorName = 'black';
    }

    $scale = 1.0;
    $scaleName = 'medium';
    if (preg_match('/small|小さい|ミニ/ui', $prompt)) {
        $scale = 0.75;
        $scaleName = 'small';
    } elseif (preg_match('/large|big|大きい|巨大/ui', $prompt)) {
        $scale = 1.35;
        $scaleName = 'large';
    }

    $glbByShape = [
        'sphere' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Sphere/glTF-Binary/Sphere.glb',
        'box' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Cube/glTF-Binary/Cube.glb',
        'cylinder' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Cylinder/glTF-Binary/Cylinder.glb',
        'torus' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Torus/glTF-Binary/Torus.glb',
        'cone' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Cone/glTF-Binary/Cone.glb',
    ];

    return [
        'shape' => $shape,
        'shapeName' => $shape,
        'color' => $color,
        'colorName' => $colorName,
        'scale' => $scale,
        'scaleName' => $scaleName,
        'roughness' => 0.35,
        'metalness' => 0.35,
        'glbUrl' => $glbByShape[$shape] ?? null,
    ];
}

function meshy_request(string $method, string $path, ?array $payload = null): array
{
    $config = app_config();
    $apiKey = trim($config['meshyApiKey']);
    if ($apiKey === '') {
        throw new RuntimeException('MESHY_API_KEY is not configured. Set env var or api/config.php.');
    }

    $url = $config['meshyBaseUrl'] . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize curl.');
    }

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ];

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $responseRaw = curl_exec($ch);
    if ($responseRaw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Meshy API request failed: ' . $error);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$responseRaw, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $responseRaw];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : (string)$responseRaw;
        throw new RuntimeException('Meshy API HTTP ' . $statusCode . ': ' . $message);
    }

    return $decoded;
}

function meshy_create_preview_task(string $prompt): string
{
    $payload = [
        'mode' => 'preview',
        'prompt' => $prompt,
        'ai_model' => 'latest',
        'target_formats' => ['glb'],
        'should_remesh' => false,
    ];

    $result = meshy_request('POST', '/openapi/v2/text-to-3d', $payload);
    $taskId = (string)($result['result'] ?? '');
    if ($taskId === '') {
        throw new RuntimeException('Meshy preview task id missing in response.');
    }
    return $taskId;
}

function meshy_create_refine_task(string $previewTaskId, string $texturePrompt): string
{
    $payload = [
        'mode' => 'refine',
        'preview_task_id' => $previewTaskId,
        'texture_prompt' => $texturePrompt,
        'target_formats' => ['glb'],
    ];

    $result = meshy_request('POST', '/openapi/v2/text-to-3d', $payload);
    $taskId = (string)($result['result'] ?? '');
    if ($taskId === '') {
        throw new RuntimeException('Meshy refine task id missing in response.');
    }
    return $taskId;
}

function meshy_get_task(string $taskId): array
{
    return meshy_request('GET', '/openapi/v2/text-to-3d/' . rawurlencode($taskId));
}

function meshy_map_status(string $status): string
{
    $normalized = strtoupper(trim($status));
    if ($normalized === 'SUCCEEDED') {
        return 'completed';
    }
    if ($normalized === 'FAILED' || $normalized === 'CANCELED') {
        return 'failed';
    }
    if ($normalized === 'IN_PROGRESS') {
        return 'processing';
    }
    return 'queued';
}

function read_json_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $parsed = json_decode((string)$raw, true);
    return is_array($parsed) ? $parsed : [];
}

function list_user_jobs(string $userId): array
{
    $jobs = [];
    $files = glob(JOBS_DIR . '/*.json') ?: [];

    foreach ($files as $path) {
        $job = read_json_file($path);
        if (($job['userId'] ?? '') !== $userId) {
            continue;
        }
        $jobs[] = $job;
    }

    usort($jobs, static function ($a, $b) {
        return (int)($b['createdAt'] ?? 0) <=> (int)($a['createdAt'] ?? 0);
    });

    return $jobs;
}

function write_json_file(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
