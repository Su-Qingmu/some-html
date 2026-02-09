<?php
// ========== Tavily 搜索 API ==========
$tavily_api_key = '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';
$tavily_url = 'https://api.tavily.com/search';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$query = $input['query'] ?? '';

if (!$query) {
    echo json_encode(['results' => [], 'error' => '请输入搜索词']);
    exit;
}

$data = [
    'api_key' => $tavily_api_key,
    'query' => $query,
    'search_depth' => 'basic',
    'max_results' => 5
];

$ch = curl_init($tavily_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['results' => [], 'error' => '网络错误: ' . $error]);
    exit;
}

$result = json_decode($response, true);

if ($http_code !== 200) {
    echo json_encode(['results' => [], 'error' => 'API 错误 (HTTP ' . $http_code . ')']);
    exit;
}

echo json_encode([
    'results' => $result['results'] ?? [],
    'answer' => $result['answer'] ?? ''
]);
?>
