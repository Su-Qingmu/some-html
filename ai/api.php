<?php
// ========== API 配置 ==========
$api_url = 'https://api.chatanywhere.org/v1/chat/completions';  // OpenAI API
$api_key = 'sk-T0eEkyuN6b3QRdwCcMfVpjS0pQrrSF2ms3TDbMyY2q0LjZ1G';  // 填入你的 API Key
$model = 'gpt-5-mini';  // 可改为 gpt-4

header('Content-Type: application/json');

// 获取前端传来的消息
$input = json_decode(file_get_contents('php://input'), true);
$user_message = $input['message'] ?? '';

if (!$user_message) {
    echo json_encode(['reply' => '请输入消息']);
    exit;
}

// 检查 API Key 是否已配置
if ($api_key === 'YOUR_API_KEY_HERE') {
    echo json_encode(['reply' => '请先配置 api.php 中的 API Key']);
    exit;
}

// 构建请求数据
$data = [
    'model' => $model,
    'messages' => [
        ['role' => 'user', 'content' => $user_message]
    ],
    'max_tokens' => 1000,
    'temperature' => 0.7
];

// 发送请求到 API
$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['reply' => '网络错误: ' . $error]);
    exit;
}

if ($http_code !== 200) {
    // 返回原始响应用于调试
    $debug = "HTTP $http_code\n\n原始响应:\n" . substr($response, 0, 500);
    echo json_encode(['reply' => 'API 请求失败\n' . $debug]);
    exit;
}

$result = json_decode($response, true);

// 检查 JSON 解析是否成功
if (json_last_error() !== JSON_ERROR_NONE) {
    $debug = "JSON 解析失败: " . json_last_error_msg() . "\n\n原始响应:\n" . substr($response, 0, 500);
    echo json_encode(['reply' => 'JSON 错误\n' . $debug]);
    exit;
}

// 解析返回结果
$reply = '';

if (isset($result['choices'][0]['message']['content'])) {
    // OpenAI 格式
    $reply = $result['choices'][0]['message']['content'];
} elseif (isset($result['error']['message'])) {
    // API 错误信息
    $reply = 'API 错误: ' . $result['error']['message'];
} else {
    $reply = '未知响应格式';
}

echo json_encode(['reply' => $reply]);
?>
