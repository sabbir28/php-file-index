<?php
/**
 * Single-File Read-Only PHP File Manager with Virtual Drive & External URL Support
 * Title: File Index
 */

define('ROOT_DIR', __DIR__ . '/files'); 
define('ENABLE_CACHE', true); 
define('CACHE_TTL', 30); 
define('HIDE_DOTFILES', true);

ini_set('display_errors', '0');
error_reporting(0);

/* ================= UTILITIES ================= */
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function human_size(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    for($i=0; $bytes>=1024 && $i<4; $i++) $bytes/=1024;
    return round($bytes,2).' '.$units[$i];
}

function safe_path(string $path): string|false {
    $real = realpath(ROOT_DIR.'/'.$path);
    if(!$real) return false;
    $root_real = realpath(ROOT_DIR);
    return str_starts_with($real, $root_real) ? $real : false;
}

function is_hidden(string $name): bool {
    return HIDE_DOTFILES && str_starts_with($name,'.');
}

function is_url(string $path): bool {
    return filter_var($path, FILTER_VALIDATE_URL) !== false;
}

/* ================= GET PARAMETERS ================= */
$path = $_GET['p'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$q = $_GET['q'] ?? '';
$ext = $_GET['ext'] ?? '';

$absPath = safe_path($path);
if($absPath===false || !is_dir($absPath)) {
    http_response_code(404);
    exit('Invalid path');
}

/* ================= VIRTUAL FILES ================= */
function load_virtual_files(string $dir, string $relativePath = ''): array {
    $list = [];
    $dh = opendir($dir);
    while(($file = readdir($dh)) !== false){
        if($file=='.'||$file=='..') continue;
        $full = $dir.'/'.$file;
        $rel = trim($relativePath.'/'.$file,'/');
        if(preg_match('/drive.*\.txt$/i', $file) && is_file($full)){
            $list = array_merge($list, load_virtual_file($full, $rel));
        }
    }
    closedir($dh);
    return $list;
}

function load_virtual_file(string $virtualFile, string $relativeDir): array {
    $list = [];
    if(!file_exists($virtualFile)) return $list;

    $lines = file($virtualFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    $item = [];
    foreach($lines as $line){
        $line = trim($line);
        if(!$line) continue;

        if(strpos($line, ':')!==false){
            [$key,$value] = explode(':', $line, 2);
            $item[strtolower(trim($key))] = trim($value);
        }

        if(isset($item['filename'],$item['url'],$item['type'])){
            $type = strtolower($item['type']);
            switch($type){
                case 'pdf': $mime='application/pdf'; break;
                case 'png': $mime='image/png'; break;
                case 'jpg': case 'jpeg': $mime='image/jpeg'; break;
                case 'txt': $mime='text/plain'; break;
                case 'folder': $mime=''; break;
                default: $mime='application/octet-stream'; break;
            }

            $pathStr = trim($item['url'], '/');
            if(is_url($pathStr)){
                $mtime = time();
                $size = 0;
            } else {
                $filepath = ROOT_DIR.'/'.$pathStr;
                $mtime = file_exists($filepath) ? filemtime($filepath) : time();
                $size = file_exists($filepath) ? filesize($filepath) : 0;
            }

            $list[] = [
                'name' => $item['filename'],
                'path' => $pathStr,
                'is_dir' => $type==='folder',
                'size' => $size,
                'mtime' => $mtime,
                'mime' => $mime
            ];
            $item = [];
        }
    }
    return $list;
}

/* ================= DIRECTORY SCAN + CACHE ================= */
$cacheFile = sys_get_temp_dir().'/fm_'.md5($absPath).'.json';

if(ENABLE_CACHE && file_exists($cacheFile) && time()-filemtime($cacheFile)<CACHE_TTL){
    $items = json_decode(file_get_contents($cacheFile), true);
} else {
    $items = [];
    $dh = opendir($absPath);
    while(($file = readdir($dh)) !== false){
        if($file=='.'||$file=='..'||is_hidden($file)) continue;
        if(preg_match('/drive.*\.txt$/i', $file)) continue;

        $full = $absPath.'/'.$file;
        $stat = stat($full);
        $items[] = [
            'name'=>$file,
            'path'=>trim($path.'/'.$file,'/'),
            'is_dir'=>is_dir($full),
            'size'=>$stat['size'],
            'mtime'=>$stat['mtime'],
            'mime'=>is_file($full)?mime_content_type($full):''
        ];
    }
    closedir($dh);

    $items = array_merge($items, load_virtual_files($absPath, $path));

    if(ENABLE_CACHE && strlen(json_encode($items))<50000){
        file_put_contents($cacheFile,json_encode($items));
    }
}

/* ================= FILTER ================= */
if($q){
    $items = array_filter($items, function($i) use($q) {
        return stripos($i['name'],$q)!==false;
    });
}
if($ext){
    $ext = strtolower(ltrim($ext,'.'));
    $items = array_filter($items, function($i) use($ext) {
        return strtolower(pathinfo($i['name'], PATHINFO_EXTENSION)) === $ext;
    });
}

/* ================= SORT ================= */
usort($items, function($a,$b) use($sort){
    switch($sort){
        case 'size': return $a['size'] <=> $b['size'];
        case 'time': return $b['mtime'] <=> $a['mtime'];
        default: return strcasecmp($a['name'],$b['name']);
    }
});

/* ================= FILE ICONS ================= */
function file_icon($filename,$is_dir,$is_external=false){
    if($is_dir) return $is_external
        ? '<i class="fa-brands fa-google-drive fa-fw" style="color:#4285F4"></i>'
        : '<i class="fa fa-folder fa-fw"></i>';

    $ext=strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch($ext){
        case 'pdf': return '<i class="fa fa-file-pdf fa-fw" style="color:#e74c3c"></i>';
        case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp': return '<i class="fa fa-file-image fa-fw" style="color:#f39c12"></i>';
        case 'txt': case 'md': return '<i class="fa fa-file-alt fa-fw" style="color:#3498db"></i>';
        case 'zip': case 'rar': case '7z': case 'tar': case 'gz': return '<i class="fa fa-file-archive fa-fw" style="color:#9b59b6"></i>';
        case 'mp3': case 'wav': case 'ogg': return '<i class="fa fa-file-audio fa-fw" style="color:#1abc9c"></i>';
        case 'mp4': case 'mkv': case 'avi': return '<i class="fa fa-file-video fa-fw" style="color:#e67e22"></i>';
        case 'doc': case 'docx': return '<i class="fa fa-file-word fa-fw" style="color:#2980b9"></i>';
        case 'xls': case 'xlsx': return '<i class="fa fa-file-excel fa-fw" style="color:#27ae60"></i>';
        case 'ppt': case 'pptx': return '<i class="fa fa-file-powerpoint fa-fw" style="color:#d35400"></i>';
        default: return '<i class="fa fa-file fa-fw"></i>';
    }
}

/* ================= HTML OUTPUT ================= */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>File Index</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-family: system-ui; background:#f5f5f5; margin:0; padding:2rem; }
h1 { font-size:2rem; margin-bottom:1rem; color:#2c3e50; }
table { width:100%; border-collapse:collapse; margin-top:1rem; }
th, td { padding:12px; border-bottom:1px solid #ddd; text-align:left; }
th a { text-decoration:none; color:#2c3e50; }
td a { text-decoration:none; color:#2980b9; }
tr:hover { background:#ecf0f1; }
form input { margin-right:4px; }
</style>
</head>
<body>
<h1>File Index /<?= h($path) ?></h1>
<form method="get" style="margin-bottom:1rem;">
<input type="hidden" name="p" value="<?= h($path) ?>">
<input name="q" placeholder="Search..." value="<?= h($q) ?>" style="padding:4px 8px;">
<input name="ext" placeholder="Ext..." value="<?= h($ext) ?>" style="padding:4px 8px;">
<button style="padding:4px 10px;">Filter</button>
</form>
<table>
<tr>
<th><a href="?p=<?= h($path) ?>&sort=name">Name</a></th>
<th>Size</th>
<th><a href="?p=<?= h($path) ?>&sort=time">Modified</a></th>
</tr>
<?php if($path): ?>
<tr><td colspan="3"><a href="?p=<?= h(dirname($path)) ?>">⬅ Parent Directory</a></td></tr>
<?php endif; ?>
<?php foreach($items as $i):
$is_external = is_url($i['path']);
$fileHref = $is_external ? h($i['path']) : 'files/' . ltrim($i['path'],'/');
?>
<tr>
<td><?= file_icon($i['name'], $i['is_dir'], $is_external) ?> 
<?php if($i['is_dir']): ?>
<a href="<?= $is_external ? h($i['path']) : '?p='.h($i['path']) ?>" <?= $is_external ? 'target="_blank"' : '' ?>><?= h($i['name']) ?></a>
<?php else: ?>
<a href="<?= $fileHref ?>" <?= $is_external ? 'target="_blank"' : 'download' ?>><?= h($i['name']) ?></a>
<?php endif; ?>
</td>
<td><?= $i['is_dir'] ? '-' : human_size($i['size']) ?></td>
<td><?= date('Y-m-d H:i',$i['mtime']) ?></td>
</tr>
<?php endforeach; ?>
</table>
</body>
</html>
