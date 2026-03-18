# 终极井字棋 - 完整规则与算法说明

## 1. 游戏结构

### 1.1 棋盘表示
- **大棋盘**：3x3 = 9个大格子，编号 0-8
- **小棋盘**：每个大格子内 3x3 = 9个小格子，编号 0-8
- **总格子数**：81个（9x9）

```
大棋盘索引：
0 | 1 | 2
---------
3 | 4 | 5
---------
6 | 7 | 8

每个大格子内的索引：
0 | 1 | 2
---------
3 | 4 | 5
---------
6 | 7 | 8
```

### 1.2 状态表示
```javascript
board[9][9]    // 棋盘：board[大索引][小索引]，值为 'X', 'O', ''
bigWinners[9]  // 大棋盘获胜状态：'X', 'O', 'draw', null
currentPlayer  // 当前玩家：'X' 或 'O'
allowedBigIdx  // 允许落子的大棋盘索引：0-8 或 -1（任意位置）
totalMoves     // 总步数
gameOver       // 游戏是否结束
```

## 2. 落子规则

### 2.1 第一步（totalMoves = 0 或 1）
- **先手（X）**第一步可以在**任意位置**落子
- allowedBigIdx = -1（无限制）

### 2.2 后续步骤（totalMoves >= 2）
落子位置受以下规则限制：

**规则A**：必须在指定的大棋盘内落子
- 玩家只能在**上一手落子的小棋盘索引对应的大棋盘**落子
- 例如：X在大棋盘索引 2，小棋盘索引 5 落子 → O只能在**大棋盘索引 5**落子

**规则B**：自由落子条件
如果目标大棋盘满足以下任一条件，玩家可以在**任意大棋盘**落子：
1. 该大棋盘已有获胜者（bigWinners[idx] !== null）
2. 该大棋盘已满（9个格子都有值）

### 2.3 限制更新时机
- 落子后**立即更新**限制，不是等对手落子后
- 限制基于**当前这一步**的落子位置

## 3. 获胜条件

### 3.1 小棋盘获胜
在小棋盘内形成三连珠（横、竖、对角线）：
```
行： [0,1,2] [3,4,5] [6,7,8]
列： [0,3,6] [1,4,7] [2,5,8]
对角：[0,4,8] [2,4,6]
```

### 3.2 小棋盘平局
小棋盘9个格子都有值但无人三连，则为平局（'draw'）

### 3.3 游戏最终获胜
- 统计9个大棋盘中，X获胜的小棋盘数量 vs O获胜的小棋盘数量
- X获胜数 > O获胜数 → X获胜
- O获胜数 > X获胜数 → O获胜
- 相同 → 平局

## 4. 算法优化说明

### 4.1 局面评估函数

评估当前局面对AI的优劣：

```python
def evaluate(board, bigWinners, aiPlayer):
    score = 0

    # 1. 已获胜的大棋盘（最重要）
    aiBigWins = count(bigWinners == aiPlayer)
    humanBigWins = count(bigWinners == humanPlayer)
    score += aiBigWins * 1000
    score -= humanBigWins * 1000

    # 2. 每个小棋盘的潜力评估
    for each small board:
        if not finished:
            # 威胁评估：几连珠？
            score += evaluate_small_board(smallBoard, aiPlayer) * 10
            score -= evaluate_small_board(smallBoard, humanPlayer) * 10

    # 3. 位置价值（大棋盘中心更重要）
    center_map = {4: 5, 0,2,6,8: 3, 1,3,5,7: 1}
    score += center_value[bigIdx]

    return score
```

### 4.2 小棋盘评估

```python
def evaluate_small_board(cells, player):
    score = 0
    lines = [[0,1,2], [3,4,5], [6,7,8], [0,3,6], [1,4,7], [2,5,8], [0,4,8], [2,4,6]]

    for line in lines:
        player_count = count(cells[i] == player for i in line)
        empty_count = count(cells[i] == '' for i in line)

        if player_count == 3: score += 100      # 已获胜
        elif player_count == 2 and empty_count == 1: score += 10  # 两连
        elif player_count == 1 and empty_count == 2: score += 1   # 一子

    # 中心位置
    if cells[4] == player: score += 3

    return score
```

### 4.3 Minimax + Alpha-Beta 剪枝

由于搜索空间较大，建议：
- **搜索深度**：4-6层（根据性能调整）
- **剪枝优化**：Alpha-Beta可大幅减少搜索量
- **启发式排序**：优先搜索高价值位置（如能赢的位置、中心位置）

### 4.4 关键策略优先级

1. **最高优先级**：己方能直接获胜 → 立即落子获胜
2. **高优先级**：对方即将获胜 → 堵住
3. **中优先级**：在有小棋盘形成两连的位置落子
4. **低优先级**：占据中心位置（大棋盘4和小棋盘4）
5. **基础价值**：角落 > 边 > 中心（针对小棋盘）

### 4.5 特殊局面处理

1. **被锁定但目标已满**：可以在任意位置落子
2. **多个大棋盘同时有威胁**：评估所有可能的落子，选择全局最优
3. **大棋盘平局**：不算任何一方获胜，需要避免在这种格子浪费步数

## 5. 性能优化建议

1. **缓存评估结果**：避免重复计算相同局面
2. **迭代加深**：时间限制内尽可能搜索更深
3. **置换表**：记录已评估的局面及结果
4. **启发式剪枝**：明显劣势的分支直接剪掉

## 6. 复杂度分析

- 大棋盘状态数：3^9 ≈ 19683（每个格子X/O/空）
- 考虑大棋盘获胜状态：约 10^5 量级
- 完整Minimax不可行，需要深度限制 + 剪枝
