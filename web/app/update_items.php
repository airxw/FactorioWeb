<?php
/**
 * 从 Factorio 游戏数据中提取物品翻译
 * 更新 items.json 文件
 */

define('BASE_DIR', dirname(dirname(__DIR__)));
define('WEB_DIR', __DIR__);
define('OUTPUT_FILE', dirname(WEB_DIR) . '/config/game/items.json');

$localeFiles = [
    BASE_DIR . '/data/base/locale/zh-CN/base.cfg',
    BASE_DIR . '/data/space-age/locale/zh-CN/space-age.cfg',
    BASE_DIR . '/data/quality/locale/zh-CN/quality.cfg',
    BASE_DIR . '/data/elevated-rails/locale/zh-CN/elevated-rails.cfg',
];

$items = [
    'logistics' => [],
    'production' => [],
    'combat' => [],
    'intermediate' => [],
    'space-age' => [],
    'equipment' => [],
    'other' => [],
];

$allItems = [];

function parseLocaleFile($file) {
    if (!file_exists($file)) {
        return [];
    }
    
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    
    $result = [
        'item-name' => [],
        'entity-name' => [],
        'fluid-name' => [],
        'equipment-name' => [],
    ];
    
    $currentSection = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (empty($line) || strpos($line, ';') === 0) {
            continue;
        }
        
        if (preg_match('/^\[([^\]]+)\]$/', $line, $matches)) {
            $currentSection = $matches[1];
            continue;
        }
        
        if ($currentSection && strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            if (isset($result[$currentSection])) {
                $result[$currentSection][$key] = $value;
            }
        }
    }
    
    return $result;
}

foreach ($localeFiles as $file) {
    $data = parseLocaleFile($file);
    
    foreach ($data as $section => $items_data) {
        foreach ($items_data as $code => $name) {
            if (!isset($allItems[$code])) {
                $allItems[$code] = $name;
            }
        }
    }
}

$logisticsItems = [
    'raw-wood', 'wood', 'coal', 'stone', 'iron-ore', 'copper-ore', 'uranium-ore',
    'transport-belt', 'fast-transport-belt', 'express-transport-belt', 'turbo-transport-belt',
    'underground-belt', 'fast-underground-belt', 'express-underground-belt', 'turbo-underground-belt',
    'splitter', 'fast-splitter', 'express-splitter', 'turbo-splitter',
    'inserter', 'long-handed-inserter', 'fast-inserter', 'filter-inserter', 'stack-inserter', 'stack-filter-inserter', 'burner-inserter', 'bulk-inserter',
    'pipe', 'pipe-to-ground', 'pump', 'pumpjack', 'storage-tank',
    'wooden-chest', 'iron-chest', 'steel-chest',
    'passive-provider-chest', 'active-provider-chest', 'requester-chest', 'buffer-chest', 'storage-chest',
    'construction-robot', 'logistic-robot', 'roboport',
    'car', 'tank', 'locomotive', 'cargo-wagon', 'fluid-wagon',
    'rail', 'train-stop', 'rail-signal', 'rail-chain-signal',
    'infinity-pipe', 'infinity-chest', 'linked-belt', 'linked-chest',
    'loader', 'fast-loader', 'express-loader',
];

$productionItems = [
    'burner-mining-drill', 'electric-mining-drill', 'big-mining-drill',
    'offshore-pump', 'stone-furnace', 'steel-furnace', 'electric-furnace',
    'assembling-machine-1', 'assembling-machine-2', 'assembling-machine-3',
    'oil-refinery', 'chemical-plant', 'centrifuge', 'lab',
    'boiler', 'steam-engine', 'steam-turbine', 'solar-panel', 'accumulator',
    'nuclear-reactor', 'heat-pipe', 'heat-exchanger',
    'beacon', 'radar',
    'foundry', 'electromagnetic-plant', 'cryogenic-plant', 'recycler', 'biolab', 'biochamber',
    'crusher', 'agricultural-tower', 'asteroid-collector',
    'heating-tower', 'fusion-generator', 'fusion-reactor',
    'lightning-collector', 'lightning-rod', 'tesla-turret', 'railgun-turret',
];

$combatItems = [
    'pistol', 'submachine-gun', 'shotgun', 'combat-shotgun', 'rocket-launcher', 'flamethrower', 'railgun', 'laser-rifle',
    'firearm-magazine', 'piercing-rounds-magazine', 'uranium-rounds-magazine',
    'shotgun-shell', 'piercing-shotgun-shell',
    'grenade', 'cluster-grenade', 'land-mine', 'poison-capsule', 'slowdown-capsule',
    'rocket', 'explosive-rocket', 'atomic-bomb',
    'cannon-shell', 'explosive-cannon-shell', 'uranium-cannon-shell', 'explosive-uranium-cannon-shell',
    'artillery-shell', 'artillery-targeting-remote',
    'defender-capsule', 'distractor-capsule', 'destroyer-capsule',
    'light-armor', 'heavy-armor', 'modular-armor', 'power-armor', 'power-armor-mk2',
    'gun-turret', 'laser-turret', 'flamethrower-turret', 'artillery-turret',
    'stone-wall', 'gate', 'repair-pack', 'cliff-explosives',
];

$intermediateItems = [
    'iron-plate', 'copper-plate', 'steel-plate', 'stone-brick',
    'iron-gear-wheel', 'iron-stick', 'copper-cable', 'copper-wire',
    'electronic-circuit', 'advanced-circuit', 'processing-unit',
    'battery', 'plastic-bar', 'sulfur', 'explosives', 'solid-fuel',
    'engine-unit', 'electric-engine-unit', 'flying-robot-frame',
    'low-density-structure', 'rocket-fuel', 'rocket-part', 'satellite',
    'uranium-235', 'uranium-238', 'uranium-fuel-cell', 'depleted-uranium-fuel-cell', 'nuclear-fuel',
    'automation-science-pack', 'logistic-science-pack', 'chemical-science-pack',
    'military-science-pack', 'production-science-pack', 'utility-science-pack', 'space-science-pack',
    'efficiency-module', 'efficiency-module-2', 'efficiency-module-3',
    'productivity-module', 'productivity-module-2', 'productivity-module-3',
    'speed-module', 'speed-module-2', 'speed-module-3',
    'blueprint', 'blueprint-book', 'deconstruction-planner', 'upgrade-planner',
    'red-wire', 'green-wire', 'repair-pack', 'barrel',
];

$spaceAgeItems = [
    'tungsten-ore', 'tungsten-carbide', 'tungsten-plate',
    'holmium-ore', 'holmium-plate', 'superconductor', 'supercapacitor',
    'lithium-ore', 'lithium-plate', 'lithium',
    'calcite', 'sulfuric-acid',
    'agricultural-science-pack', 'electromagnetic-science-pack', 'metallurgic-science-pack', 'cryogenic-science-pack', 'promethium-science-pack',
    'carbonic-asteroid-chunk', 'metallic-asteroid-chunk', 'oxide-asteroid-chunk', 'promethium-asteroid-chunk',
    'thruster', 'space-platform-hub', 'cargo-bay', 'cargo-pod', 'cargo-landing-pad',
    'yumako-tree', 'jellystem', 'pentapod-egg', 'bioflux',
    'capture-robot-rocket',
    'quality-module', 'quality-module-2', 'quality-module-3',
];

$equipmentItems = [
    'solar-panel-equipment', 'battery-equipment', 'battery-mk2-equipment',
    'fusion-reactor-equipment', 'fission-reactor-equipment',
    'energy-shield-equipment', 'energy-shield-mk2-equipment',
    'personal-laser-defense-equipment', 'discharge-defense-equipment',
    'exoskeleton-equipment', 'night-vision-equipment',
    'personal-roboport-equipment', 'personal-roboport-mk2-equipment',
    'belt-immunity-equipment',
];

function addItem(&$category, $code, $allItems) {
    if (isset($allItems[$code])) {
        $category[$code] = $allItems[$code];
    } else {
        $category[$code] = $code;
    }
}

foreach ($logisticsItems as $code) {
    addItem($items['logistics'], $code, $allItems);
}

foreach ($productionItems as $code) {
    addItem($items['production'], $code, $allItems);
}

foreach ($combatItems as $code) {
    addItem($items['combat'], $code, $allItems);
}

foreach ($intermediateItems as $code) {
    addItem($items['intermediate'], $code, $allItems);
}

foreach ($spaceAgeItems as $code) {
    addItem($items['space-age'], $code, $allItems);
}

foreach ($equipmentItems as $code) {
    addItem($items['equipment'], $code, $allItems);
}

$extraItems = [
    'concrete' => '标准混凝土',
    'refined-concrete' => '钢筋混凝土',
    'hazard-concrete' => '标准混凝土（标识）',
    'refined-hazard-concrete' => '钢筋混凝土（标识）',
    'landfill' => '填埋材料',
    'space-platform-foundation' => '太空平台地基',
    'coin' => '金币',
    'raw-fish' => '鲜鱼',
    'wooden-chest' => '木箱',
    'iron-chest' => '铁箱',
    'steel-chest' => '钢箱',
    'active-provider-chest' => '主动供货箱（紫箱）',
    'passive-provider-chest' => '被动供货箱（红箱）',
    'requester-chest' => '优先集货箱（蓝箱）',
    'buffer-chest' => '主动存货箱（绿箱）',
    'small-electric-pole' => '小型电线杆',
    'medium-electric-pole' => '中型电线杆',
    'big-electric-pole' => '远程输电塔',
    'substation' => '广域配电站',
    'power-switch' => '电闸',
    'programmable-speaker' => '程控扬声器',
    'display-panel' => '显示器',
    'arithmetic-combinator' => '算术运算器',
    'decider-combinator' => '判断运算器',
    'constant-combinator' => '常量运算器',
    'selector-combinator' => '选择运算器',
];

foreach ($extraItems as $code => $name) {
    $items['other'][$code] = $name;
}

foreach ($allItems as $code => $name) {
    $found = false;
    foreach ($items as $category) {
        if (isset($category[$code])) {
            $found = true;
            break;
        }
    }
    
    if (!$found && strpos($code, '-remnants') === false && strpos($code, '-corpse') === false) {
        if (preg_match('/^quality-/', $code)) continue;
        if (preg_match('/-quality-\d$/', $code)) continue;
        
        $items['other'][$code] = $name;
    }
}

$json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents(OUTPUT_FILE, $json);

$totalCount = 0;
foreach ($items as $category => $categoryItems) {
    $count = count($categoryItems);
    $totalCount += $count;
    echo "分类 $category: $count 项\n";
}

echo "\n总计: $totalCount 项物品\n";
echo "已保存到: " . OUTPUT_FILE . "\n";
