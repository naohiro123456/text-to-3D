<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

ensure_storage_dirs();
require_post_or_options();

$data = read_json_body();
$prompt = trim((string)($data['prompt'] ?? ''));
$userId = normalize_user_id((string)($data['userId'] ?? 'guest'));

if ($prompt === '') {
    json_response([
        'success' => false,
        'message' => 'prompt is required.',
    ], 400);
}

$guidanceScale = isset($data['guidanceScale']) ? (float)$data['guidanceScale'] : 15.0;
$steps = isset($data['steps']) ? (int)$data['steps'] : 64;

// Forward to Shap-E Python server
$shapEUrl = getenv('SHAP_E_URL') ?: 'http://127.0.0.1:8100/generate';

$payload = json_encode([
    'prompt' => $prompt,
    'guidance_scale' => $guidanceScale,
    'steps' => $steps,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($shapEUrl);
if ($ch === false) {
    json_response([
        'success' => false,
        'message' => 'Failed to initialize curl.',
    ], 500);
}

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: model/gltf-binary',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 600); // Shap-E can take several minutes

$headerData = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$headerData) {
    $parts = explode(':', $header, 2);
    if (count($parts) === 2) {
        $headerData[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    return strlen($header);
});

$responseRaw = curl_exec($ch);

if ($responseRaw === false) {
    $error = curl_error($ch);
    curl_close($ch);
    json_response([
        'success' => false,
        'message' => 'Shap-E server request failed: ' . $error,
    ], 502);
}

$statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($statusCode < 200 || $statusCode >= 300) {
    // Try to decode error from Python server
    $decoded = json_decode((string)$responseRaw, true);
    $detail = is_array($decoded) ? ($decoded['detail'] ?? 'Unknown error') : (string)$responseRaw;
    json_response([
        'success' => false,
        'message' => 'Shap-E server returned HTTP ' . $statusCode,
        'detail' => $detail,
    ], 502);
}

// Save GLB to storage
$glbFilename = 'shap_e_' . time() . '_' . bin2hex(random_bytes(4)) . '.glb';
$glbPath = STORAGE_ROOT . '/output';
if (!is_dir($glbPath)) {
    mkdir($glbPath, 0775, true);
}
$fullGlbPath = $glbPath . '/' . $glbFilename;
file_put_contents($fullGlbPath, $responseRaw);

// Save job record
$title = trim((string)($data['title'] ?? ''));
if ($title === '') {
    $title = normalize_title($prompt);
}

$jobId = uniqid('job_', true);
$genTime = $headerData['x-generation-time'] ?? '?';

$job = [
    'jobId' => $jobId,
    'userId' => $userId,
    'prompt' => $prompt,
    'title' => $title,
    'status' => 'completed',
    'createdAt' => time(),
    'updatedAt' => time(),
    'provider' => 'shap-e',
    'providerTaskId' => null,
    'stage' => 'done',
    'progress' => 100,
    'error' => '',
    'result' => [
        'glbUrl' => './api/storage/output/' . $glbFilename,
        'generationTime' => $genTime,
        'title' => $title,
    ],
];

$jobPath = JOBS_DIR . '/' . $jobId . '.json';
write_json_file($jobPath, $job);

// Return GLB binary directly
header('Content-Type: model/gltf-binary');
header('Content-Disposition: attachment; filename="model.glb"');
header('Content-Length: ' . strlen((string)$responseRaw));
header('X-Job-Id: ' . $jobId);
header('X-Generation-Time: ' . $genTime);
echo $responseRaw;
exit;
