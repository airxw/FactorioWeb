<?php
// maps.php
$saveDir = '../server/saves';
if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

$msg = '';

// 处理上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['map_file'])) {
    $file = $_FILES['map_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($ext !== 'zip') {
        $msg = "❌ 只能上传 .zip 格式的地图存档！";
    } else {
        // 【修正点】: 不再使用正则过滤中文，改为只过滤路径穿越符
        $rawName = $file['name'];
        // 1. 去掉路径分隔符，防止传到其他目录
        $safeName = str_replace(array('/', '\\'), '', $rawName);
        // 2. 避免文件名包含 ".."
        $safeName = str_replace('..', '', $safeName);
        
        // 3. 简单的重名处理（可选，防止覆盖）
        // if (file_exists("$saveDir/$safeName")) { $msg = "❌ 文件已存在"; ... }

        if (move_uploaded_file($file['tmp_name'], "$saveDir/$safeName")) {
            $msg = "✅ 地图上传成功: $safeName";
        } else {
            $msg = "❌ 上传失败，请检查目录权限。";
        }
    }
}

// 处理删除
if (isset($_GET['del'])) {
    // 同样只取 basename 防止删除其他文件
    $fileToDelete = "$saveDir/" . basename($_GET['del']); 
    if (file_exists($fileToDelete)) unlink($fileToDelete);
    header("Location: maps.php");
    exit;
}

$maps = glob("$saveDir/*.zip");
?>
<!DOCTYPE html>
<html>
<head>
    <title>地图管理</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>body{padding:20px;}</style>
</head>
<body>
<div class="container" style="max-width: 800px;">
    <h3>🗺️ 地图存档管理</h3>
    
    <?php if($msg): ?><div class="alert alert-info"><?= $msg ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
                <input type="file" name="map_file" class="form-control" required accept=".zip">
                <button type="submit" class="btn btn-success">上传地图</button>
            </form>
        </div>
    </div>

    <table class="table table-bordered">
        <thead><tr><th>文件名</th><th>大小</th><th>操作</th></tr></thead>
        <tbody>
            <?php foreach($maps as $map): ?>
            <tr>
                <td><?= htmlspecialchars(basename($map)) ?></td>
                <td><?= round(filesize($map)/1024/1024, 2) ?> MB</td>
                <td>
                    <a href="?del=<?= urlencode(basename($map)) ?>" class="btn btn-sm btn-danger" onclick="return confirm('确定删除？')">删除</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <a href="index.php" class="btn btn-secondary">返回控制台</a>
</div>
</body>
</html>