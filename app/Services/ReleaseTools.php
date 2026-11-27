<?php
declare(strict_types=1);

namespace app\Services;

class ReleaseTools
{
    public static function ensureInstalled(): array
    {
        $sourceDir = APP . '/Support/ReleaseTools';
        $targetDir = ROOT . '/tools';
        $installed = [];
        foreach (ReleasePackage::CORE_TOOLS as $entry) {
            $name = basename($entry);
            $source = $sourceDir . '/' . $name;
            $target = $targetDir . '/' . $name;
            if (is_link($source) || !is_file($source)) throw new \RuntimeException('缺少有效的工具校验副本：' . $name);
            $content = @file_get_contents($source);
            $expectedHash = @hash_file('sha256', $source);
            if ($content === false || !is_string($expectedHash)) throw new \RuntimeException('读取工具校验副本失败：' . $name);
            if (is_link($target)) throw new \RuntimeException('工具路径不得为符号链接：' . $name);
            if (is_file($target) && hash_equals($expectedHash, (string)hash_file('sha256', $target))) continue;
            if (is_link($targetDir)) throw new \RuntimeException('tools 目录不得为符号链接');
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) throw new \RuntimeException('无法创建 tools 目录');
            $tmp = $target . '.tmp-' . bin2hex(random_bytes(6));
            $bytes = @file_put_contents($tmp, $content);
            $tempHash = @hash_file('sha256', $tmp);
            if ($bytes === false || $bytes !== strlen($content) || $tempHash !== $expectedHash || !@chmod($tmp, 0644)) {
                @unlink($tmp);
                throw new \RuntimeException('写入或校验工具失败：' . $name);
            }
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException('安装工具失败：' . $name);
            }
            clearstatcache(true, $target);
            if (is_link($target) || !is_file($target) || !hash_equals($expectedHash, (string)hash_file('sha256', $target))) {
                throw new \RuntimeException('工具安装后回读校验失败：' . $name);
            }
            $installed[] = $entry;
        }
        return $installed;
    }
}
