<?php
declare(strict_types=1);

namespace app\Core;

/** 安全上传（扩展+MIME 双白名单，图片内容校验，哈希重命名） */
class Uploader
{
    private const IMG_EXT = ['jpg','jpeg','png','gif','webp'];
    private const DOC_EXT = ['pdf','doc','docx','xls','xlsx','txt'];
    private const EXT_MIME = [
        'jpg' => ['image/jpeg','image/pjpeg'], 'jpeg' => ['image/jpeg','image/pjpeg'],
        'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'], 'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'txt' => ['text/plain'],
    ];

    /**
     * @param array $file $_FILES 元素
     * @param string $module 附件归类（article/banner/avatar/doc）
     * @return array [ok(bool), path(string 相对 public), name, error]
     */
    public static function image(array $file, string $module = 'common'): array
    {
        return self::save($file, 'image', $module);
    }

    public static function doc(array $file, string $module = 'doc'): array
    {
        return self::save($file, 'doc', $module);
    }

    public static function detectMime(string $path, string $content, string $ext = ''): string
    {
        $ext = strtolower($ext);
        if (in_array($ext, ['docx', 'xlsx'], true) && strncmp($content, "PK\x03\x04", 4) === 0) {
            if (!class_exists('\\ZipArchive')) return '';
            $zip = new \ZipArchive();
            if (@$zip->open($path, \ZipArchive::CHECKCONS) !== true) return '';
            $hasWord = $zip->locateName('word/document.xml') !== false;
            $hasSheet = $zip->locateName('xl/workbook.xml') !== false;
            $zip->close();
            if ($ext === 'docx' && $hasWord && !$hasSheet) return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            if ($ext === 'xlsx' && $hasSheet && !$hasWord) return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            return '';
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') return strtolower($mime);
        }
        if (strncmp($content, '%PDF-', 5) === 0) return 'application/pdf';
        if (strlen($content) >= 12 && substr($content, 0, 4) === 'RIFF' && substr($content, 8, 4) === 'WEBP') return 'image/webp';
        if (strncmp($content, "GIF87a", 6) === 0 || strncmp($content, "GIF89a", 6) === 0) return 'image/gif';
        if (strlen($content) >= 3 && substr($content, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
        if (strncmp($content, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
        if ($ext === 'txt' && strpos($content, "\0") === false && mb_check_encoding($content, 'UTF-8') && !preg_match('/^\s*</', $content)) {
            return 'text/plain';
        }
        return '';
    }

    private static function save(array $file, string $kind, string $module): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, '', '', '上传失败（错误码 ' . ($file['error'] ?? '-') . '）'];
        }
        if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 20 * 1024 * 1024) {
            return [false, '', '', '文件大小超出限制（≤20MB）'];
        }
        $orig = $file['name'] ?? 'file';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allow = $kind === 'image' ? self::IMG_EXT : array_merge(self::IMG_EXT, self::DOC_EXT);
        if (!in_array($ext, $allow, true)) {
            return [false, '', '', '不允许的文件类型：' . $ext];
        }
        $content = @file_get_contents($file['tmp_name']);
        if ($content === false || strlen($content) !== (int)$file['size']) {
            return [false, '', '', '读取上传文件失败'];
        }
        // PHP/HTML 混入检测（无论扩展名）
        if (preg_match('/<\?php|<\?=|\<script/i', $content)) {
            return [false, '', '', '文件内容不安全，已拒绝'];
        }
        $mime = self::detectMime((string)$file['tmp_name'], $content, $ext);
        if ($kind === 'image') {
            if (strpos($mime, 'image/') !== 0) return [false, '', '', '不是有效的图片文件'];
            $info = @getimagesize($file['tmp_name']);
            if ($info === false) return [false, '', '', '不是有效的图片文件'];
        } elseif (isset(self::EXT_MIME[$ext]) && !in_array($mime, self::EXT_MIME[$ext], true)) {
            return [false, '', '', '文件类型与扩展名不符'];
        }

        // 目录按年月分
        $sub = $module . '/' . date('Ym');
        $dir = UPLOAD_PATH . '/' . $sub;
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $name = date('d') . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            if (!@rename($file['tmp_name'], $dest)) {
                return [false, '', '', '保存文件失败'];
            }
        }
        @chmod($dest, 0644);
        $path = '/uploads/' . $sub . '/' . $name;
        // v2.18 隐私加固：jpg/png 重编码剥离 EXIF（GPS 定位/设备信息——来访者照片属敏感隐私）
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && function_exists('imagecreatetruecolor')) {
            self::stripExif($dest, $ext);
        }
        // 生成 600px 宽缩略图（列表加速；WebP 优先，GD 不支持时回退 JPEG）
        self::makeThumb($dest, $ext, $sub, $name);

        // 记录媒体库
        try {
            Database::insert('attachments', [
                'path' => $path,
                'original_name' => mb_substr($orig, 0, 190),
                'mime' => $mime,
                'size' => (int)$file['size'],
                'hash' => hash('sha256', $content),
                'module' => $module,
                'uploaded_by' => admin_user()['id'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('upload', 'attachment record failed: ' . $t->getMessage());
        }
        return [true, $path, $orig, ''];
    }

    /** v2.18 隐私：重编码覆盖原图（EXIF/GPS/设备元数据全部剥离；失败保留原图不影响上传） */
    private static function stripExif(string $path, string $ext): void
    {
        try {
            $img = $ext === 'png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
            if (!$img) return;
            if ($ext === 'png') {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                imagepng($img, $path, 6);
            } else {
                imagejpeg($img, $path, 88);
            }
            imagedestroy($img);
            @chmod($path, 0644);
        } catch (\Throwable $t) {
            Logger::warning('upload', 'exif strip failed: ' . $t->getMessage());
        }
    }

    /** 列表缩略图：宽 600 等比；输出 /uploads/{sub}/t_{name}.webp|.jpg */
    private static function makeThumb(string $src, string $ext, string $sub, string $name): void
    {
        try {
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true) || !function_exists('imagecreatetruecolor')) return;
            $info = @getimagesize($src);
            if (!$info || $info[0] <= 620) return; // 小图不缩
            $img = $ext === 'png' ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
            if (!$img) return;
            $w = 600;
            $h = (int)round($info[1] * $w / $info[0]);
            $thumb = imagecreatetruecolor($w, $h);
            if ($ext === 'png') {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
                imagefill($thumb, 0, 0, $transparent);
            }
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
            $thumbPath = dirname($src) . '/t_' . pathinfo($name, PATHINFO_FILENAME);
            if (function_exists('imagewebp')) {
                imagewebp($thumb, $thumbPath . '.webp', 82);
            } else {
                imagejpeg($thumb, $thumbPath . '.jpg', 82);
            }
            imagedestroy($thumb);
            imagedestroy($img);
        } catch (\Throwable $t) {
            Logger::error('thumb', $t->getMessage());
        }
    }

    public static function remove(string $publicPath): void
    {
        $full = PUBLIC_PATH . $publicPath;
        $real = realpath($full);
        $base = realpath(UPLOAD_PATH);
        if ($real && $base && strpos($real, $base . DIRECTORY_SEPARATOR) === 0 && is_file($real)) {
            @unlink($real);
            Database::run('DELETE FROM `attachments` WHERE `path` = ?', [$publicPath]);
        }
    }
}
