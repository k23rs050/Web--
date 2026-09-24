<?php
/**
 * 階層カテゴリ（最大3階層）の定義とヘルパー
 */

function getCategoryTree(): array
{
    return [
        '料理' => [
            '和食' => ['寿司', 'ラーメン', '丼もの'],
            '洋食' => ['パスタ', 'ステーキ', 'ハンバーガー'],
            '中華' => ['炒め物', '点心'],
        ],
        'スポーツ' => [
            '球技' => ['サッカー', '野球', 'バスケ'],
            '個人競技' => ['陸上', '水泳', 'テニス', '卓球'],
            '観戦' => [],
        ],
        '娯楽' => [
            'ゲーム' => ['家庭用', 'スマホゲーム', 'PCゲーム'],
            '映画・ドラマ' => ['映画', 'アニメ', 'ドラマ'],
            '音楽' => [],
        ],
        '勉強' => [
            '学校' => ['授業', 'レポート', '試験'],
            '資格' => ['IT', '語学', 'その他資格'],
            '自学' => [],
        ],
        '雑談' => [
            '日常' => [],
            '質問' => [],
        ],
        '連絡' => [
            'お知らせ' => [],
            '募集' => [],
        ],
    ];
}

/**
 * フラットな選択肢一覧（path => 表示ラベル）
 * 例: '料理/和食/寿司' => '料理 › 和食 › 寿司'
 */
function getCategoryFlatOptions(): array
{
    $options = [];
    $tree = getCategoryTree();

    foreach ($tree as $l1 => $l2map) {
        $options[$l1] = $l1;

        if (!is_array($l2map) || $l2map === []) {
            continue;
        }

        foreach ($l2map as $l2 => $l3list) {
            $path2 = $l1 . '/' . $l2;
            $options[$path2] = $l1 . ' › ' . $l2;

            if (!is_array($l3list) || $l3list === []) {
                continue;
            }

            foreach ($l3list as $l3) {
                $path3 = $path2 . '/' . $l3;
                $options[$path3] = $l1 . ' › ' . $l2 . ' › ' . $l3;
            }
        }
    }

    return $options;
}

function isValidCategoryPath(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    return array_key_exists($path, getCategoryFlatOptions());
}

function formatCategoryLabel(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '未分類';
    }
    $options = getCategoryFlatOptions();
    if (isset($options[$path])) {
        return $options[$path];
    }
    // 旧データ（フラット名のみ）の互換表示
    return str_replace('/', ' › ', $path);
}

/**
 * フィルタ用: 選択パスとその子孫すべてに一致する値一覧
 */
function getCategoryFilterValues(string $path): array
{
    $path = trim($path);
    if ($path === '' || $path === 'all') {
        return [];
    }

    $values = [];
    foreach (array_keys(getCategoryFlatOptions()) as $optionPath) {
        if ($optionPath === $path || strpos($optionPath, $path . '/') === 0) {
            $values[] = $optionPath;
        }
    }

    // 旧データの単一カテゴリ名とも一致させる
    $parts = explode('/', $path);
    if (count($parts) === 1 && !in_array($parts[0], $values, true)) {
        $values[] = $parts[0];
    }

    return $values;
}

function ensureCategoryColumn(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'category'");
        $col = $stmt->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT '雑談'");
        } else {
            $type = strtolower((string)($col['Type'] ?? ''));
            if (preg_match('/varchar\((\d+)\)/', $type, $m) && (int)$m[1] < 100) {
                $pdo->exec("ALTER TABLE posts MODIFY COLUMN category VARCHAR(100) NOT NULL DEFAULT '雑談'");
            }
        }
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }

    return $ready;
}

/**
 * 階層選択UI用のHTML（3段セレクト）
 */
function renderCategoryCascadeSelects(
    string $selectedPath = '雑談',
    string $name = 'category',
    string $idPrefix = 'cat',
    bool $required = true,
    bool $includeAll = false
): string {
    $tree = getCategoryTree();
    $parts = $selectedPath === 'all' || $selectedPath === ''
        ? []
        : explode('/', $selectedPath);
    $sel1 = $parts[0] ?? ($includeAll ? '' : '雑談');
    $sel2 = $parts[1] ?? '';
    $sel3 = $parts[2] ?? '';

    $treeJson = htmlspecialchars(json_encode($tree, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $reqAttr = $required && !$includeAll ? ' required' : '';

    $html = '<div class="category-cascade" data-tree="' . $treeJson . '" data-prefix="' . htmlspecialchars($idPrefix) . '">';
    $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" id="' . htmlspecialchars($idPrefix) . '-path" value="' . htmlspecialchars($selectedPath) . '">';

    $html .= '<div class="category-cascade-row">';
    $html .= '<div class="form-group category-level">';
    $html .= '<label for="' . htmlspecialchars($idPrefix) . '-l1">大カテゴリ</label>';
    $html .= '<select id="' . htmlspecialchars($idPrefix) . '-l1" class="cat-l1"' . $reqAttr . '>';
    if ($includeAll) {
        $html .= '<option value="">すべて</option>';
    }
    foreach (array_keys($tree) as $l1) {
        $selected = ($sel1 === $l1) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($l1) . '"' . $selected . '>' . htmlspecialchars($l1) . '</option>';
    }
    $html .= '</select></div>';

    $html .= '<div class="form-group category-level">';
    $html .= '<label for="' . htmlspecialchars($idPrefix) . '-l2">中カテゴリ</label>';
    $html .= '<select id="' . htmlspecialchars($idPrefix) . '-l2" class="cat-l2"><option value="">（任意）</option></select>';
    $html .= '</div>';

    $html .= '<div class="form-group category-level">';
    $html .= '<label for="' . htmlspecialchars($idPrefix) . '-l3">小カテゴリ</label>';
    $html .= '<select id="' . htmlspecialchars($idPrefix) . '-l3" class="cat-l3"><option value="">（任意）</option></select>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<script>
(function(){
  var root = document.currentScript.parentElement;
  var tree = JSON.parse(root.getAttribute("data-tree"));
  var prefix = root.getAttribute("data-prefix");
  var l1 = root.querySelector(".cat-l1");
  var l2 = root.querySelector(".cat-l2");
  var l3 = root.querySelector(".cat-l3");
  var hidden = document.getElementById(prefix + "-path");
  var init1 = ' . json_encode($sel1, JSON_UNESCAPED_UNICODE) . ';
  var init2 = ' . json_encode($sel2, JSON_UNESCAPED_UNICODE) . ';
  var init3 = ' . json_encode($sel3, JSON_UNESCAPED_UNICODE) . ';

  function fillL2(keep) {
    var key = l1.value;
    l2.innerHTML = "<option value=\\"\\">（任意）</option>";
    l3.innerHTML = "<option value=\\"\\">（任意）</option>";
    if (!key || !tree[key]) { updatePath(); return; }
    Object.keys(tree[key]).forEach(function(name){
      var opt = document.createElement("option");
      opt.value = name;
      opt.textContent = name;
      if (keep && name === init2) opt.selected = true;
      l2.appendChild(opt);
    });
    fillL3(keep);
  }

  function fillL3(keep) {
    var key1 = l1.value;
    var key2 = l2.value;
    l3.innerHTML = "<option value=\\"\\">（任意）</option>";
    if (!key1 || !key2 || !tree[key1] || !tree[key1][key2]) { updatePath(); return; }
    (tree[key1][key2] || []).forEach(function(name){
      var opt = document.createElement("option");
      opt.value = name;
      opt.textContent = name;
      if (keep && name === init3) opt.selected = true;
      l3.appendChild(opt);
    });
    updatePath();
  }

  function updatePath() {
    var parts = [];
    if (l1.value) parts.push(l1.value);
    if (l1.value && l2.value) parts.push(l2.value);
    if (l1.value && l2.value && l3.value) parts.push(l3.value);
    hidden.value = parts.length ? parts.join("/") : (l1.querySelector("option[value=\'\']") ? "all" : "");
  }

  l1.addEventListener("change", function(){ init2 = ""; init3 = ""; fillL2(false); });
  l2.addEventListener("change", function(){ init3 = ""; fillL3(false); });
  l3.addEventListener("change", updatePath);

  if (init1) {
    l1.value = init1;
    fillL2(true);
  } else {
    updatePath();
  }
})();
</script>';

    $html .= '</div>';
    return $html;
}
