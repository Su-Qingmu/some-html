<?php
/**
 * 井字棋联机后端
 * 使用文件存储游戏状态
 */

// 配置
define('DATA_DIR', __DIR__ . '/game_data');
define('ROOM_EXPIRE', 3600); // 房间过期时间（秒）

// 创建数据目录
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

// API 路由
$action = $_GET['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

switch ($action) {
    case 'create_room':
        createRoom();
        break;
    case 'join_room':
        joinRoom();
        break;
    case 'get_status':
        getStatus();
        break;
    case 'make_move':
        makeMove();
        break;
    case 'ai_move':
        aiMove();
        break;
    default:
        echo json_encode(['success' => false, 'error' => '未知操作']);
}

/**
 * 创建房间
 */
function createRoom() {
    $roomCode = generateRoomCode();
    $playerId = 'player_' . time();
    $gameState = [
        'room_code' => $roomCode,
        'players' => ['X' => $playerId, 'O' => null],
        'current_player' => 'X',
        'big_winners' => array_fill(0, 9, null),
        'board' => array_fill(0, 9, array_fill(0, 9, '')),
        'allowed_big_idx' => -1,
        'total_moves' => 0,
        'game_over' => false,
        'winner' => null,
        'created_at' => time()
    ];

    saveGame($roomCode, $gameState);

    echo json_encode([
        'success' => true,
        'room_code' => $roomCode,
        'player' => 'X'
    ]);
}

/**
 * 加入房间
 */
function joinRoom() {
    $roomCode = $_POST['room_code'] ?? '';

    if (strlen($roomCode) !== 4 || !is_numeric($roomCode)) {
        echo json_encode(['success' => false, 'error' => '房间号格式错误']);
        return;
    }

    $gameState = loadGame($roomCode);

    if (!$gameState) {
        echo json_encode(['success' => false, 'error' => '房间不存在']);
        return;
    }

    if ($gameState['players']['O'] !== null) {
        echo json_encode(['success' => false, 'error' => '房间已满']);
        return;
    }

    $gameState['players']['O'] = 'player_' . time();
    saveGame($roomCode, $gameState);

    echo json_encode([
        'success' => true,
        'room_code' => $roomCode,
        'player' => 'O'
    ]);
}

/**
 * 获取房间状态
 */
function getStatus() {
    $roomCode = $_GET['room_code'] ?? '';
    $player = $_GET['player'] ?? '';

    $gameState = loadGame($roomCode);

    if (!$gameState) {
        echo json_encode(['success' => false, 'error' => '房间不存在']);
        return;
    }

    // 检查游戏是否结束
    checkGameOver($gameState);
    saveGame($roomCode, $gameState);

    // 返回非敏感信息
    $xJoined = !empty($gameState['players']['X']);
    $oJoined = !empty($gameState['players']['O']);
    $roomFull = $xJoined && $oJoined;

    echo json_encode([
        'success' => true,
        'room_code' => $gameState['room_code'],
        'current_player' => $gameState['current_player'],
        'big_winners' => $gameState['big_winners'],
        'board' => $gameState['board'],
        'allowed_big_idx' => $gameState['allowed_big_idx'],
        'total_moves' => $gameState['total_moves'],
        'game_over' => $gameState['game_over'],
        'winner' => $gameState['winner'],
        'is_my_turn' => $gameState['current_player'] === $player,
        'player_assigned' => $player,
        'room_full' => $roomFull,
        'players' => ['X' => $xJoined, 'O' => $oJoined]
    ]);
}

/**
 * 执行落子
 */
function makeMove() {
    $roomCode = $_POST['room_code'] ?? '';
    $player = $_POST['player'] ?? '';
    $bigIdx = isset($_POST['big_idx']) ? intval($_POST['big_idx']) : -1;
    $smallIdx = isset($_POST['small_idx']) ? intval($_POST['small_idx']) : -1;

    $gameState = loadGame($roomCode);

    if (!$gameState) {
        echo json_encode(['success' => false, 'error' => '房间不存在']);
        return;
    }

    // 验证是否是玩家回合
    $playerSymbol = $gameState['players']['X'] === $player ? 'X' : ($gameState['players']['O'] === $player ? 'O' : null);
    if (!$playerSymbol || $gameState['current_player'] !== $playerSymbol) {
        echo json_encode(['success' => false, 'error' => '不是你的回合']);
        return;
    }

    // 验证落子是否合法
    if (!isValidMove($gameState, $bigIdx, $smallIdx)) {
        echo json_encode(['success' => false, 'error' => '无效落子']);
        return;
    }

    // 执行落子
    $gameState['board'][$bigIdx][$smallIdx] = $playerSymbol;
    $gameState['total_moves']++;

    // 检查小棋盘是否获胜
    $smallWinner = checkSmallBoardWinner($gameState['board'], $bigIdx);
    if ($smallWinner) {
        $gameState['big_winners'][$bigIdx] = $smallWinner;
    }

    // 检查游戏是否结束
    checkGameOver($gameState);

    if (!$gameState['game_over']) {
        // 更新限制
        updateRestriction($gameState, $smallIdx);

        // 切换玩家
        $gameState['current_player'] = $gameState['current_player'] === 'X' ? 'O' : 'X';
    }

    saveGame($roomCode, $gameState);

    echo json_encode([
        'success' => true,
        'big_idx' => $bigIdx,
        'small_idx' => $smallIdx,
        'current_player' => $gameState['current_player'],
        'big_winners' => $gameState['big_winners'],
        'game_over' => $gameState['game_over'],
        'winner' => $gameState['winner']
    ]);
}

/**
 * AI落子（根据难度选择算法）
 */
function aiMove() {
    $board = json_decode($_POST['board'] ?? '[]', true);
    $bigWinners = json_decode($_POST['big_winners'] ?? '[]', true);
    $allowedBigIdx = isset($_POST['allowed_big_idx']) ? intval($_POST['allowed_big_idx']) : -1;
    $difficulty = $_POST['difficulty'] ?? 'normal';
    $aiPlayer = 'O';
    $humanPlayer = 'X';

    if (empty($board)) {
        echo json_encode(['success' => false, 'error' => '无效棋盘']);
        return;
    }

    $move = null;

    switch ($difficulty) {
        case 'easy':
            // 简单：50%概率最优，50%随机
            if (rand(1, 100) <= 50) {
                $move = findBestMove($board, $bigWinners, $allowedBigIdx, $aiPlayer, $humanPlayer);
            }
            if (!$move) {
                $validMoves = getValidMoves($board, $bigWinners, $allowedBigIdx);
                $move = $validMoves[array_rand($validMoves)] ?? null;
            }
            break;

        case 'hard':
            // 高级：使用MCTS算法
            $move = mctsMove($board, $bigWinners, $allowedBigIdx, $aiPlayer, $humanPlayer);
            break;

        case 'normal':
        default:
            // 普通：使用Minimax算法
            $move = findBestMove($board, $bigWinners, $allowedBigIdx, $aiPlayer, $humanPlayer);
            break;
    }

    if ($move) {
        $board[$move['big_idx']][$move['small_idx']] = $aiPlayer;
        $bigWinners[$move['big_idx']] = checkSmallBoardWinner($board, $move['big_idx']);

        echo json_encode([
            'success' => true,
            'big_idx' => $move['big_idx'],
            'small_idx' => $move['small_idx'],
            'big_winners' => $bigWinners
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => '无有效落子']);
    }
}

// ============ 辅助函数 ============

function generateRoomCode() {
    do {
        $code = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    } while (file_exists(DATA_DIR . "/room_{$code}.json"));
    return $code;
}

function saveGame($roomCode, $gameState) {
    $gameState['updated_at'] = time();
    file_put_contents(DATA_DIR . "/room_{$roomCode}.json", json_encode($gameState));
}

function loadGame($roomCode) {
    $file = DATA_DIR . "/room_{$roomCode}.json";
    if (!file_exists($file)) {
        return null;
    }

    $data = json_decode(file_get_contents($file), true);

    // 检查是否过期
    if (time() - $data['updated_at'] > ROOM_EXPIRE) {
        unlink($file);
        return null;
    }

    return $data;
}

function isValidMove($gameState, $bigIdx, $smallIdx) {
    // 大棋盘索引无效
    if ($bigIdx < 0 || $bigIdx > 8) return false;
    // 小棋盘索引无效
    if ($smallIdx < 0 || $smallIdx > 8) return false;
    // 大棋盘已分出胜负
    if ($gameState['big_winners'][$bigIdx] !== null) return false;
    // 位置已被占用
    if ($gameState['board'][$bigIdx][$smallIdx] !== '') return false;
    // 不在允许的位置
    if ($gameState['allowed_big_idx'] !== -1 && $gameState['allowed_big_idx'] !== $bigIdx) return false;

    return true;
}

function updateRestriction(&$gameState, $smallIdx) {
    if ($gameState['total_moves'] >= 1) {
        $nextBigIdx = $smallIdx;
        $isBigFinished = $gameState['big_winners'][$nextBigIdx] !== null;
        $isBigFull = !in_array('', $gameState['board'][$nextBigIdx]);

        if (!$isBigFinished && !$isBigFull) {
            $gameState['allowed_big_idx'] = $nextBigIdx;
        } else {
            $gameState['allowed_big_idx'] = -1;
        }
    }
}

function checkSmallBoardWinner($board, $bigIdx) {
    $cells = $board[$bigIdx];
    $winPatterns = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6]
    ];

    foreach ($winPatterns as $pattern) {
        [$a, $b, $c] = $pattern;
        if ($cells[$a] && $cells[$a] === $cells[$b] && $cells[$a] === $cells[$c]) {
            return $cells[$a];
        }
    }

    if (!in_array('', $cells)) {
        return 'draw';
    }

    return null;
}

function checkGameOver(&$gameState) {
    // 检查所有大棋盘是否结束
    $allFinished = !in_array(null, $gameState['big_winners'], true);
    $bigFull = true;

    for ($i = 0; $i < 9; $i++) {
        if (in_array('', $gameState['board'][$i])) {
            $bigFull = false;
            break;
        }
    }

    if ($allFinished || $bigFull) {
        $gameState['game_over'] = true;

        $xWins = count(array_filter($gameState['big_winners'], function($w) { return $w === 'X'; }));
        $oWins = count(array_filter($gameState['big_winners'], function($w) { return $w === 'O'; }));

        if ($xWins > $oWins) {
            $gameState['winner'] = 'X';
        } elseif ($oWins > $xWins) {
            $gameState['winner'] = 'O';
        } else {
            $gameState['winner'] = 'draw';
        }
    }
}

// ============ 高级AI算法 ============

function findBestMove($board, $bigWinners, $allowedBigIdx, $aiPlayer, $humanPlayer) {
    $validMoves = getValidMoves($board, $bigWinners, $allowedBigIdx);

    if (empty($validMoves)) {
        return null;
    }

    $bestScore = -PHP_INT_MAX;
    $bestMove = null;

    foreach ($validMoves as $move) {
        // 模拟落子
        $newBoard = array_map(function($row) { return $row; }, $board);
        $newBoard[$move['big_idx']][$move['small_idx']] = $aiPlayer;

        // 检查是否获胜
        $newBigWinners = $bigWinners;
        $winner = checkSmallBoardWinner($newBoard, $move['big_idx']);
        if ($winner) {
            $newBigWinners[$move['big_idx']] = $winner;
        }

        // 检查游戏是否结束
        if (checkUltimateWin($newBigWinners, $aiPlayer)) {
            return $move;
        }

        // 评估分数
        $score = minimax($newBoard, $newBigWinners, $allowedBigIdx, 0, false, $aiPlayer, $humanPlayer, -PHP_INT_MAX, PHP_INT_MAX);

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMove = $move;
        }
    }

    return $bestMove ?: $validMoves[array_rand($validMoves)];
}

function getValidMoves($board, $bigWinners, $allowedBigIdx) {
    $moves = [];

    for ($bigIdx = 0; $bigIdx < 9; $bigIdx++) {
        if ($bigWinners[$bigIdx] !== null) continue;
        if ($allowedBigIdx !== -1 && $allowedBigIdx !== $bigIdx) continue;

        $isFull = !in_array('', $board[$bigIdx]);
        if ($isFull) continue;

        for ($smallIdx = 0; $smallIdx < 9; $smallIdx++) {
            if ($board[$bigIdx][$smallIdx] === '') {
                $moves[] = ['big_idx' => $bigIdx, 'small_idx' => $smallIdx];
            }
        }
    }

    return $moves;
}

function minimax($board, $bigWinners, $allowedBigIdx, $depth, $isMaximizing, $aiPlayer, $humanPlayer, $alpha, $beta) {
    // 检查游戏结束
    $aiWins = count(array_filter($bigWinners, function($w) use ($aiPlayer) { return $w === $aiPlayer; }));
    $humanWins = count(array_filter($bigWinners, function($w) use ($humanPlayer) { return $w === $humanPlayer; }));

    if (checkUltimateWin($bigWinners, $aiPlayer)) {
        return 1000 - $depth;
    }
    if (checkUltimateWin($bigWinners, $humanPlayer)) {
        return -1000 + $depth;
    }

    // 检查平局
    $allFinished = !in_array(null, $bigWinners, true);
    if ($allFinished) {
        return 0;
    }

    // 限制搜索深度
    if ($depth >= 4) {
        return evaluateBoard($board, $bigWinners, $aiPlayer, $humanPlayer);
    }

    $validMoves = getValidMoves($board, $bigWinners, $allowedBigIdx);

    if (empty($validMoves)) {
        return 0;
    }

    if ($isMaximizing) {
        $maxScore = -PHP_INT_MAX;
        foreach ($validMoves as $move) {
            $newBoard = array_map(function($row) { return $row; }, $board);
            $newBoard[$move['big_idx']][$move['small_idx']] = $aiPlayer;

            $newBigWinners = $bigWinners;
            $winner = checkSmallBoardWinner($newBoard, $move['big_idx']);
            if ($winner) {
                $newBigWinners[$move['big_idx']] = $winner;
            }

            // 更新下一个限制
            $nextBigIdx = $move['small_idx'];
            $nextAllowed = $nextBigIdx;
            if ($newBigWinners[$nextBigIdx] !== null || !in_array('', $newBoard[$nextBigIdx])) {
                $nextAllowed = -1;
            }

            $score = minimax($newBoard, $newBigWinners, $nextAllowed, $depth + 1, false, $aiPlayer, $humanPlayer, $alpha, $beta);
            $maxScore = max($maxScore, $score);
            $alpha = max($alpha, $score);
            if ($beta <= $alpha) break;
        }
        return $maxScore;
    } else {
        $minScore = PHP_INT_MAX;
        foreach ($validMoves as $move) {
            $newBoard = array_map(function($row) { return $row; }, $board);
            $newBoard[$move['big_idx']][$move['small_idx']] = $humanPlayer;

            $newBigWinners = $bigWinners;
            $winner = checkSmallBoardWinner($newBoard, $move['big_idx']);
            if ($winner) {
                $newBigWinners[$move['big_idx']] = $winner;
            }

            $nextBigIdx = $move['small_idx'];
            $nextAllowed = $nextBigIdx;
            if ($newBigWinners[$nextBigIdx] !== null || !in_array('', $newBoard[$nextBigIdx])) {
                $nextAllowed = -1;
            }

            $score = minimax($newBoard, $newBigWinners, $nextAllowed, $depth + 1, true, $aiPlayer, $humanPlayer, $alpha, $beta);
            $minScore = min($minScore, $score);
            $beta = min($beta, $score);
            if ($beta <= $alpha) break;
        }
        return $minScore;
    }
}

function checkUltimateWin($bigWinners, $player) {
    $winPatterns = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6]
    ];

    foreach ($winPatterns as $pattern) {
        if ($bigWinners[$pattern[0]] === $player &&
            $bigWinners[$pattern[1]] === $player &&
            $bigWinners[$pattern[2]] === $player) {
            return true;
        }
    }
    return false;
}

function evaluateBoard($board, $bigWinners, $aiPlayer, $humanPlayer) {
    $score = 0;

    // 已获胜的大棋盘
    $aiWins = count(array_filter($bigWinners, function($w) use ($aiPlayer) { return $w === $aiPlayer; }));
    $humanWins = count(array_filter($bigWinners, function($w) use ($humanPlayer) { return $w === $humanPlayer; }));
    $score += $aiWins * 100;
    $score -= $humanWins * 100;

    // 评估每个小棋盘的潜力
    for ($bigIdx = 0; $bigIdx < 9; $bigIdx++) {
        if ($bigWinners[$bigIdx] !== null) continue;

        $cells = $board[$bigIdx];
        $aiCount = count(array_filter($cells, function($c) use ($aiPlayer) { return $c === $aiPlayer; }));
        $humanCount = count(array_filter($cells, function($c) use ($humanPlayer) { return $c === $humanPlayer; }));

        // 小棋盘威胁评估
        $score += evaluateSmallBoard($cells, $aiPlayer) * 10;
        $score -= evaluateSmallBoard($cells, $humanPlayer) * 10;
    }

    // 中心位置优势
    $centerBonus = [4 => 3, 0, 2, 6, 8 => 2, 1, 3, 5, 7 => 1];
    for ($bigIdx = 0; $bigIdx < 9; $bigIdx++) {
        if ($bigWinners[$bigIdx] !== null) continue;
        $score += ($centerBonus[$bigIdx] ?? 0);
    }

    return $score;
}

function evaluateSmallBoard($cells, $player) {
    $score = 0;
    $winPatterns = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6]
    ];

    foreach ($winPatterns as $pattern) {
        $playerCount = 0;
        $emptyCount = 0;

        foreach ($pattern as $idx) {
            if ($cells[$idx] === $player) $playerCount++;
            if ($cells[$idx] === '') $emptyCount++;
        }

        if ($playerCount === 3) {
            $score += 100; // 已获胜
        } elseif ($playerCount === 2 && $emptyCount === 1) {
            $score += 10; // 两连
        } elseif ($playerCount === 1 && $emptyCount === 2) {
            $score += 1; // 一子
        }
    }

    // 中心位置
    if ($cells[4] === $player) $score += 3;

    return $score;
}

// ============ MCTS算法（高级难度）============

class MCTSNode {
    public $state;
    public $parent;
    public $action;
    public $children = [];
    public $visits = 0;
    public $wins = 0;
    public $untriedActions = [];

    public function __construct($state, $parent = null, $action = null) {
        $this->state = $state;
        $this->parent = $parent;
        $this->action = $action;
        if ($parent === null) {
            $this->untriedActions = getValidMovesMCTS($state['board'], $state['bigWinners'], $state['allowedBigIdx']);
        }
    }
}

function mctsMove($board, $bigWinners, $allowedBigIdx, $aiPlayer, $humanPlayer) {
    $state = [
        'board' => $board,
        'bigWinners' => $bigWinners,
        'allowedBigIdx' => $allowedBigIdx,
        'currentPlayer' => $aiPlayer,
        'totalMoves' => countBoardMoves($board)
    ];

    $root = new MCTSNode($state);
    $timeLimit = 800000; // 800ms
    $startTime = microtime(true);

    while (microtime(true) - $startTime < $timeLimit / 1000000) {
        // 选择
        $node = $root;
        $simState = cloneState($state);

        while (empty($node->untriedActions) && !empty($node->children)) {
            $node = ucbSelect($node);
            applyAction($simState, $node->action);
        }

        // 扩展
        if (!empty($node->untriedActions)) {
            $action = array_pop($node->untriedActions);
            applyAction($simState, $action);
            $childNode = new MCTSNode(cloneState($simState), $node, $action);
            $node->children[] = $childNode;
            $node = $childNode;
        }

        // 模拟
        $result = simulate($simState, $aiPlayer, $humanPlayer);

        // 回溯
        while ($node !== null) {
            $node->visits++;
            $node->wins += $result;
            $node = $node->parent;
            $result = 1 - $result;
        }
    }

    // 返回访问次数最多的子节点
    if (empty($root->children)) {
        $validMoves = getValidMoves($board, $bigWinners, $allowedBigIdx);
        return $validMoves[array_rand($validMoves)] ?? null;
    }

    $bestChild = null;
    $bestVisits = -1;
    foreach ($root->children as $child) {
        if ($child->visits > $bestVisits) {
            $bestVisits = $child->visits;
            $bestChild = $child;
        }
    }

    return $bestChild ? $bestChild->action : null;
}

function ucbSelect($node) {
    $c = 1.4;
    $bestChild = null;
    $bestValue = -PHP_INT_MAX;

    foreach ($node->children as $child) {
        $ucb = $child->wins / $child->visits + $c * sqrt(2 * log($node->visits) / $child->visits);
        if ($ucb > $bestValue) {
            $bestValue = $ucb;
            $bestChild = $child;
        }
    }

    return $bestChild;
}

function simulate($state, $aiPlayer, $humanPlayer) {
    $simState = cloneState($state);

    while (!isGameOverMCTS($simState)) {
        $actions = getValidMovesMCTS($simState['board'], $simState['bigWinners'], $simState['allowedBigIdx']);

        if (empty($actions)) break;

        // 启发式：优先获胜或堵截
        $winActions = [];
        $blockActions = [];

        foreach ($actions as $action) {
            $testState = cloneState($simState);
            applyAction($testState, $action);

            $bigWinners = $testState['bigWinners'];
            $current = $testState['currentPlayer'];

            if ($current === $aiPlayer && checkSmallWin($testState['board'], $action['big_idx'], $action['small_idx'], $current)) {
                $winActions[] = $action;
            } elseif ($current === $humanPlayer && checkSmallWin($testState['board'], $action['big_idx'], $action['small_idx'], $humanPlayer)) {
                $blockActions[] = $action;
            }
        }

        if (!empty($winActions)) {
            $action = $winActions[array_rand($winActions)];
        } elseif (!empty($blockActions)) {
            $action = $blockActions[array_rand($blockActions)];
        } else {
            // 按权重选择：中心>角落>边
            $weighted = [];
            foreach ($actions as $a) {
                $weight = 1;
                if ($a['small_idx'] == 4) $weight = 10;
                elseif (in_array($a['small_idx'], [0, 2, 6, 8])) $weight = 5;
                elseif (in_array($a['small_idx'], [1, 3, 5, 7])) $weight = 3;
                for ($i = 0; $i < $weight; $i++) $weighted[] = $a;
            }
            $action = $weighted[array_rand($weighted)];
        }

        applyAction($simState, $action);
    }

    $winner = getGameWinner($simState);
    if ($winner === $aiPlayer) return 1;
    if ($winner === $humanPlayer) return 0;
    return 0.5;
}

function cloneState($state) {
    return [
        'board' => array_map(function($row) { return $row; }, $state['board']),
        'bigWinners' => $state['bigWinners'],
        'allowedBigIdx' => $state['allowedBigIdx'],
        'currentPlayer' => $state['currentPlayer'],
        'totalMoves' => $state['totalMoves']
    ];
}

function applyAction(&$state, $action) {
    $bigIdx = $action['big_idx'];
    $smallIdx = $action['small_idx'];
    $player = $state['currentPlayer'];

    $state['board'][$bigIdx][$smallIdx] = $player;
    $state['totalMoves']++;

    // 检查小棋盘获胜
    $winner = checkSmallBoardWinner($state['board'], $bigIdx);
    if ($winner) {
        $state['bigWinners'][$bigIdx] = $winner;
    }

    // 更新限制
    $state['allowedBigIdx'] = $smallIdx;
    if ($state['bigWinners'][$smallIdx] !== null || !in_array('', $state['board'][$smallIdx])) {
        $state['allowedBigIdx'] = -1;
    }

    // 切换玩家
    $state['currentPlayer'] = $state['currentPlayer'] === 'X' ? 'O' : 'X';
}

function getValidMovesMCTS($board, $bigWinners, $allowedBigIdx) {
    return getValidMoves($board, $bigWinners, $allowedBigIdx);
}

function isGameOverMCTS($state) {
    $xWins = count(array_filter($state['bigWinners'], function($w) { return $w === 'X'; }));
    $oWins = count(array_filter($state['bigWinners'], function($w) { return $w === 'O'; }));

    if ($xWins > 4 || $oWins > 4) return true;

    $allFinished = !in_array(null, $state['bigWinners'], true);
    return $allFinished;
}

function getGameWinner($state) {
    $xWins = count(array_filter($state['bigWinners'], function($w) { return $w === 'X'; }));
    $oWins = count(array_filter($state['bigWinners'], function($w) { return $w === 'O'; }));

    if ($xWins > $oWins) return 'X';
    if ($oWins > $xWins) return 'O';
    if (!in_array(null, $state['bigWinners'], true)) return 'draw';
    return null;
}

function checkSmallWin($board, $bigIdx, $smallIdx, $player) {
    $cells = $board[$bigIdx];
    $winPatterns = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6]
    ];

    foreach ($winPatterns as $pattern) {
        if (in_array($smallIdx, $pattern)) {
            $win = true;
            foreach ($pattern as $idx) {
                if ($cells[$idx] !== $player) {
                    $win = false;
                    break;
                }
            }
            if ($win) return true;
        }
    }
    return false;
}

function countBoardMoves($board) {
    $count = 0;
    foreach ($board as $small) {
        foreach ($small as $cell) {
            if ($cell !== '') $count++;
        }
    }
    return $count;
}
