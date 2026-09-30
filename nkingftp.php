<?php

/**
 * 2025.php
 * @author shim, Ryan
 */
// 配置参数
$CONFIG = array(
    'ACCESS_PASSWORD' => 'king8722132', // 访问密码，请务必修改为强密码
    'AUTH_COOKIE_NAME' => 'file_manager_auth', // 认证cookie名称
    'AUTH_COOKIE_EXPIRE' => 24 * 3600, // 认证cookie过期时间（24小时）
    'MAX_FILE_SIZE' => 2 * 1024 * 1024, // 最大文件大小默认值（2MB），当无法从PHP配置读取时使用
    'ALLOWED_TYPES' => '无限制', // 允许的文件类型
    'MAX_CONCURRENT' => 3, // 最大并发数
    // 分片上传配置
    'CHUNK_SIZE' => 1024 * 1024, // 分片大小：1MB（保守值，确保不超过 upload_max_filesize=2M 限制，留出空间给表单开销）
    'CHUNK_DIR' => '.chunks', // 临时分片存储目录
    'MAX_UPLOAD_SIZE' => 2 * 1024 * 1024 * 1024 // 最大上传文件大小：2GB（防止上传过大文件）
);

// 启用输出缓冲，防止headers already sent   误
ob_start();

// 辅助函数：将 php.ini 中的大小字符串转换为字节数
function convertToBytes($value)
{
    if (empty($value)) {
        return null;
    }
    $value = trim($value);
    $last = strtolower($value[strlen($value) - 1]);
    $numeric = (int)$value;
    switch ($last) {
        case 'g':
            $numeric *= 1024;
        case 'm':
            $numeric *= 1024;
        case 'k':
            $numeric *= 1024;
    }
    return $numeric;
}

// 动态获取最大文件上传大小
function getMaxFileSize()
{
    global $CONFIG;

    // 从 PHP 配置中获取 upload_max_filesize 和 post_max_size
    $uploadMaxFilesize = convertToBytes(ini_get('upload_max_filesize'));
    $postMaxSize = convertToBytes(ini_get('post_max_size'));

    // 取两者中的较小值
    if ($uploadMaxFilesize !== null && $postMaxSize !== null) {
        return min($uploadMaxFilesize, $postMaxSize);
    } elseif ($uploadMaxFilesize !== null) {
        return $uploadMaxFilesize;
    } elseif ($postMaxSize !== null) {
        return $postMaxSize;
    }

    // 如果都无法读取，则使用配置中的默认值
    return $CONFIG['MAX_FILE_SIZE'];
}

// 动态设置最大文件大小
$CONFIG['MAX_FILE_SIZE'] = getMaxFileSize();

// 动态调整分片大小，确保不超过 upload_max_filesize 的 50%
// 这样可以为表单字段留出足够的空间
$uploadLimit = $CONFIG['MAX_FILE_SIZE'];
$CONFIG['CHUNK_SIZE'] = max(500 * 1024, intval($uploadLimit * 0.5)); // 最小 500KB，最大为限制的一半
// 确保分片大小不超过 5MB
$CONFIG['CHUNK_SIZE'] = min($CONFIG['CHUNK_SIZE'], 5 * 1024 * 1024);

// 获取当前脚本完整URL，用于动态生成链接
$currentFile = $_SERVER['PHP_SELF'];

// 自定义gzdecode函数，兼容PHP 5.2版本
if (!function_exists('gzdecode')) {
    function gzdecode($data)
    {
        $len = strlen($data);
        if ($len < 18 || strcmp(substr($data, 0, 2), "\x1f\x8b")) {
            return null; // Not GZIP format
        }
        $method = ord(substr($data, 2, 1)); // Compression method
        $flags = ord(substr($data, 3, 1)); // Flags
        if (($flags & 31) !== $flags) {
            // Reserved bits are set
            return null;
        }
        // Skip MTIME, XFL, OS fields
        $headerlen = 10;
        $extralen = 0;
        $extra = '';
        if ($flags & 4) {
            // Extras present
            $extralen = unpack('v', substr($data, 8, 2));
            $extralen = $extralen[1];
            $extra = substr($data, 10, $extralen);
            $headerlen += 2 + $extralen;
        }
        $filenamelen = 0;
        $filename = '';
        if ($flags & 8) {
            // Filename present
            $filenamelen = strpos(substr($data, $headerlen), "\x00");
            if ($filenamelen === false) {
                return null;
            }
            $filename = substr($data, $headerlen, $filenamelen);
            $headerlen += $filenamelen + 1;
        }
        $commentlen = 0;
        $comment = '';
        if ($flags & 16) {
            // Comment present
            $commentlen = strpos(substr($data, $headerlen), "\x00");
            if ($commentlen === false) {
                return null;
            }
            $comment = substr($data, $headerlen, $commentlen);
            $headerlen += $commentlen + 1;
        }
        $headercrc = '';
        if ($flags & 2) {
            // Header CRC present
            $headercrc = substr($data, $headerlen, 2);
            $headerlen += 2;
            // Calculate CRC
        }
        // Compressed data starts here
        $compressed = substr($data, $headerlen, $len - $headerlen - 8);
        $decompressed = gzinflate($compressed);
        if ($decompressed === false) {
            return null;
        }
        return $decompressed;
    }
}


// 错误消息变量初始化
$error = '';
$success = '';
$uploadedFiles = array();

// 辅助函数 - 安全处理文件名和文件夹名
function sanitizePath($path, $isFileName = false)
{
    // 支持中文等Unicode字符，只移除危险字符
    // 如果是文件名，允许包含点号
    $pattern = $isFileName ? '/[^\p{L}\p{N}\-\.\/]/u' : '/[^\p{L}\p{N}\-\/]/u';
    return preg_replace($pattern, '_', $path);
}

// 辅助函数 - 处理中文路径的basename函数
function mb_basename($path)
{
    $path = rtrim($path, '/');
    if (empty($path)) {
        return '';
    }
    $lastSlash = strrpos($path, '/');
    if ($lastSlash === false) {
        return $path;
    }
    return substr($path, $lastSlash + 1);
}

// 辅助函数 - 处理中文路径的dirname函数
function mb_dirname($path)
{
    $path = rtrim($path, '/');
    $lastSlash = strrpos($path, '/');
    if ($lastSlash === false) {
        return '.';
    }
    $dirname = substr($path, 0, $lastSlash);
    return $dirname === '' ? '/' : $dirname;
}

// 辅助函数 - 判断是否为文本文件
function isTextFile($fileName)
{
    // 使用关联数组（哈希表）提高查找效率
    $textExtensions = array(
        'txt' => true,
        'md' => true,
        'html' => true,
        'htm' => true,
        'css' => true,
        'js' => true,
        'php' => true,
        'py' => true,
        'java' => true,
        'c' => true,
        'cpp' => true,
        'h' => true,
        'hpp' => true,
        'json' => true,
        'xml' => true,
        'ini' => true,
        'conf' => true,
        'log' => true,
        'sh' => true,
        'bat' => true,
        'cmd' => true,
        'csv' => true,
        'tsv' => true,
        'yaml' => true,
        'yml' => true,
        'sql' => true,
        'pl' => true,
        'rb' => true,
        'swift' => true,
        'go' => true,
        'rs' => true,
        'kt' => true,
        'vue' => true,
        'jsx' => true,
        'tsx' => true,
        'scss' => true,
        'sass' => true
    );

    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return isset($textExtensions[$extension]);
}

// 辅助函数 - 验证路径安全性
function validatePath($path, $basePath)
{
    // 简化路径验证逻辑，使用realpath和strpos双重检查
    $realPath = @realpath($path);
    $realBasePath = @realpath($basePath);

    // 如果realpath成功，使用realpath结果进行检查
    if ($realPath !== false && $realBasePath !== false) {
        return strpos($realPath, $realBasePath) === 0;
    }

    // 否则使用原始路径进行基本检查
    return strpos($path, $basePath) === 0;
}

// 辅助函数 - 递归删除文件夹
function deleteDirectory($dir)
{
    // 检查路径是否存在
    if (!file_exists($dir)) {
        return true;
    }

    // 如果是文件，直接删除
    if (!is_dir($dir)) {
        return unlink($dir);
    }

    // 遍历目录内容
    $items = scandir($dir);
    foreach ($items as $item) {
        // 跳过当前目录和父目录
        if ($item === '.' || $item === '..') {
            continue;
        }

        // 构建完整路径
        $itemPath = $dir . DIRECTORY_SEPARATOR . $item;

        // 递归删除子项
        if (!deleteDirectory($itemPath)) {
            return false;
        }
    }

    // 删除空目录
    return rmdir($dir);
}

// 辅助函数 - 递归复制文件夹
function copyDirectory($src, $dst)
{
    if (!file_exists($dst)) {
        mkdir($dst, 0755, true);
    }

    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if ($file != '.' && $file != '..') {
            $srcFile = $src . DIRECTORY_SEPARATOR . $file;
            $dstFile = $dst . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcFile)) {
                copyDirectory($srcFile, $dstFile);
            } else {
                copy($srcFile, $dstFile);
            }
        }
    }
    closedir($dir);
    return true;
}

// 辅助函数 - 递归收集所有文件
function collectAllFiles($dir, &$fileList, $baseDir = null)
{
    if ($baseDir === null) {
        $baseDir = $dir;
    }

    $handle = opendir($dir);
    while (false !== ($file = readdir($handle))) {
        if ($file != '.' && $file != '..') {
            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
            $relativePath = substr($fullPath, strlen($baseDir) + 1);

            if (is_dir($fullPath)) {
                // 递归处理子目录
                collectAllFiles($fullPath, $fileList, $baseDir);
            } else {
                // 添加文件到列表
                $fileList[$fullPath] = $relativePath;
            }
        }
    }
    closedir($handle);
}

// 辅助函数 - 获取所有文件夹列表
function getFolderTree($baseDir, $currentDir = '', &$result = array())
{
    $fullPath = $baseDir . '/' . $currentDir;
    $folders = scandir($fullPath);

    foreach ($folders as $folder) {
        if ($folder === '.' || $folder === '..') continue;

        $folderPath = $fullPath . '/' . $folder;
        if (is_dir($folderPath)) {
            $relativePath = $currentDir . '/' . $folder;
            array_push($result, ltrim($relativePath, '/'));
            getFolderTree($baseDir, $relativePath, $result);
        }
    }

    return $result;
}



// 从URL参数获取消息
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// 处理密码验证
if (isset($_POST['password_submit'])) {
    $enteredPassword = stripslashes($_POST['password']);
    if ($enteredPassword === $CONFIG['ACCESS_PASSWORD']) {
        // 登录成功，设置认证cookie
        $authToken = md5(uniqid(rand(), true));
        setcookie($CONFIG['AUTH_COOKIE_NAME'], $authToken, time() + $CONFIG['AUTH_COOKIE_EXPIRE'], '/');
        // 使用PRG模式，防止刷新页面重新提交
        header('Location: ' . $currentFile . '?success=' . urlencode('登录成功！'));
        exit();
    } else {
        // 密码错误
        header('Location: ' . $currentFile . '?error=' . urlencode('密码错误，请重试。'));
        exit();
    }
}

// 处理登出
if (isset($_GET['logout'])) {
    // 删除认证cookie
    setcookie($CONFIG['AUTH_COOKIE_NAME'], '', time() - 3600, '/');
    header('Location: ' . $currentFile . '?success=' . urlencode('已成功退出登录！'));
    exit();
}

// 处理密码修改
if (isset($_POST['change_password'])) {
    $currentPassword = stripslashes($_POST['current_password']);
    $newPassword = stripslashes($_POST['new_password']);
    $confirmPassword = stripslashes($_POST['confirm_password']);

    // 验证当前密码
    if ($currentPassword !== $CONFIG['ACCESS_PASSWORD']) {
        $errorUrl = $currentFile . '?error=' . urlencode('当前密码错误，请重试。');
        if (isset($_GET['path'])) {
            $errorUrl = $currentFile . '?path=' . urlencode($_GET['path']) . '&error=' . urlencode('当前密码错误，请重试。');
        }
        header('Location: ' . $errorUrl);
        exit();
    }

    // 验证新密码长度
    if (strlen($newPassword) < 6) {
        $errorUrl = $currentFile . '?error=' . urlencode('新密码长度不能少于6个字符。');
        if (isset($_GET['path'])) {
            $errorUrl = $currentFile . '?path=' . urlencode($_GET['path']) . '&error=' . urlencode('新密码长度不能少于6个字符。');
        }
        header('Location: ' . $errorUrl);
        exit();
    }

    // 验证两次输入的新密码是否一致
    if ($newPassword !== $confirmPassword) {
        $errorUrl = $currentFile . '?error=' . urlencode('两次输入的新密码不一致，请重试。');
        if (isset($_GET['path'])) {
            $errorUrl = $currentFile . '?path=' . urlencode($_GET['path']) . '&error=' . urlencode('两次输入的新密码不一致，请重试。');
        }
        header('Location: ' . $errorUrl);
        exit();
    }

    // 读取当前脚本内容
    $scriptContent = file_get_contents(__FILE__);

    // 使用正则表达式替换密码
    $oldPasswordPattern = '/\'ACCESS_PASSWORD\'\s*=>\s*\'[^\']+\'/';
    $newPasswordLine = "'ACCESS_PASSWORD' => '{$newPassword}'";
    $newScriptContent = preg_replace($oldPasswordPattern, $newPasswordLine, $scriptContent);

    // 写入修改后的脚本内容
    if (file_put_contents(__FILE__, $newScriptContent)) {
        // 修改成功，更新当前会话中的密码
        $CONFIG['ACCESS_PASSWORD'] = $newPassword;

        // 重新设置认证cookie
        $authToken = md5(uniqid(rand(), true));
        setcookie($CONFIG['AUTH_COOKIE_NAME'], $authToken, time() + $CONFIG['AUTH_COOKIE_EXPIRE'], '/');

        // 构建正确的重定向URL
        $redirectUrl = $currentFile . '?success=' . urlencode('密码修改成功！请使用新密码登录。');
        if (isset($_GET['path'])) {
            $redirectUrl = $currentFile . '?path=' . urlencode($_GET['path']) . '&success=' . urlencode('密码修改成功！请使用新密码登录。');
        }
        header('Location: ' . $redirectUrl);
        exit();
    } else {
        // 构建正确的重定向URL
        $errorRedirectUrl = $currentFile . '?error=' . urlencode('密码修改失败，请检查服务器写入权限。');
        if (isset($_GET['path'])) {
            $errorRedirectUrl = $currentFile . '?path=' . urlencode($_GET['path']) . '&error=' . urlencode('密码修改失败，请检查服务器写入权限。');
        }
        header('Location: ' . $errorRedirectUrl);
        exit();
    }
}

// 检查用户是否已认证
$isAuthenticated = isset($_COOKIE[$CONFIG['AUTH_COOKIE_NAME']]);

// 如果用户已通过验证，则加载文件上传和文件列表功能
if ($isAuthenticated) {
    $basePath = dirname(__FILE__);

    // 处理获取文件夹列表的请求
    if (isset($_GET['get_folders'])) {
        // 返回所有文件夹的JSON数据
        $folders = getFolderTree($basePath);
        header('Content-Type: application/json');
        echo json_encode($folders);
        exit();
    }

    // 处理分片上传
    if (isset($_POST['chunk_upload'])) {
        $fileId = stripslashes($_POST['file_id']);
        $chunkIndex = intval($_POST['chunk_index']);
        $totalChunks = intval($_POST['total_chunks']);
        $fileName = sanitizePath(stripslashes($_POST['file_name']), true);
        $fileSize = intval($_POST['file_size']);

        // 验证文件大小不超过最大限制
        if ($fileSize > $CONFIG['MAX_UPLOAD_SIZE']) {
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'error' => '文件大小超过最大限制 (' . formatFileSize($CONFIG['MAX_UPLOAD_SIZE']) . ')'));
            exit();
        }

        // 检查文件上传是否有错误
        if (!isset($_FILES['chunk_data']) || $_FILES['chunk_data']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = '分片上传失败';
            if (isset($_FILES['chunk_data'])) {
                switch ($_FILES['chunk_data']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                        $errorMsg = '分片大小超过 PHP upload_max_filesize 限制 (' . ini_get('upload_max_filesize') . ')';
                        break;
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMsg = '分片大小超过表单 MAX_FILE_SIZE 限制';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = '没有接收到文件数据 (可能超过 post_max_size: ' . ini_get('post_max_size') . ')';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errorMsg = '文件只部分上传';
                        break;
                    default:
                        $errorMsg = '上传错误代码: ' . $_FILES['chunk_data']['error'];
                }
            } else {;
                $errorMsg = '未接收到文件，可能超过 post_max_size (' . ini_get('post_max_size') . ')';
            }

            header('Content-Type: application/json');
            echo json_encode(array(
                'success' => false,
                'error' => $errorMsg
            ));
            exit();
        }

        // 创建临时目录
        $chunkDir = $basePath . '/' . $CONFIG['CHUNK_DIR'] . '/' . $fileId;
        if (!file_exists($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        // 保存分片文件
        $chunkFile = $chunkDir . '/' . $chunkIndex;
        if (move_uploaded_file($_FILES['chunk_data']['tmp_name'], $chunkFile)) {
            // 保存元数据
            file_put_contents($chunkDir . '/meta.json', json_encode(array(
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'total_chunks' => $totalChunks
            )));

            header('Content-Type: application/json');
            echo json_encode(array('success' => true, 'chunk_index' => $chunkIndex));
        } else {
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'error' => '无法保存分片文件，请检查目录权限'));
        }
        exit();
    }

    // 处理分片合并
    if (isset($_POST['chunk_merge'])) {
        $fileId = stripslashes($_POST['file_id']);
        $chunkDir = $basePath . '/' . $CONFIG['CHUNK_DIR'] . '/' . $fileId;

        // 读取元数据
        $metaFile = $chunkDir . '/meta.json';
        if (!file_exists($metaFile)) {
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'error' => '元数据不存在'));
            exit();
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        $currentPath = isset($_POST['current_path']) ? stripslashes($_POST['current_path']) : '';

        // 构建目标文件路径
        $targetDir = $basePath . ($currentPath ? '/' . $currentPath : '');
        $targetFile = $targetDir . '/' . $meta['file_name'];

        // 检查文件是否已存在
        if (file_exists($targetFile)) {
            $filenameWithoutExt = pathinfo($meta['file_name'], PATHINFO_FILENAME);
            $fileExt = pathinfo($meta['file_name'], PATHINFO_EXTENSION);
            $meta['file_name'] = $filenameWithoutExt . '_' . time() . '.' . $fileExt;
            $targetFile = $targetDir . '/' . $meta['file_name'];
        }

        // 合并分片
        $targetHandle = fopen($targetFile, 'wb');
        if (!$targetHandle) {
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'error' => '无法创建目标文件'));
            exit();
        }

        for ($i = 0; $i < $meta['total_chunks']; $i++) {
            $chunkFile = $chunkDir . '/' . $i;
            if (file_exists($chunkFile)) {
                $chunkData = file_get_contents($chunkFile);
                fwrite($targetHandle, $chunkData);
            } else {
                fclose($targetHandle);
                unlink($targetFile);
                header('Content-Type: application/json');
                echo json_encode(array('success' => false, 'error' => '分片 ' . $i . ' 缺失'));
                exit();
            }
        }
        fclose($targetHandle);

        // 清理临时文件
        deleteDirectory($chunkDir);

        header('Content-Type: application/json');
        echo json_encode(array('success' => true, 'file_name' => $meta['file_name']));
        exit();
    }

    // 处理文件编辑请求
    if (isset($_GET['edit'])) {
        $fileNameToEdit = rawurldecode($_GET['edit']);
        $fileNameToEdit = sanitizePath($fileNameToEdit, true);
        $fileToEdit = $basePath . '/' . $fileNameToEdit;
        $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : dirname($fileNameToEdit);
        // 修复根目录下的当前位置问题，将 '.' 转换为空字符串
        if ($currentPath === '.') {
            $currentPath = '';
        }

        // 检查是否为文本文件
        if (!isTextFile(mb_basename($fileToEdit))) {
            header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('只允许编辑文本文件'));
            exit();
        }

        // 处理表单提交
        if (isset($_POST['save_file'])) {
            $fileContent = stripslashes($_POST['file_content']);

            // 安全检查
            if (!file_exists($fileToEdit) || !is_file($fileToEdit) || !validatePath($fileToEdit, $basePath)) {
                header('Location: ' . $currentFile . '?error=' . urlencode('无效的文件编辑请求'));
                exit();
            }

            // 保存文件
            if (file_put_contents($fileToEdit, $fileContent) !== false) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode('文件已成功保存：' . mb_basename($fileNameToEdit)));
                exit();
            } else {
                header('Location: ' . $currentFile . '?error=' . urlencode('文件保存失败，请检查服务器权限'));
                exit();
            }
        }

        // 显示编辑表单
        if (file_exists($fileToEdit) && is_file($fileToEdit) && validatePath($fileToEdit, $basePath)) {
            $fileContent = file_get_contents($fileToEdit);
            $fileName = mb_basename($fileToEdit);

            // 显示编辑页面
            echo '<!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>编辑文件 - ' . htmlspecialchars($fileName) . '</title>
                <link rel="stylesheet" href="https://registry.npmmirror.com/@fortawesome/fontawesome-free/7.0.1/files/css/all.min.css">
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        background-color: #1a1a1a;
                        color: #e0e0e0;
                        margin: 0;
                        padding: 20px;
                    }
                    .container {
                        max-width: 1200px;
                        margin: 0 auto;
                    }
                    .back-link {
                        display: inline-block;
                        margin-bottom: 20px;
                        color: #1a73e8;
                        text-decoration: none;
                    }
                    .back-link:hover {
                        text-decoration: underline;
                    }
                    h1 {
                        margin-bottom: 20px;
                    }
                    form {
                        margin-top: 20px;
                    }
                    .form-group {
                        margin-bottom: 20px;
                    }
                    label {
                        display: block;
                        margin-bottom: 8px;
                        font-weight: bold;
                    }
                    textarea {
                        width: 100%;
                        height: 500px;
                        padding: 12px;
                        border: 1px solid #444;
                        border-radius: 4px;
                        background-color: #252525;
                        color: #e0e0e0;
                        font-family: Consolas, Monaco, monospace;
                        font-size: 14px;
                        resize: vertical;
                    }
                    .form-actions {
                        display: flex;
                        gap: 10px;
                    }
                    button {
                        background-color: #1a73e8;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 16px;
                    }
                    button:hover {
                        background-color: #1557b0;
                    }
                    .cancel-btn {
                        background-color: #666;
                    }
                    .cancel-btn:hover {
                        background-color: #555;
                    }
                    /* 页面加载蒙板 */
                    #loadingMask {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background-color: rgba(18, 18, 18, 0.8);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 9999;
                        flex-direction: column;
                    }
                    #loadingMask .spinner {
                        font-size: 48px;
                        color: #1a73e8;
                        margin-bottom: 20px;
                        animation: spin 1s linear infinite;
                    }
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                    #loadingMask .message {
                        font-size: 18px;
                        color: #e0e0e0;
                    }
                    /* 编辑表单容器 */
                    #editFormContainer {
                        display: none;
                    }
                </style>
            </head>
            <body>
                <!-- 页面加载蒙板 -->
                <div id="loadingMask">
                    <div class="spinner">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="message">文件加载中，请稍候...</div>
                </div>
                
                <div class="container">
                    <div id="editFormContainer">
                        <a href="' . $currentFile . '?path=' . urlencode($currentPath) . '" class="back-link"><i class="fas fa-arrow-left"></i> 返回文件列表</a>
                        <h1>编辑文件：' . htmlspecialchars($fileName) . '</h1>
                        <form method="post">
                            <div class="form-group">
                                <label for="file_content">文件内容</label>
                                <textarea id="file_content" name="file_content" required>' . htmlspecialchars($fileContent) . '</textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="save_file">保存文件</button>
                                <a href="' . $currentFile . '?path=' . urlencode($currentPath) . '"><button type="button" class="cancel-btn">取消</button></a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <script>
                    // 页面加载完成后隐藏蒙板，显示编辑表单
                    window.addEventListener("load", function() {
                        var loadingMask = document.getElementById("loadingMask");
                        var editFormContainer = document.getElementById("editFormContainer");
                        
                        // 添加淡出效果
                        loadingMask.style.transition = "opacity 0.5s ease";
                        loadingMask.style.opacity = "0";
                        
                        // 延迟后完全隐藏蒙板并显示表单
                        setTimeout(function() {
                            loadingMask.style.display = "none";
                            editFormContainer.style.display = "block";
                        }, 500);
                    });
                </script>
            </body>
            </html>';
            exit();
        } else {
            header('Location: ' . $currentFile . '?error=' . urlencode('文件不存在或无法编辑'));
            exit();
        }
    }

    // 处理重命名请求
    if (isset($_GET['rename'])) {
        $oldPath = rawurldecode($_GET['rename']);
        $oldPath = sanitizePath($oldPath, true);
        $itemType = isset($_GET['type']) ? $_GET['type'] : '';
        $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : mb_dirname($oldPath);
        // 修复根目录下的当前位置问题，将 '.' 转换为空字符串
        if ($currentPath === '.') {
            $currentPath = '';
        }

        // 构建完整路径
        $oldFullPath = $basePath . '/' . $oldPath;
        // 使用自定义函数处理中文路径
        $oldName = mb_basename($oldFullPath);

        // 处理表单提交
        if (isset($_POST['rename_item'])) {
            $newName = stripslashes($_POST['new_name']);

            // 安全处理新名称
            $newName = sanitizePath($newName, true);

            // 检查新名称是否为空
            if (empty($newName)) {
                header('Location: ' . $currentFile . '?error=' . urlencode('名称不能为空'));
                exit();
            }

            // 构建新路径
            $newFullPath = mb_dirname($oldFullPath) . '/' . $newName;

            // 安全检查
            if (!file_exists($oldFullPath) || !validatePath($oldFullPath, $basePath) || !validatePath($newFullPath, $basePath)) {
                header('Location: ' . $currentFile . '?error=' . urlencode('无效的重命名请求'));
                exit();
            }

            // 检查新名称是否已存在
            if (file_exists($newFullPath)) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('名称已存在：' . $newName));
                exit();
            }

            // 执行重命名
            if (rename($oldFullPath, $newFullPath)) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode(ucfirst($itemType) . '已成功重命名：' . $oldName . ' → ' . $newName));
                exit();
            } else {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('重命名失败，请检查服务器权限'));
                exit();
            }
        }

        // 显示重命名表单
        if (file_exists($oldFullPath) && validatePath($oldFullPath, $basePath)) {
            // 显示重命名页面
            echo '<!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>重命名' . ucfirst($itemType) . ' - ' . htmlspecialchars($oldName) . '</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        background-color: #1a1a1a;
                        color: #e0e0e0;
                        margin: 0;
                        padding: 20px;
                    }
                    .container {
                        max-width: 600px;
                        margin: 0 auto;
                    }
                    .back-link {
                        display: inline-block;
                        margin-bottom: 20px;
                        color: #1a73e8;
                        text-decoration: none;
                    }
                    .back-link:hover {
                        text-decoration: underline;
                    }
                    h1 {
                        margin-bottom: 20px;
                    }
                    form {
                        margin-top: 20px;
                    }
                    .form-group {
                        margin-bottom: 20px;
                    }
                    label {
                        display: block;
                        margin-bottom: 8px;
                        font-weight: bold;
                    }
                    input[type="text"] {
                        width: 100%;
                        padding: 10px;
                        border: 1px solid #444;
                        border-radius: 4px;
                        background-color: #252525;
                        color: #e0e0e0;
                        font-size: 16px;
                    }
                    .form-actions {
                        display: flex;
                        gap: 10px;
                    }
                    button {
                        background-color: #1a73e8;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 16px;
                    }
                    button:hover {
                        background-color: #1557b0;
                    }
                    .cancel-btn {
                        background-color: #666;
                    }
                    .cancel-btn:hover {
                        background-color: #555;
                    }
                    .old-name {
                        background-color: #333;
                        padding: 10px;
                        border-radius: 4px;
                        margin-top: 5px;
                        font-family: monospace;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <a href="' . $currentFile . '?path=' . urlencode($currentPath) . '" class="back-link"><i class="fas fa-arrow-left"></i> 返回文件列表</a>
                    <h1>重命名' . ucfirst($itemType) . '</h1>
                    <div class="form-group">
                        <label>原名称</label>
                        <div class="old-name">' . htmlspecialchars($oldName) . '</div>
                    </div>
                    <form method="post">
                        <div class="form-group">
                            <label for="new_name">新名称</label>
                            <input type="text" id="new_name" name="new_name" value="' . htmlspecialchars($oldName) . '" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="rename_item">保存</button>
                            <a href="' . $currentFile . '?path=' . urlencode($currentPath) . '"><button type="button" class="cancel-btn">取消</button></a>
                        </div>
                    </form>
                </div>
            </body>
            </html>';
            exit();
        } else {
            header('Location: ' . $currentFile . '?error=' . urlencode('项目不存在或无法重命名'));
            exit();
        }
    }



    // 处理文件删除请求
    if (isset($_GET['delete'])) {
        // 使用urldecode解码文件名，并确保完整恢复原始文件名
        $fileNameToDelete = rawurldecode($_GET['delete']);

        // 安全处理文件名
        $fileNameToDelete = sanitizePath($fileNameToDelete, true);

        // 构建完整路径
        $fileToDelete = $basePath . '/' . $fileNameToDelete;

        // 添加额外的安全检查，确保文件名不为空且不只是扩展名
        if (empty($fileNameToDelete) || strpos($fileNameToDelete, '.') === 0) {
            // 使用PRG模式
            header('Location: ' . $currentFile . '?error=' . urlencode('无效的文件名格式: ' . $fileNameToDelete));
            exit();
        }

        // 安全检查，确保不能删除当前脚本文件或非本目录文件
        $validFile = true;
        $errorReason = '';

        if ($fileNameToDelete === $currentFile) {
            $validFile = false;
            $errorReason = '禁止删除当前脚本文件';
        } elseif (!file_exists($fileToDelete)) {
            $validFile = false;
            $errorReason = '文件不存在: ' . $fileNameToDelete;
        } elseif (!is_file($fileToDelete)) {
            $validFile = false;
            $errorReason = '指定的路径不是文件: ' . $fileNameToDelete;
        } elseif (!validatePath($fileToDelete, $basePath)) {
            $validFile = false;
            $errorReason = '文件不在允许的目录内: ' . $fileNameToDelete;
        }

        // 获取当前路径或文件所在目录
        $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : mb_dirname($fileNameToDelete);
        if ($currentPath === '.') {
            $currentPath = ''; // 当前目录
        }

        if ($validFile) {
            if (unlink($fileToDelete)) {
                // 使用URL参数传递成功消息，并保留当前路径
                if ($currentPath) {
                    header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode('文件已成功删除：' . mb_basename($fileNameToDelete)));
                } else {
                    header('Location: ' . $currentFile . '?success=' . urlencode('文件已成功删除：' . mb_basename($fileNameToDelete)));
                }
                exit();
            } else {
                // 使用URL参数传递错误消息，并保留当前路径
                $permissionsError = '删除文件失败，请检查服务器权限。';
                $uploadDir = dirname(__FILE__) . '/';
                if (!is_writable($uploadDir)) {
                    $permissionsError = '服务器没有写入权限，无法删除文件。请联系管理员设置正确的权限。';
                }
                if ($currentPath) {
                    header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode($permissionsError));
                } else {
                    header('Location: ' . $currentFile . '?error=' . urlencode($permissionsError));
                }
                exit();
            }
        } else {
            // 使用URL参数传递错误消息，并保留当前路径
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('无效的文件删除请求: ' . $errorReason));
            } else {
                header('Location: ' . $currentFile . '?error=' . urlencode('无效的文件删除请求: ' . $errorReason));
            }
            exit();
        }
    }



    // 辅助函数 - 解压 ZIP 文件 (替代原来的 tar 解压)
    function extractZipFile($zipFilePath, $extractDir)
    {
        // 确保目标目录存在
        if (!file_exists($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipFilePath);
        if ($res !== TRUE) {
            return false;
        }

        $extractedFiles = array();
        // extractTo will create directories as needed
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            return false;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $extractedFiles[] = $zip->getNameIndex($i);
        }

        $zip->close();
        return $extractedFiles;
    }

    // 处理GZ -> 替换为 ZIP 解压请求（保留参数名 'ungzip' 不改变其他交互）
    if (isset($_GET['ungzip'])) {
        // 使用urldecode解码文件名
        $fileNameToUngzip = rawurldecode($_GET['ungzip']);

        // 安全处理文件名
        $fileNameToUngzip = sanitizePath($fileNameToUngzip, true);

        // 检查文件是否以 .zip 结尾
        if (substr($fileNameToUngzip, -4) !== '.zip') {
            header('Location: ' . $currentFile . '?path=' . urlencode(isset($currentPath) ? $currentPath : '') . '&error=' . urlencode('无效的压缩文件格式，仅支持.zip文件'));
            exit();
        }

        // 构建完整路径
        $fileToUngzip = $basePath . '/' . $fileNameToUngzip;
        $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : mb_dirname($fileNameToUngzip);

        // 安全检查
        $validFile = true;
        $errorReason = '';

        if (empty($fileNameToUngzip) || strpos($fileNameToUngzip, '.') === 0) {
            $validFile = false;
            $errorReason = '无效的文件名格式';
        } elseif (!file_exists($fileToUngzip)) {
            $validFile = false;
            $errorReason = '压缩文件不存在';
        } elseif (!is_file($fileToUngzip)) {
            $validFile = false;
            $errorReason = '指定的路径不是文件';
        } elseif (!validatePath($fileToUngzip, $basePath)) {
            $validFile = false;
            $errorReason = '文件不在允许的目录内';
        }

        if ($validFile) {
            // 使用 ZipArchive 解压
            if (!class_exists('ZipArchive')) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('服务器不支持Zip扩展，无法解压。'));
                exit();
            }

            $extractDir = dirname($fileToUngzip);

            $extractedFiles = extractZipFile($fileToUngzip, $extractDir);

            if ($extractedFiles === false) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('解压失败，文件可能已损坏或不是有效的ZIP文件'));
                exit();
            }

            $successMessage = 'ZIP文件已成功解压，共解压 ' . count($extractedFiles) . ' 个文件';
            header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode($successMessage));
            exit();
        } else {
            header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('无效的解压请求: ' . $errorReason));
            exit();
        }
    }

    // 处理文件夹删除请求
    if (isset($_GET['delete_folder'])) {
        // 使用urldecode解码文件夹名
        $folderNameToDelete = rawurldecode($_GET['delete_folder']);

        // 安全处理文件夹名
        $folderNameToDelete = sanitizePath($folderNameToDelete);

        // 构建完整路径
        $folderToDelete = $basePath . '/' . $folderNameToDelete;

        // 安全检查
        $validFolder = true;
        $errorReason = '';

        if (empty($folderNameToDelete)) {
            $validFolder = false;
            $errorReason = '文件夹名称不能为空';
        } elseif (!file_exists($folderToDelete)) {
            $validFolder = false;
            $errorReason = '文件夹不存在: ' . $folderNameToDelete;
        } elseif (!is_dir($folderToDelete)) {
            $validFolder = false;
            $errorReason = '指定的路径不是文件夹: ' . $folderNameToDelete;
        } else {
            // 安全检查确保不能删除根目录或不在允许的目录内
            $realFolderPath = @realpath($folderToDelete);
            $realBasePath = @realpath($basePath);

            if ($realFolderPath === false || $realBasePath === false || $realFolderPath === $realBasePath) {
                $validFolder = false;
                $errorReason = '不允许删除此文件夹';
            } elseif (strpos($realFolderPath, $realBasePath) !== 0) {
                $validFolder = false;
                $errorReason = '文件夹不在允许的目录内: ' . $folderNameToDelete;
            }
        }

        // 获取当前路径或文件夹所在目录
        $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : mb_dirname($folderNameToDelete);
        if ($currentPath === '.') {
            $currentPath = ''; // 当前目录
        }

        if ($validFolder) {
            if (deleteDirectory($folderToDelete)) {
                // 使用URL参数传递成功消息，并保留当前路径
                if ($currentPath) {
                    header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode('文件夹已成功删除：' . mb_basename($folderNameToDelete)));
                } else {
                    header('Location: ' . $currentFile . '?success=' . urlencode('文件夹已成功删除：' . mb_basename($folderNameToDelete)));
                }
                exit();
            } else {
                // 使用URL参数传递错误消息，并保留当前路径
                $permissionsError = '删除文件夹失败，请检查服务器权限。';
                if ($currentPath) {
                    header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode($permissionsError));
                } else {
                    header('Location: ' . $currentFile . '?error=' . urlencode($permissionsError));
                }
                exit();
            }
        } else {
            // 使用URL参数传递错误消息，并保留当前路径
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('无效的文件夹删除请求: ' . $errorReason));
            } else {
                header('Location: ' . $currentFile . '?error=' . urlencode('无效的文件夹删除请求: ' . $errorReason));
            }
            exit();
        }
    }

    // 处理新建文件夹请求
    if (isset($_POST['create_folder']) && !empty($_POST['folder_name'])) {
        $folderName = stripslashes($_POST['folder_name']);
        $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : '';

        // 安全处理文件夹名
        $folderName = sanitizePath($folderName);

        // 确保文件夹名不为空
        if (empty($folderName)) {
            header('Location: ' . $currentFile . '?error=' . urlencode('文件夹名称不能为空'));
            exit();
        }

        // 构建完整的文件夹路径
        $folderPath = $basePath . '/' . ($currentPath ? $currentPath . '/' : '') . $folderName;

        // 检查文件夹是否已存在
        if (file_exists($folderPath)) {
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('文件夹已存在：' . $folderName));
            } else {
                header('Location: ' . $currentFile . '?error=' . urlencode('文件夹已存在：' . $folderName));
            }
            exit();
        }

        // 创建文件夹
        if (mkdir($folderPath, 0755, true)) {
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode('文件夹创建成功：' . $folderName));
            } else {
                header('Location: ' . $currentFile . '?success=' . urlencode('文件夹创建成功：' . $folderName));
            }
            exit();
        } else {
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('创建文件夹失败，请检查服务器权限'));
            } else {
                header('Location: ' . $currentFile . '?error=' . urlencode('创建文件夹失败，请检查服务器权限'));
            }
            exit();
        }
    }

    // 处理新建文件请求
    if (isset($_POST['create_file']) && !empty($_POST['file_name'])) {
        $fileName = stripslashes($_POST['file_name']);
        $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : '';

        // 安全处理文件名
        $fileName = sanitizePath($fileName, true);

        // 确保文件名不为空
        if (empty($fileName)) {
            header('Location: ' . $currentFile . '?error=' . urlencode('文件名不能为空'));
            exit();
        }

        // 构建完整的文件路径
        $filePath = $basePath . '/' . ($currentPath ? $currentPath . '/' : '') . $fileName;

        // 检查文件是否已存在
        if (file_exists($filePath)) {
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('文件已存在：' . $fileName));
            } else {
                header('Location: ' . $currentFile . '?error=' . urlencode('文件已存在：' . $fileName));
            }
            exit();
        }

        // 创建空文件
        if (touch($filePath)) {
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode('文件创建成功：' . $fileName));
            } else {
                header('Location: ' . $currentFile . '?success=' . urlencode('文件创建成功：' . $fileName));
            }
            exit();
        } else {
            if ($currentPath) {
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('创建文件失败，请检查服务器权限'));
            } else {
                header('Location: ' . $currentFile . '?error=' . urlencode('创建文件失败，请检查服务器权限'));
            }
            exit();
        }
    }

    // 处理批量操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
        $batchAction = $_POST['batch_action'];
        $currentPath = isset($_POST['current_path']) ? $_POST['current_path'] : '';

        if ($batchAction === 'delete' && isset($_POST['selected_items'])) {
            $selectedItems = $_POST['selected_items'];
            $deletedCount = 0;
            $failedCount = 0;

            foreach ($selectedItems as $item) {
                $itemType = $item['type'];
                $itemPath = rawurldecode($item['path']);
                $fullPath = $basePath . '/' . $itemPath;

                // 安全检查
                if (validatePath($fullPath, $basePath)) {
                    if ($itemType === 'file') {
                        if (unlink($fullPath)) {
                            $deletedCount++;
                        } else {
                            $failedCount++;
                        }
                    } elseif ($itemType === 'folder') {
                        if (deleteDirectory($fullPath)) {
                            $deletedCount++;
                        } else {
                            $failedCount++;
                        }
                    }
                } else {
                    $failedCount++;
                }
            }

            // 重定向到原页面并显示结果
            $successMessage = "批量删除完成：成功删除 $deletedCount 个项目，失败 $failedCount 个项目";
            header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode($successMessage));
            exit();
        } elseif ($batchAction === 'compress' && isset($_POST['selected_items'])) {
            $selectedItems = $_POST['selected_items'];

            // 创建临时目录
            $tempDir = $basePath . '/temp_' . time() . '_' . rand(1000, 9999);
            mkdir($tempDir, 0755, true);

            try {
                // 复制选中的文件和文件夹到临时目录
                foreach ($selectedItems as $item) {
                    $itemType = $item['type'];
                    $itemPath = rawurldecode($item['path']);
                    $fullPath = $basePath . '/' . $itemPath;
                    $tempPath = $tempDir . '/' . basename($itemPath);

                    // 安全检查
                    if (validatePath($fullPath, $basePath)) {
                        if ($itemType === 'file') {
                            copy($fullPath, $tempPath);
                        } elseif ($itemType === 'folder') {
                            // 递归复制文件夹
                            copyDirectory($fullPath, $tempPath);
                        }
                    }
                }

                // 递归收集所有文件
                $allFiles = array();
                collectAllFiles($tempDir, $allFiles);

                if (empty($allFiles)) {
                    throw new Exception('没有找到可压缩的文件');
                }

                // 使用 ZipArchive 生成 ZIP 压缩包（替代原 tar.gz 实现）
                if (!class_exists('ZipArchive')) {
                    throw new Exception('服务器不支持Zip扩展，无法压缩');
                }

                $zipFileName = 'archive_' . date('YmdHis') . '.zip';
                $savePath = $basePath . '/' . $currentPath . '/' . $zipFileName;

                $zip = new ZipArchive();
                if ($zip->open($savePath, ZipArchive::CREATE) !== true) {
                    throw new Exception('无法创建ZIP文件');
                }

                foreach ($allFiles as $filePath => $relativePath) {
                    // 添加文件到zip，保留相对路径
                    $zip->addFile($filePath, $relativePath);
                }

                $zip->close();

                // 读取zip内容用于下载
                $zipContent = file_get_contents($savePath);

                // 设置下载头
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                header('Content-Length: ' . strlen($zipContent));

                // 输出压缩内容
                echo $zipContent;

                // 清理临时文件
                deleteDirectory($tempDir);
                exit();
            } catch (Exception $e) {
                // 清理临时文件
                deleteDirectory($tempDir);

                // 重定向到原页面并显示错误
                header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&error=' . urlencode('压缩失败：' . $e->getMessage()));
                exit();
            }
        } elseif (($batchAction === 'move' || $batchAction === 'copy') && isset($_POST['selected_items']) && isset($_POST['target_path'])) {
            $selectedItems = $_POST['selected_items'];
            $targetPath = sanitizePath($_POST['target_path']);
            $targetFullPath = $basePath . '/' . $targetPath;

            $successCount = 0;
            $failedCount = 0;

            // 确保目标目录存在
            if (!file_exists($targetFullPath)) {
                mkdir($targetFullPath, 0755, true);
            }

            foreach ($selectedItems as $item) {
                $itemType = $item['type'];
                $itemPath = rawurldecode($item['path']);
                $sourceFullPath = $basePath . '/' . $itemPath;
                $itemName = mb_basename($itemPath);
                $targetItemPath = $targetFullPath . '/' . $itemName;

                // 安全检查
                if (validatePath($sourceFullPath, $basePath) && validatePath($targetItemPath, $basePath)) {
                    // 检查目标是否已存在
                    if (!file_exists($targetItemPath)) {
                        $canProcess = true;

                        // 检查是否将文件夹复制/移动到其子文件夹中，防止无限循环
                        if ($itemType === 'folder') {
                            $realSourcePath = realpath($sourceFullPath);
                            $realTargetParentPath = realpath($targetFullPath);

                            if ($realSourcePath === $realTargetParentPath || strpos($realTargetParentPath . '/', $realSourcePath . '/') === 0) {
                                $canProcess = false;
                                $failedCount++;
                            }
                        }

                        if ($canProcess) {
                            if ($batchAction === 'move') {
                                // 执行移动操作
                                if ($itemType === 'file') {
                                    if (rename($sourceFullPath, $targetItemPath)) {
                                        $successCount++;
                                    } else {
                                        $failedCount++;
                                    }
                                } elseif ($itemType === 'folder') {
                                    if (rename($sourceFullPath, $targetItemPath)) {
                                        $successCount++;
                                    } else {
                                        $failedCount++;
                                    }
                                }
                            } else { // copy
                                // 执行复制操作
                                if ($itemType === 'file') {
                                    if (copy($sourceFullPath, $targetItemPath)) {
                                        $successCount++;
                                    } else {
                                        $failedCount++;
                                    }
                                } elseif ($itemType === 'folder') {
                                    if (copyDirectory($sourceFullPath, $targetItemPath)) {
                                        $successCount++;
                                    } else {
                                        $failedCount++;
                                    }
                                }
                            }
                        }
                    } else {
                        $failedCount++;
                    }
                } else {
                    $failedCount++;
                }
            }

            // 重定向到原页面并显示结果
            $actionName = $batchAction === 'move' ? '移动' : '复制';
            $successMessage = "批量$actionName完成：成功$actionName $successCount 个项目，失败 $failedCount 个项目";
            header('Location: ' . $currentFile . '?path=' . urlencode($currentPath) . '&success=' . urlencode($successMessage));
            exit();
        }
    }

    // 处理文件上传
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 批量文件上传处理
        if (isset($_FILES['files']) && $_FILES['files']['error'][0] !== UPLOAD_ERR_NO_FILE) {
            $totalFiles = count($_FILES['files']['name']);
            $uploadedCount = 0;
            $failedCount = 0;

            for ($i = 0; $i < $totalFiles; $i++) {
                if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                    $fileTmpName = $_FILES['files']['tmp_name'][$i];
                    $originalFileName = urldecode($_FILES['files']['name'][$i]);
                    // 安全处理文件名
                    $fileName = sanitizePath($originalFileName, true);

                    if (empty($fileName) || $fileName === '.') {
                        $fileName = 'upload_' . time() . '_' . $i . '.tmp';
                    }

                    $fileSize = $_FILES['files']['size'][$i];

                    // 检查文件大小
                    if ($fileSize > $CONFIG['MAX_FILE_SIZE']) {
                        $failedCount++;
                        continue;
                    }

                    // 获取目标文件夹（如果有）
                    $targetDir = $basePath . '/';
                    if (isset($_POST['target_folder']) && !empty($_POST['target_folder'])) {
                        // 安全处理文件夹名
                        $targetFolder = sanitizePath($_POST['target_folder']);
                        $targetDir = $basePath . '/' . $targetFolder . '/';
                        // 确保目标文件夹存在
                        if (!file_exists($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }
                    }

                    $targetFile = $targetDir . $fileName;

                    // 如果文件已存在，添加时间戳以避免覆盖
                    if (file_exists($targetFile)) {
                        $filenameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
                        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                        $fileName = $filenameWithoutExt . '_' . time() . '.' . $fileExt;
                        $targetFile = $targetDir . $fileName;
                    }

                    // 移除mb_convert_encoding调用

                    // 移动上传的文件
                    if (move_uploaded_file($fileTmpName, $targetFile)) {
                        $uploadedCount++;
                    } else {
                        $failedCount++;
                    }
                }
            }

            // 使用PRG模式
            header('Location: ' . $currentFile . '?success=' . urlencode("批量上传完成：成功 $uploadedCount 个文件，失败 $failedCount 个文件"));
            exit();
        }
        // 处理文件夹上传（通过AJAX发送的文件）
        else if (isset($_FILES['folder_file']) && isset($_POST['relative_path'])) {
            $fileTmpName = $_FILES['folder_file']['tmp_name'];
            $relativePath = urldecode($_POST['relative_path']);
            $originalFileName = urldecode($_FILES['folder_file']['name']);

            // 获取用户当前所在路径
            $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : '';
            $safeCurrentPath = sanitizePath($currentPath);

            // 安全处理路径
            $safeRelativePath = sanitizePath($relativePath, true);

            // 构建完整的目标目录：basePath + 当前路径 + 相对路径的目录部分
            if (empty($safeCurrentPath)) {
                $targetDir = $basePath . '/' . dirname($safeRelativePath) . '/';
            } else {
                $targetDir = $basePath . '/' . $safeCurrentPath . '/' . dirname($safeRelativePath) . '/';
            }
            $fileName = mb_basename($safeRelativePath);

            // 确保目标目录存在
            if (!file_exists($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $targetFile = $targetDir . $fileName;

            // 移动上传的文件
            if (move_uploaded_file($fileTmpName, $targetFile)) {
                echo json_encode(array('success' => true, 'file' => $relativePath));
            } else {
                echo json_encode(array('success' => false, 'error' => '文件上传失败'));
            }
            exit();
        }
    }

    // 排序函数
    function sortItems($a, $b)
    {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'folder' ? -1 : 1;
        }
        return strcmp($a['name'], $b['name']);
    }

    // 获取目录和文件列表
    function getFilesAndFolders($path = '')
    {
        global $currentFile; // 访问全局变量
        $items = array();
        $currentPath = empty($path) ? dirname(__FILE__) : dirname(__FILE__) . '/' . $path;
        // 移除mb_convert_encoding调用

        // 检查路径是否存在且为目录
        if (!file_exists($currentPath) || !is_dir($currentPath)) {
            return $items;
        }

        // 获取文件夹列表
        if ($handle = opendir($currentPath)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != '.' && $entry != '..' && $entry != basename(__FILE__)) {
                    // 移除mb_convert_encoding调用
                    $fullPath = $currentPath . '/' . $entry;
                    $relativePath = empty($path) ? $entry : $path . '/' . $entry;

                    if (is_dir($fullPath)) {
                        array_push($items, array(
                            'type' => 'folder',
                            'name' => $entry,
                            'path' => $relativePath,
                            'date' => filemtime($fullPath),
                            'count' => count(scandir($fullPath)) - 2 // 减去.和..
                        ));
                    } else {
                        array_push($items, array(
                            'type' => 'file',
                            'name' => $entry,
                            'path' => $relativePath,
                            'size' => filesize($fullPath),
                            'date' => filemtime($fullPath),
                            'url' => './' . (empty($path) ? '' : rawurlencode($path) . '/') . rawurlencode($entry)
                        ));
                    }
                }
            }
            closedir($handle);

            // 排序：文件夹在前，然后按名称排序
            usort($items, 'sortItems');
        }

        return $items;
    }

    // 格式化文件大小
    function formatFileSize($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }

    // 格式化日期时间
    function formatDateTime($timestamp)
    {
        date_default_timezone_set('Asia/Shanghai');
        return date('Y-m-d H:i:s', $timestamp);
    }

    // 获取当前路径（从URL参数）
    $currentPath = isset($_GET['path']) ? rawurldecode($_GET['path']) : '';
    // 安全处理路径
    $currentPath = sanitizePath($currentPath);

    // 获取文件和文件夹列表
    $items = getFilesAndFolders($currentPath);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件管理器</title>
    <!-- 引入FontAwesome CSS -->
    <link rel="stylesheet" href="https://registry.npmmirror.com/@fortawesome/fontawesome-free/7.0.1/files/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            background-color: #121212;
            /* 深色背景 */
            margin: 0;
            padding: 20px;
            color: #e0e0e0;
            /* 浅色文字 */
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #1e1e1e;
            /* 深色容器背景 */
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        h1 {
            color: #ffffff;
            /* 白色标题 */
            margin-bottom: 30px;
            text-align: center;
        }

        h2 {
            color: #e0e0e0;
            /* 浅色副标题 */
            margin-top: 40px;
            margin-bottom: 20px;
            border-bottom: 2px solid #333333;
            /* 深色边框 */
            padding-bottom: 10px;
        }

        .upload-form,
        .password-form,
        .create-folder-form {
            background-color: #252525;
            /* 深色表单背景 */
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #333333;
        }

        .create-folder-form {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .create-folder-form form {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .inline-label {
            display: inline-block;
            margin-bottom: 0;
            font-weight: 600;
            color: #e0e0e0;
            white-space: nowrap;
        }

        .inline-input {
            display: inline-block;
            margin-bottom: 0;
            width: 150px;
            padding: 8px 12px;
            border: 1px solid #444444;
            border-radius: 4px;
            background-color: #333333;
            color: #e0e0e0;
            height: 36px;
            box-sizing: border-box;
        }

        .inline-button {
            background-color: #1a73e8;
            /* 蓝色按钮 */
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
            height: 36px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* 保留原始样式 */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #e0e0e0;
        }

        input[type="file"],
        input[type="password"],
        select {
            display: block;
            margin-bottom: 15px;
            width: 100%;
            padding: 8px;
            border: 1px solid #444444;
            border-radius: 4px;
            background-color: #333333;
            color: #e0e0e0;
        }

        button {
            background-color: #1a73e8;
            /* 蓝色按钮 */
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }

        button:hover {
            background-color: #1557b0;
        }

        .error {
            color: #ff6b6b;
            background-color: rgba(255, 107, 107, 0.1);
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .success {
            color: #4ade80;
            background-color: rgba(74, 222, 128, 0.1);
            border-color: rgba(74, 222, 128, 0.3);
        }

        .notice-box {
            padding: 8px;
            margin: 8px -8px;
            border-radius: 4px;
        }

        /* 添加图标间距样式 */
        .mr-1 {
            margin-right: 5px;
        }

        .mr-2 {
            margin-right: 10px;
        }

        .file-list {
            width: 100%;
            border-collapse: collapse;
        }

        .file-list th,
        .file-list td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #333333;
        }

        .file-list th {
            background-color: #252525;
            font-weight: 600;
            color: #e0e0e0;
        }

        .file-list tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .file-link {
            color: #8ab4f8;
            text-decoration: none;
        }

        .file-link:hover {
            text-decoration: underline;
        }

        .folder-link {
            color: #f59e0b;
            text-decoration: none;
        }

        .folder-link:hover {
            text-decoration: underline;
        }

        .allowed-types {
            font-size: 14px;
            color: #b0b0b0;
            margin-top: 5px;
        }

        .logout {
            text-align: right;
            margin-bottom: 20px;
        }

        .logout a {
            color: #ff6b6b;
            text-decoration: none;
        }

        .logout a:hover {
            text-decoration: underline;
        }

        .delete-link {
            color: #ff6b6b;
            text-decoration: none;
            margin-left: 10px;
        }

        .delete-link:hover {
            text-decoration: underline;
        }

        /* 编辑按钮样式 */
        .edit-link {
            color: #1a73e8;
            text-decoration: none;
            margin-left: 10px;
        }

        .edit-link:hover {
            text-decoration: underline;
        }

        /* 重命名按钮样式 */
        .rename-link {
            color: #8ab4f8;
            text-decoration: none;
            margin-left: 10px;
        }

        .rename-link:hover {
            text-decoration: underline;
        }

        /* 解压按钮样式 */
        .ungzip-link {
            color: #8ab4f8;
            text-decoration: none;
            margin-left: 10px;
        }

        .ungzip-link:hover {
            text-decoration: underline;
        }

        /* 批量上传按钮 */
        .batch-upload-btn {
            background-color: #34d399;
            margin-left: 10px;
        }

        .batch-upload-btn:hover {
            background-color: #10b981;
        }

        /* 全页面拖放高亮效果 */
        body.drag-over {
            background-color: rgba(26, 115, 232, 0.1);
            transition: background-color 0.3s ease;
        }

        /* 路径导航 */
        .path-nav {
            margin-bottom: 20px;
            font-size: 16px;
        }

        .path-nav a {
            color: #8ab4f8;
            text-decoration: none;
        }

        .path-nav a:hover {
            text-decoration: underline;
        }

        .path-separator {
            color: #666;
            margin: 0 5px;
        }

        /* 创建文件夹表单样式 */
        .create-folder-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .create-folder-form .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        /* 上传进度指示器 */
        .upload-progress {
            margin-top: 15px;
            display: none;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #333;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background-color: #1a73e8;
            width: 0;
            transition: width 0.3s ease;
        }

        .upload-stats {
            margin-top: 5px;
            font-size: 14px;
            color: #b0b0b0;
        }
    </style>
</head>

<body>
    <!-- 页面加载蒙板 -->
    <div id="loadingMask" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(18, 18, 18, 0.8); display: flex; align-items: center; justify-content: center; z-index: 9999; flex-direction: column;">
        <div style="font-size: 48px; color: #1a73e8; margin-bottom: 20px;">
            <i class="fas fa-spinner fa-spin"></i>
        </div>
        <div style="font-size: 18px; color: #e0e0e0;">页面加载中，请稍候...</div>
    </div>

    <div class="container">
        <h1><i class="fas fa-cloud-upload-alt mr-2"></i>文件管理器</h1>

        <?php if ($error): ?>
            <div class="error notice-box"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success notice-box"><i class="fas fa-check-circle mr-2"></i><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if ($isAuthenticated): ?>
            <!-- 已登录用户可以看到的内容 -->
            <div class="logout">
                <a href="#" onclick="openAdminModal()"><i class="fas fa-user mr-1"></i>管理员信息</a>
            </div>



            <!-- 管理员信息浮窗 -->
            <div id="adminModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); z-index: 1000;">
                <div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background-color: #252525; border: 1px solid #444; border-radius: 8px; padding: 30px; width: 500px; max-width: 90%; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);">
                    <!-- 浮窗头部 -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: #e0e0e0; margin: 0;"><i class="fas fa-user mr-2"></i>管理员信息</h2>
                        <button onclick="closeAdminModal()" style="background: none; border: none; color: #e0e0e0; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
                    </div>

                    <!-- 浮窗内容 -->
                    <div id="adminModalContent">
                        <!-- 修改密码表单 -->
                        <div class="upload-form">
                            <h3 style="color: #e0e0e0; margin-top: 0; margin-bottom: 20px;">修改密码</h3>
                            <form method="post" action="<?php echo $currentFile; ?><?php echo isset($_GET['path']) ? '?path=' . urlencode($_GET['path']) : ''; ?>">
                                <div class="form-group" style="display: flex; flex-direction: column; gap: 15px;">
                                    <div style="flex: 1; min-width: 200px;">
                                        <label for="current_password" class="inline-label"><i class="fas fa-key mr-1"></i>当前密码</label>
                                        <input type="password" id="current_password" name="current_password" required class="inline-input">
                                    </div>
                                    <div style="flex: 1; min-width: 200px;">
                                        <label for="new_password" class="inline-label"><i class="fas fa-lock mr-1"></i>新密码</label>
                                        <input type="password" id="new_password" name="new_password" required class="inline-input">
                                    </div>
                                    <div style="flex: 1; min-width: 200px;">
                                        <label for="confirm_password" class="inline-label"><i class="fas fa-lock mr-1"></i>确认新密码</label>
                                        <input type="password" id="confirm_password" name="confirm_password" required class="inline-input">
                                    </div>
                                    <div style="display: flex; align-items: flex-end; margin-bottom: 0;">
                                        <button type="submit" name="change_password" class="inline-button"><i class="fas fa-save mr-1"></i>保存</button>
                                        <button type="button" onclick="closeAdminModal()" class="inline-button" style="background-color: #666; margin-left: 10px;"><i class="fas fa-times mr-1"></i>取消</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- 退出登录按钮 -->
                        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #444;">
                            <a href="<?php echo $currentFile; ?>?logout=true" onclick="return confirm('确定要  出登录吗？');" style="background-color: #ff6b6b; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;">
                                <i class="fas fa-sign-out-alt mr-1"></i>退出登录
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 上传进度指示器 -->
            <div id="uploadProgress" class="upload-progress">
                <div class="progress-bar">
                    <div id="progressFill" class="progress-fill"></div>
                </div>
                <div id="uploadStats" class="upload-stats">准备上传...</div>
            </div>

            <!-- 隐藏的文件选择输入 -->
            <input type="file" id="fileInput" multiple style="display: none;">
            <!-- 隐藏的文件夹选择输入 -->
            <input type="file" id="folderInput" webkitdirectory directory style="display: none;">

            <h2>文件和文件夹</h2>

            <!-- 路径导航 -->
            <div class="path-nav">
                <a href="<?php echo $currentFile; ?>"><i class="fas fa-home mr-1"></i>根目录</a>
                <?php if (!empty($currentPath)): ?>
                    <?php
                    $pathParts = explode('/', $currentPath);
                    $currentBuild = '';
                    foreach ($pathParts as $part):
                        $currentBuild .= '/' . $part;
                    ?>
                        <span class="path-separator">/</span>
                        <a href="<?php echo $currentFile; ?>?path=<?php echo urlencode(substr($currentBuild, 1)); ?>">
                            <?php echo htmlspecialchars($part); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 操作栏容器 - 批量操作和新建功能在同一行 -->
            <div class="action-bar" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 15px; width: 100%; flex-wrap: wrap;">
                <!-- 批量操作容器 -->
                <?php if (count($items) > 0): ?>
                    <div id="batchActions" class="batch-actions" style="display: none; align-items: center; gap: 8px; flex-shrink: 0;">
                        <button id="copySelected" class="batch-copy-btn" style="background-color: #60a5fa; padding: 8px 12px; font-size: 14px; height: 36px; border: none; border-radius: 4px; cursor: pointer; color: white; min-width: 80px;">
                            <i class="fas fa-copy mr-1"></i>复制
                        </button>
                        <button id="moveSelected" class="batch-move-btn" style="background-color: #f472b6; padding: 8px 12px; font-size: 14px; height: 36px; border: none; border-radius: 4px; cursor: pointer; color: white; min-width: 80px;">
                            <i class="fas fa-cut mr-1"></i>移动
                        </button>
                        <button id="compressSelected" class="batch-compress-btn" style="background-color: #4caf50; padding: 8px 12px; font-size: 14px; height: 36px; border: none; border-radius: 4px; cursor: pointer; color: white; min-width: 80px;">
                            <i class="fas fa-file-archive mr-1"></i>压缩
                        </button>
                        <button id="deleteSelected" class="batch-delete-btn" style="background-color: #ff6b6b; padding: 8px 12px; font-size: 14px; height: 36px; border: none; border-radius: 4px; cursor: pointer; color: white; min-width: 80px;">
                            <i class="fas fa-trash-alt mr-1"></i>删除
                        </button>
                        <span id="selectedCount" style="font-size: 14px; color: #e0e0e0;">已选择 0 个项目</span>
                    </div>
                <?php endif; ?>

                <!-- 新建功能容器 -->
                <div class="create-container" style="display: flex; align-items: center; gap: 15px; flex-shrink: 0;">
                    <!-- 上传文件按钮 -->
                    <button type="button" onclick="document.getElementById('fileInput').click()" class="inline-button" style="padding: 8px 16px; font-size: 14px; background-color: #34d399; border: none; border-radius: 4px; cursor: pointer; color: white; display: flex; align-items: center; gap: 5px;">
                        <i class="fas fa-file-upload mr-1"></i>上传文件
                    </button>

                    <!-- 上传文件夹按钮 -->
                    <button type="button" onclick="document.getElementById('folderInput').click()" class="inline-button" style="padding: 8px 16px; font-size: 14px; background-color: #f59e0b; border: none; border-radius: 4px; cursor: pointer; color: white; display: flex; align-items: center; gap: 5px;">
                        <i class="fas fa-arrow-up-from-bracket mr-1"></i>上传文件夹
                    </button>
                    <!-- 新建文件夹表单 -->
                    <form method="post" action="<?php echo $currentFile; ?><?php echo isset($_GET['path']) ? '?path=' . urlencode($_GET['path']) : ''; ?>" style="display: flex; align-items: center; gap: 8px;">
                        <label for="folder_name" class="inline-label" style="font-size: 14px; color: #e0e0e0;"><i class="fas fa-folder-plus mr-1"></i>新建文件夹</label>
                        <input type="text" id="folder_name" name="folder_name" placeholder="文件夹名称" required class="inline-input" style="padding: 8px 12px; border: 1px solid #444; border-radius: 4px; background-color: #333; color: #e0e0e0;">
                        <button type="submit" name="create_folder" class="inline-button" style="padding: 8px 16px; font-size: 14px; background-color: #1a73e8; border: none; border-radius: 4px; cursor: pointer; color: white;"><i class="fas fa-plus mr-1"></i>创建</button>
                    </form>

                    <!-- 新建文件表单 -->
                    <form method="post" action="<?php echo $currentFile; ?><?php echo isset($_GET['path']) ? '?path=' . urlencode($_GET['path']) : ''; ?>" style="display: flex; align-items: center; gap: 8px;">
                        <label for="file_name" class="inline-label" style="font-size: 14px; color: #e0e0e0;"><i class="fas fa-file-alt mr-1"></i>新建文件</label>
                        <input type="text" id="file_name" name="file_name" placeholder="文件名" required class="inline-input" style="padding: 8px 12px; border: 1px solid #444; border-radius: 4px; background-color: #333; color: #e0e0e0;">
                        <button type="submit" name="create_file" class="inline-button" style="padding: 8px 16px; font-size: 14px; background-color: #1a73e8; border: none; border-radius: 4px; cursor: pointer; color: white;"><i class="fas fa-plus mr-1"></i>创建</button>
                    </form>

                </div>
            </div>

            <?php if (count($items) > 0): ?>
                <table class="file-list">
                    <thead>
                        <tr>
                            <th style="width: 30px;">
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            </th>
                            <th>名称</th>
                            <th>大小</th>
                            <th>修改时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="item-checkbox" data-type="<?php echo $item['type']; ?>" data-path="<?php echo urlencode($item['path']); ?>">
                                </td>
                                <td>
                                    <?php if ($item['type'] === 'folder'): ?>
                                        <a href="<?php echo $currentFile; ?>?path=<?php echo urlencode($item['path']); ?>" class="folder-link">
                                            <i class="fas fa-folder mr-1"></i><?php echo htmlspecialchars($item['name']); ?>
                                        </a>
                                        <span class="allowed-types">(<?php echo $item['count']; ?> 个项目)</span>
                                    <?php else: ?>
                                        <a href="<?php echo $item['url']; ?>" class="file-link" target="_blank">
                                            <i class="fas fa-file mr-1"></i><?php echo htmlspecialchars($item['name']); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['type'] === 'folder'): ?>
                                        -
                                    <?php else: ?>
                                        <?php echo formatFileSize($item['size']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDateTime($item['date']); ?></td>
                                <td>
                                    <?php if ($item['type'] === 'folder'): ?>
                                        <!-- 重命名按钮 -->
                                        <a href="<?php echo $currentFile; ?>?rename=<?php echo urlencode($item['path']); ?>&path=<?php echo urlencode($currentPath); ?>&type=folder" class="rename-link">
                                            <i class="fas fa-edit mr-1"></i>重命名
                                        </a>
                                        <!-- 删除按钮 -->
                                        <a href="<?php echo $currentFile; ?>?delete_folder=<?php echo urlencode($item['path']); ?>&path=<?php echo urlencode($currentPath); ?>" class="delete-link" onclick="return confirm('确定要删除文件夹 &quot;<?php echo htmlspecialchars($item['name']); ?>&quot; 及其所有内容吗？删除后无法恢复。');">
                                            <i class="fas fa-trash-alt mr-1"></i>删除
                                        </a>
                                    <?php else: ?>
                                        <!-- 编辑按钮 -->
                                        <?php if (isTextFile($item['name'])): ?>
                                            <a href="<?php echo $currentFile; ?>?edit=<?php echo urlencode($item['path']); ?>&path=<?php echo urlencode($currentPath); ?>" class="edit-link">
                                                <i class="fas fa-edit mr-1"></i>编辑
                                            </a>
                                        <?php endif; ?>

                                        <!-- 重命名按钮 -->
                                        <a href="<?php echo $currentFile; ?>?rename=<?php echo urlencode($item['path']); ?>&path=<?php echo urlencode($currentPath); ?>&type=file" class="rename-link">
                                            <i class="fas fa-edit mr-1"></i>重命名
                                        </a>
                                        <!-- 解压按钮 (改为支持 .zip) -->
                                        <?php if (substr($item['name'], -4) === '.zip'): ?>
                                            <a href="<?php echo $currentFile; ?>?ungzip=<?php echo urlencode($item['path']); ?>&path=<?php echo urlencode($currentPath); ?>" class="ungzip-link" onclick="return confirm('确定要解压文件 &quot;<?php echo htmlspecialchars($item['name']); ?>&quot; 吗？');">
                                                <i class="fas fa-expand-alt mr-1"></i>解压
                                            </a>
                                        <?php endif; ?>

                                        <!-- 删除按钮 -->
                                        <a href="<?php echo $currentFile; ?>?delete=<?php echo urlencode($item['path']); ?>&path=<?php echo urlencode($currentPath); ?>" class="delete-link" onclick="return confirm('确定要删除文件 &quot;<?php echo htmlspecialchars($item['name']); ?>&quot; 吗？删除后无法恢复。');">
                                            <i class="fas fa-trash-alt mr-1"></i>删除
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>当前目录为空</p>
            <?php endif; ?>
        <?php else:
            // 未登录用户看到的密码表单 
        ?>
            <div class="password-form">
                <h2><i class="fas fa-lock mr-2"></i>请输入访问密码</h2>
                <form method="post">
                    <div class="form-group">
                        <label for="password"><i class="fas fa-key mr-1"></i>密码</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <button type="submit" name="password_submit"><i class="fas fa-sign-in-alt mr-1"></i>登录</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // 页面加载完成后隐藏蒙板
        window.addEventListener('load', function() {
            const loadingMask = document.getElementById('loadingMask');
            if (loadingMask) {
                // 添加淡出效果
                loadingMask.style.transition = 'opacity 0.5s ease';
                loadingMask.style.opacity = '0';

                // 延迟后完全隐藏
                setTimeout(function() {
                    loadingMask.style.display = 'none';
                }, 500);
            }
        });

        // 打开管理员信息浮窗
        function openAdminModal() {
            document.getElementById('adminModal').style.display = 'block';
        }

        // 关闭管理员信息浮窗
        function closeAdminModal() {
            document.getElementById('adminModal').style.display = 'none';
        }

        // 点击浮窗外围关闭浮窗
        window.onclick = function(event) {
            const modal = document.getElementById('adminModal');
            if (event.target === modal) {
                closeAdminModal();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // 批量操作相关元素
            const selectAllCheckbox = document.getElementById('selectAll');
            const itemCheckboxes = document.querySelectorAll('.item-checkbox');
            const batchActions = document.getElementById('batchActions');
            const deleteSelectedBtn = document.getElementById('deleteSelected');
            const compressSelectedBtn = document.getElementById('compressSelected');
            const moveSelectedBtn = document.getElementById('moveSelected');
            const copySelectedBtn = document.getElementById('copySelected');
            const selectedCountSpan = document.getElementById('selectedCount');

            // 拖放上传相关元素
            const uploadProgress = document.getElementById('uploadProgress');
            const progressFill = document.getElementById('progressFill');
            const uploadStats = document.getElementById('uploadStats');
            const fileInput = document.getElementById('fileInput');
            const folderInput = document.getElementById('folderInput');

            // 初始化批量操作相关事件
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', toggleSelectAll);
            }

            if (itemCheckboxes.length > 0) {
                itemCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', updateSelectedCount);
                });
            }

            if (deleteSelectedBtn) {
                deleteSelectedBtn.addEventListener('click', handleBatchDelete);
            }

            if (compressSelectedBtn) {
                compressSelectedBtn.addEventListener('click', handleBatchCompress);
            }

            if (moveSelectedBtn) {
                moveSelectedBtn.addEventListener('click', function() {
                    handleBatchMoveOrCopy('move');
                });
            }

            if (copySelectedBtn) {
                copySelectedBtn.addEventListener('click', function() {
                    handleBatchMoveOrCopy('copy');
                });
            }

            // 更新选中项目计数
            function updateSelectedCount() {
                const selectedCount = document.querySelectorAll('.item-checkbox:checked').length;
                selectedCountSpan.textContent = `已选择 ${selectedCount} 个项目`;

                if (selectedCount > 0) {
                    batchActions.style.display = 'block';
                    // 隐藏新建容器
                    const createContainer = document.querySelector('.create-container');
                    if (createContainer) {
                        createContainer.style.display = 'none';
                    }
                } else {
                    batchActions.style.display = 'none';
                    selectAllCheckbox.checked = false;
                    // 显示新建容器
                    const createContainer = document.querySelector('.create-container');
                    if (createContainer) {
                        createContainer.style.display = 'flex';
                    }
                }
            }

            // 全选/取消全选
            function toggleSelectAll() {
                const isChecked = selectAllCheckbox.checked;
                itemCheckboxes.forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
                updateSelectedCount();
            }

            // 处理批量删除
            function handleBatchDelete() {
                const selectedItems = document.querySelectorAll('.item-checkbox:checked');
                const selectedCount = selectedItems.length;

                if (selectedCount === 0) {
                    alert('请先选择要删除的项目');
                    return;
                }

                if (confirm(`确定要删除选中的 ${selectedCount} 个项目吗？删除后无法恢复。`)) {
                    // 收集选中项目的路径和类型
                    const selectedPaths = new Array();
                    selectedItems.forEach(checkbox => {
                        selectedPaths.push({
                            type: checkbox.dataset.type,
                            path: checkbox.dataset.path
                        });
                    });

                    // 创建表单并提交
                    const form = document.createElement('form');
                    form.method = 'post';
                    form.action = '<?php echo $currentFile; ?>';

                    // 添加路径数据
                    selectedPaths.forEach((item, index) => {
                        const typeInput = document.createElement('input');
                        typeInput.type = 'hidden';
                        typeInput.name = `selected_items[${index}][type]`;
                        typeInput.value = item.type;
                        form.appendChild(typeInput);

                        const pathInput = document.createElement('input');
                        pathInput.type = 'hidden';
                        pathInput.name = `selected_items[${index}][path]`;
                        pathInput.value = item.path;
                        form.appendChild(pathInput);
                    });

                    // 添加当前路径
                    const currentPath = new URLSearchParams(window.location.search).get('path') || '';
                    const currentPathInput = document.createElement('input');
                    currentPathInput.type = 'hidden';
                    currentPathInput.name = 'current_path';
                    currentPathInput.value = currentPath;
                    form.appendChild(currentPathInput);

                    // 添加操作类型
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'batch_action';
                    actionInput.value = 'delete';
                    form.appendChild(actionInput);

                    // 提交表单
                    document.body.appendChild(form);
                    form.submit();
                }
            }

            // 处理批量压缩
            function handleBatchCompress() {
                const selectedItems = document.querySelectorAll('.item-checkbox:checked');
                const selectedCount = selectedItems.length;

                if (selectedCount === 0) {
                    alert('请先选择要压缩的项目');
                    return;
                }

                if (confirm(`确定要将选中的 ${selectedCount} 个项目（支持文件和文件夹）批量压缩为GZ文件并下载吗？`)) {
                    // 收集选中项目的路径和类型
                    const selectedPaths = new Array();
                    selectedItems.forEach(checkbox => {
                        selectedPaths.push({
                            type: checkbox.dataset.type,
                            path: checkbox.dataset.path
                        });
                    });

                    // 创建表单并提交
                    const form = document.createElement('form');
                    form.method = 'post';
                    form.action = '<?php echo $currentFile; ?>';
                    form.target = '_blank'; // 在新窗口打开，方便下载

                    // 添加路径数据
                    selectedPaths.forEach((item, index) => {
                        const typeInput = document.createElement('input');
                        typeInput.type = 'hidden';
                        typeInput.name = `selected_items[${index}][type]`;
                        typeInput.value = item.type;
                        form.appendChild(typeInput);

                        const pathInput = document.createElement('input');
                        pathInput.type = 'hidden';
                        pathInput.name = `selected_items[${index}][path]`;
                        pathInput.value = item.path;
                        form.appendChild(pathInput);
                    });

                    // 添加当前_path
                    const currentPath = new URLSearchParams(window.location.search).get('path') || '';
                    const currentPathInput = document.createElement('input');
                    currentPathInput.type = 'hidden';
                    currentPathInput.name = 'current_path';
                    currentPathInput.value = currentPath;
                    form.appendChild(currentPathInput);

                    // 添加操作类型
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'batch_action';
                    actionInput.value = 'compress';
                    form.appendChild(actionInput);

                    // 提交表单
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }
            }

            // 处理批量移动或复制
            function handleBatchMoveOrCopy(actionType) {
                const selectedItems = document.querySelectorAll('.item-checkbox:checked');
                const selectedCount = selectedItems.length;

                if (selectedCount === 0) {
                    alert(`请先选择要${actionType === 'move' ? '移动' : '复制'}的项目`);
                    return;
                }

                // 创建加载对话框
                const loadingDialog = document.createElement('div');
                loadingDialog.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background-color: #252525;
                    border: 1px solid #444;
                    border-radius: 8px;
                    padding: 20px;
                    z-index: 1000;
                    width: 250px;
                    text-align: center;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
                `;

                const loadingMessage = document.createElement('p');
                loadingMessage.textContent = '正在加载文件夹列表...';
                loadingMessage.style.cssText = `
                    color: #e0e0e0;
                    margin: 0;
                `;
                loadingDialog.appendChild(loadingMessage);

                const overlay = document.createElement('div');
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0, 0, 0, 0.7);
                    z-index: 999;
                `;

                document.body.appendChild(overlay);
                document.body.appendChild(loadingDialog);

                // 从服务器获取所有文件夹
                fetch('<?php echo $currentFile; ?>?get_folders=1')
                    .then(response => response.json())
                    .then(folders => {
                        // 移除加载对话框
                        document.body.removeChild(loadingDialog);

                        // 创建目标文件夹选择对话框
                        const dialog = document.createElement('div');
                        dialog.style.cssText = `
                            position: fixed;
                            top: 50%;
                            left: 50%;
                            transform: translate(-50%, -50%);
                            background-color: #252525;
                            border: 1px solid #444;
                            border-radius: 8px;
                            padding: 20px;
                            z-index: 1000;
                            width: 400px;
                            max-width: 90%;
                            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
                        `;

                        const title = document.createElement('h3');
                        title.textContent = `${actionType === 'move' ? '批量移动' : '批量复制'}项目`;
                        title.style.cssText = `
                            color: #e0e0e0;
                            margin-top: 0;
                            margin-bottom: 15px;
                            font-size: 18px;
                        `;
                        dialog.appendChild(title);

                        const message = document.createElement('p');
                        message.textContent = `请选择目标文件夹，将选中的 ${selectedCount} 个项目${actionType === 'move' ? '移动' : '复制'}到该文件夹：`;
                        message.style.cssText = `
                            color: #e0e0e0;
                            margin-bottom: 15px;
                        `;
                        dialog.appendChild(message);

                        const selectContainer = document.createElement('div');
                        selectContainer.style.cssText = `
                            margin-bottom: 20px;
                        `;

                        const selectLabel = document.createElement('label');
                        selectLabel.textContent = '目标文件夹：';
                        selectLabel.style.cssText = `
                            display: block;
                            color: #e0e0e0;
                            margin-bottom: 8px;
                            font-weight: 600;
                        `;
                        selectContainer.appendChild(selectLabel);

                        const select = document.createElement('select');
                        select.id = 'batchTargetPath';
                        select.style.cssText = `
                            width: 100%;
                            padding: 10px;
                            border: 1px solid #444;
                            border-radius: 4px;
                            background-color: #333;
                            color: #e0e0e0;
                            font-size: 16px;
                        `;

                        // 添加选项
                        const rootOption = document.createElement('option');
                        rootOption.value = '';
                        rootOption.textContent = '根目录';
                        select.appendChild(rootOption);

                        folders.forEach(folder => {
                            const option = document.createElement('option');
                            option.value = folder;
                            option.textContent = folder;
                            select.appendChild(option);
                        });

                        selectContainer.appendChild(select);
                        dialog.appendChild(selectContainer);

                        const buttonContainer = document.createElement('div');
                        buttonContainer.style.cssText = `
                            display: flex;
                            gap: 10px;
                            justify-content: flex-end;
                        `;

                        const cancelBtn = document.createElement('button');
                        cancelBtn.textContent = '取消';
                        cancelBtn.style.cssText = `
                            background-color: #666;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 4px;
                            cursor: pointer;
                            font-size: 16px;
                        `;
                        cancelBtn.addEventListener('click', () => {
                            document.body.removeChild(dialog);
                            document.body.removeChild(overlay);
                        });
                        buttonContainer.appendChild(cancelBtn);

                        const confirmBtn = document.createElement('button');
                        confirmBtn.textContent = actionType === 'move' ? '移动' : '复制';
                        confirmBtn.style.cssText = `
                            background-color: #1a73e8;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 4px;
                            cursor: pointer;
                            font-size: 16px;
                        `;
                        confirmBtn.addEventListener('click', () => {
                            const targetPath = select.value;

                            // 创建表单并提交
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.action = '<?php echo $currentFile; ?>';

                            // 收集选中项目的路径和类型
                            const selectedPaths = new Array();
                            selectedItems.forEach(checkbox => {
                                selectedPaths.push({
                                    type: checkbox.dataset.type,
                                    path: checkbox.dataset.path
                                });
                            });

                            // 添加路径数据
                            selectedPaths.forEach((item, index) => {
                                const typeInput = document.createElement('input');
                                typeInput.type = 'hidden';
                                typeInput.name = `selected_items[${index}][type]`;
                                typeInput.value = item.type;
                                form.appendChild(typeInput);

                                const pathInput = document.createElement('input');
                                pathInput.type = 'hidden';
                                pathInput.name = `selected_items[${index}][path]`;
                                pathInput.value = item.path;
                                form.appendChild(pathInput);
                            });

                            // 添加当前路径
                            const currentPath = new URLSearchParams(window.location.search).get('path') || '';
                            const currentPathInput = document.createElement('input');
                            currentPathInput.type = 'hidden';
                            currentPathInput.name = 'current_path';
                            currentPathInput.value = currentPath;
                            form.appendChild(currentPathInput);

                            // 添加目标路径
                            const targetPathInput = document.createElement('input');
                            targetPathInput.type = 'hidden';
                            targetPathInput.name = 'target_path';
                            targetPathInput.value = targetPath;
                            form.appendChild(targetPathInput);

                            // 添加操作类型
                            const actionInput = document.createElement('input');
                            actionInput.type = 'hidden';
                            actionInput.name = 'batch_action';
                            actionInput.value = actionType;
                            form.appendChild(actionInput);

                            // 提交表单
                            document.body.appendChild(form);
                            form.submit();
                            document.body.removeChild(form);

                            // 移除对话框
                            document.body.removeChild(dialog);
                            document.body.removeChild(overlay);
                        });
                        buttonContainer.appendChild(confirmBtn);
                        dialog.appendChild(buttonContainer);

                        document.body.appendChild(dialog);
                    })
                    .catch(error => {
                        // 移除加载对话框
                        document.body.removeChild(loadingDialog);
                        document.body.removeChild(overlay);

                        alert('加载文件夹列表失败，请重试');
                        console.error('Error loading folders:', error);
                    });
            }

            // 阻止默认拖放行为
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                document.body.addEventListener(eventName, preventDefaults, false);
            });

            // 添加全页面高亮效果
            ['dragenter', 'dragover'].forEach(eventName => {
                document.body.addEventListener(eventName, highlightBody, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                document.body.addEventListener(eventName, unhighlightBody, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            function highlightBody() {
                document.body.classList.add('drag-over');
            }

            function unhighlightBody() {
                document.body.classList.remove('drag-over');
            }

            // 处理放置事件
            document.body.addEventListener('drop', handleDrop, false);

            // 处理文件选择
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    uploadFiles(this.files);
                }
            });

            // 处理文件夹选择
            folderInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    handleFolderSelection(this.files);
                }
            });

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                const items = dt.items;

                // 检查是否拖放了文件夹
                let isFolder = false;
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    if (item.kind === 'file' && item.webkitGetAsEntry) {
                        const entry = item.webkitGetAsEntry();
                        if (entry.isDirectory) {
                            isFolder = true;
                            break;
                        }
                    }
                }

                if (isFolder) {
                    // 处理文件夹拖放
                    handleFolderDrop(items);
                } else if (files.length > 0) {
                    // 处理文件拖放
                    uploadFiles(files);
                }
            }

            // 分片上传配置
            const CHUNK_SIZE = <?php echo $CONFIG['CHUNK_SIZE']; ?>; // 5MB
            const MAX_CONCURRENT = <?php echo $CONFIG['MAX_CONCURRENT']; ?>; // 最大并发数
            const MAX_UPLOAD_SIZE = <?php echo $CONFIG['MAX_UPLOAD_SIZE']; ?>; // 最大上传大小

            // 检查是否需要分片上传
            function needsChunkedUpload(file) {
                const maxFileSize = <?php echo $CONFIG['MAX_FILE_SIZE']; ?>;
                return file.size > maxFileSize;
            }

            // 检查文件是否超过最大限制
            function exceedsMaxSize(file) {
                return file.size > MAX_UPLOAD_SIZE;
            }

            // 格式化文件大小
            function formatFileSize(bytes) {
                if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
                if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
                if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
                return bytes + ' B';
            }

            // 分片上传文件
            function uploadFileWithChunks(file, targetFolder) {
                return new Promise((resolve, reject) => {
                    const fileId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                    const uploadedChunks = new Set();
                    let activeUploads = 0;

                    uploadProgress.style.display = 'block';
                    progressFill.style.width = '0%';
                    uploadStats.textContent = `准备上传：${file.name} (${formatFileSize(file.size)})`;

                    // 上传单个分片
                    function uploadChunk(chunkIndex) {
                        if (uploadedChunks.has(chunkIndex)) return Promise.resolve();

                        const start = chunkIndex * CHUNK_SIZE;
                        const end = Math.min(start + CHUNK_SIZE, file.size);
                        const chunk = file.slice(start, end);

                        const formData = new FormData();
                        formData.append('chunk_upload', '1');
                        formData.append('file_id', fileId);
                        formData.append('chunk_index', chunkIndex);
                        formData.append('total_chunks', totalChunks);
                        formData.append('file_name', file.name);
                        formData.append('file_size', file.size);
                        formData.append('chunk_data', chunk);
                        if (targetFolder) {
                            formData.append('current_path', targetFolder);
                        }

                        activeUploads++;

                        return fetch('<?php echo $currentFile; ?>', {
                                method: 'POST',
                                body: formData
                            })
                            .then(async response => {
                                const text = await response.text();
                                try {
                                    const data = JSON.parse(text);
                                    activeUploads--;
                                    if (data.success) {
                                        uploadedChunks.add(chunkIndex);
                                        const percentComplete = (uploadedChunks.size / totalChunks) * 100;
                                        progressFill.style.width = percentComplete + '%';
                                        uploadStats.textContent = `上传中：${uploadedChunks.size}/${totalChunks} 分片 (${Math.round(percentComplete)}%)`;
                                    } else {
                                        // 显示详细错误和调试信息
                                        const debugInfo = data.debug ? JSON.stringify(data.debug) : '';
                                        console.error('上传错误详情:', data.error, debugInfo);
                                        uploadStats.textContent = `错误: ${data.error}`;
                                        throw new Error(data.error || '分片上传失败');
                                    }
                                } catch (e) {
                                    // JSON 解析失败，显示服务器返回的内容
                                    console.error('服务器响应:', text.substring(0, 500));
                                    console.error('解析错误:', e);
                                    uploadStats.textContent = `服务器错误: ${e.message}`;
                                    throw new Error('服务器响应错误: ' + (e.message || '未知错误'));
                                }
                            })
                            .catch(error => {
                                activeUploads--;
                                console.error('分片上传失败:', chunkIndex, error);
                                throw error;
                            });
                    }

                    // 启动并发上传
                    function startNextChunk() {
                        // 找到下一个未上传的分片
                        for (let i = 0; i < totalChunks; i++) {
                            if (!uploadedChunks.has(i) && activeUploads < MAX_CONCURRENT) {
                                uploadChunk(i).then(() => {
                                    if (uploadedChunks.size < totalChunks) {
                                        startNextChunk();
                                    } else {
                                        // 所有分片上传完成，请求合并
                                        mergeChunks();
                                    }
                                }).catch(() => {
                                    // 失败重试
                                    setTimeout(() => startNextChunk(), 1000);
                                });
                                return;
                            }
                        }
                    }

                    // 合并分片
                    function mergeChunks() {
                        uploadStats.textContent = '正在合并文件...';

                        const formData = new FormData();
                        formData.append('chunk_merge', '1');
                        formData.append('file_id', fileId);
                        if (targetFolder) {
                            formData.append('current_path', targetFolder);
                        }

                        fetch('<?php echo $currentFile; ?>', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    uploadStats.textContent = `上传完成：${data.file_name}`;
                                    progressFill.style.width = '100%';
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 1500);
                                    resolve(data);
                                } else {
                                    throw new Error(data.error || '合并失败');
                                }
                            })
                            .catch(error => {
                                uploadStats.textContent = `合并失败：${error.message}`;
                                reject(error);
                            });
                    }

                    // 开始上传
                    for (let i = 0; i < Math.min(MAX_CONCURRENT, totalChunks); i++) {
                        startNextChunk();
                    }
                });
            }

            // 上传文件（支持分片上传）
            function uploadFiles(files) {
                // 显示进度条
                uploadProgress.style.display = 'block';
                progressFill.style.width = '0%';

                const totalFiles = files.length;
                let processedFiles = 0;

                // 获取当前路径作为目标文件夹
                const currentPath = new URLSearchParams(window.location.search).get('path') || '';

                // 处理单个文件上传（普通上传）
                function uploadSingleFile(file) {
                    return new Promise((resolve, reject) => {
                        const formData = new FormData();
                        formData.append('files[]', file);
                        if (currentPath) {
                            formData.append('target_folder', currentPath);
                        }

                        const xhr = new XMLHttpRequest();

                        xhr.upload.addEventListener('progress', function(e) {
                            if (e.lengthComputable) {
                                const percentComplete = (e.loaded / e.total) * 100;
                                progressFill.style.width = percentComplete + '%';
                                uploadStats.textContent = `正在上传：${file.name} (${Math.round(percentComplete)}%)`;
                            }
                        });

                        xhr.addEventListener('load', function() {
                            resolve();
                        });

                        xhr.addEventListener('error', function() {
                            reject(new Error('上传失败'));
                        });

                        xhr.open('POST', '<?php echo $currentFile; ?>');
                        xhr.send(formData);
                    });
                }

                // 顺序处理每个文件
                function processNextFile() {
                    if (processedFiles >= totalFiles) {
                        uploadStats.textContent = '上传完成！';
                        setTimeout(() => window.location.reload(), 1000);
                        return;
                    }

                    const file = files[processedFiles];

                    // 检查文件是否超过最大限制
                    if (exceedsMaxSize(file)) {
                        uploadStats.textContent = `跳过：${file.name}（超过最大限制 ${formatFileSize(MAX_UPLOAD_SIZE)}）`;
                        processedFiles++;
                        setTimeout(processNextFile, 500);
                        return;
                    }

                    // 判断是否需要分片上传
                    if (needsChunkedUpload(file)) {
                        // 使用分片上传
                        uploadFileWithChunks(file, currentPath)
                            .then(() => {
                                processedFiles++;
                                processNextFile();
                            })
                            .catch(error => {
                                uploadStats.textContent = `上传失败：${file.name} - ${error.message}`;
                                processedFiles++;
                                setTimeout(processNextFile, 2000);
                            });
                    } else {
                        // 使用普通上传
                        uploadStats.textContent = `正在上传：${file.name}`;
                        uploadSingleFile(file)
                            .then(() => {
                                processedFiles++;
                                const percentComplete = (processedFiles / totalFiles) * 100;
                                progressFill.style.width = percentComplete + '%';
                                processNextFile();
                            })
                            .catch(error => {
                                uploadStats.textContent = `上传失败：${file.name}`;
                                processedFiles++;
                                setTimeout(processNextFile, 2000);
                            });
                    }
                }

                // 开始处理第一个文件
                processNextFile();
            }

            // 处理文件夹拖放
            function handleFolderDrop(items) {
                const entries = new Array();

                // 显示进度条
                uploadProgress.style.display = 'block';
                progressFill.style.width = '0%';
                uploadStats.textContent = '正在准备上传文件夹...';

                // 收集所有文件条目，包括文件夹
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    if (item.webkitGetAsEntry) {
                        const entry = item.webkitGetAsEntry();
                        entries.push(entry);
                    }
                }

                // 递归读取文件夹内容
                const allFiles = new Array();
                let totalFiles = 0;
                let processedFiles = 0;

                function readDirectory(entry, path = '') {
                    const dirReader = entry.createReader();

                    function readEntries() {
                        dirReader.readEntries(function(entries) {
                            if (entries.length > 0) {
                                for (let i = 0; i < entries.length; i++) {
                                    const entry = entries[i];
                                    const entryPath = path + '/' + entry.name;

                                    if (entry.isDirectory) {
                                        readDirectory(entry, entryPath);
                                    } else {
                                        totalFiles++;
                                        entry.file(function(file) {
                                            // 只有当路径以斜杠开头时才去掉第一个字符
                                            file.relativePath = entryPath.startsWith('/') ? entryPath.substring(1) : entryPath;
                                            allFiles.push(file);
                                            processedFiles++;

                                            if (processedFiles === totalFiles) {
                                                // 所有文件都已收集，开始上传
                                                uploadFolderFiles(allFiles);
                                            }
                                        });
                                    }
                                }

                                // 继续读取下一批条目
                                readEntries();
                            }
                        });
                    }

                    // 开始读取条目
                    readEntries();
                }

                // 开始读取每个条目
                for (let i = 0; i < entries.length; i++) {
                    const entry = entries[i];
                    if (entry.isDirectory) {
                        readDirectory(entry, entry.name);
                    } else {
                        totalFiles++;
                        entry.file(function(file) {
                            file.relativePath = file.name;
                            allFiles.push(file);
                            processedFiles++;

                            if (processedFiles === totalFiles) {
                                // 所有文件都已收集，开始上传
                                uploadFolderFiles(allFiles);
                            }
                        });
                    }
                }
            }

            // 上传文件夹中的文件
            function uploadFolderFiles(files) {
                const totalFiles = files.length;
                let uploadedFiles = 0;

                uploadStats.textContent = `正在上传：0/${totalFiles} 个文件`;

                // 获取当前URL中的path参数
                const urlParams = new URLSearchParams(window.location.search);
                const currentPath = urlParams.get('path') || '';
                const currentFile = '<?php echo $currentFile; ?>';
                const uploadUrl = currentPath ? `${currentFile}?path=${encodeURIComponent(currentPath)}` : currentFile;

                function uploadNextFile() {
                    if (uploadedFiles >= totalFiles) {
                        // 所有文件上传完成
                        uploadStats.textContent = `上传完成：${totalFiles}/${totalFiles} 个文件`;
                        progressFill.style.width = '100%';

                        // 延迟后刷新页面
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                        return;
                    }

                    const file = files[uploadedFiles];
                    const formData = new FormData();
                    formData.append('folder_file', file);
                    formData.append('relative_path', file.relativePath);

                    const xhr = new XMLHttpRequest();

                    xhr.addEventListener('load', function() {
                        uploadedFiles++;
                        const percentComplete = (uploadedFiles / totalFiles) * 100;
                        progressFill.style.width = percentComplete + '%';
                        uploadStats.textContent = `正在上传：${uploadedFiles}/${totalFiles} 个文件`;

                        // 上传下一个文件
                        uploadNextFile();
                    });

                    xhr.addEventListener('error', function() {
                        uploadedFiles++;
                        uploadStats.textContent = `上传出错：${file.relativePath}`;

                        // 继续上传下一个文件
                        setTimeout(uploadNextFile, 100);
                    });

                    xhr.open('POST', uploadUrl);
                    xhr.send(formData);
                }

                // 开始上传第一个文件
                uploadNextFile();
            }

            // 处理文件夹选择
            function handleFolderSelection(files) {
                // 显示进度条
                uploadProgress.style.display = 'block';
                progressFill.style.width = '0%';

                const totalFiles = files.length;
                let uploadedFiles = 0;

                uploadStats.textContent = `正在上传  0/${totalFiles} 个文件`;

                // 获取当前URL中的path参数
                const urlParams = new URLSearchParams(window.location.search);
                const currentPath = urlParams.get('path') || '';
                const currentFile = '<?php echo $currentFile; ?>';
                const uploadUrl = currentPath ? `${currentFile}?path=${encodeURIComponent(currentPath)}` : currentFile;

                function uploadNextFile() {
                    if (uploadedFiles >= totalFiles) {
                        // 所有文件上传完成
                        uploadStats.textContent = `上传完成：${totalFiles}/${totalFiles} 个文件`;
                        progressFill.style.width = '100%';

                        // 延迟后刷新页面
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                        return;
                    }

                    const file = files[uploadedFiles];
                    const formData = new FormData();
                    formData.append('folder_file', file);
                    // 从webkitRelativePath获取相对路径
                    const relativePath = file.webkitRelativePath || file.name;
                    formData.append('relative_path', relativePath);

                    const xhr = new XMLHttpRequest();

                    xhr.addEventListener('load', function() {
                        uploadedFiles++;
                        const percentComplete = (uploadedFiles / totalFiles) * 100;
                        progressFill.style.width = percentComplete + '%';
                        uploadStats.textContent = `正在上传：${uploadedFiles}/${totalFiles} 个文件`;

                        // 上传下一个文件
                        uploadNextFile();
                    });

                    xhr.addEventListener('error', function() {
                        uploadedFiles++;
                        uploadStats.textContent = `上传出错：${relativePath}`;

                        // 继续上传下一个文件
                        setTimeout(uploadNextFile, 100);
                    });

                    xhr.open('POST', uploadUrl);
                    xhr.send(formData);
                }

                // 开始上传第一个文件
                uploadNextFile();
            }
        });
    </script>
</body>

</html>