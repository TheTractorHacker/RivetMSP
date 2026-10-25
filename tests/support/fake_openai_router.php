<?php
/*
 * Minimal OpenAI-compatible chat/completions loopback, for `php -S` under tests/ai_provider_verify.php.
 * Started via: php -S 127.0.0.1:<port> tests/support/fake_openai_router.php
 *
 * Echoes back everything it received (path, model, Authorization header, message content) as the
 * assistant message content, JSON-encoded, so the calling test can verify aiChat() sent EXACTLY what
 * the configured provider/model row says - the whole point of proving the interface is genuinely
 * provider-agnostic (driven by config, not hardcoded to any one vendor's URL/key/model).
 */
header('Content-Type: application/json');

$path = $_SERVER['REQUEST_URI'] ?? '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

$echo = [
    'path'    => $path,
    'auth'    => $auth,
    'model'   => $body['model'] ?? null,
    'user_msg' => $body['messages'][array_key_last($body['messages'] ?? [0])]['content'] ?? null,
];

echo json_encode([
    'choices' => [
        ['message' => ['content' => json_encode($echo)]],
    ],
    'usage' => ['total_tokens' => 42],
]);
