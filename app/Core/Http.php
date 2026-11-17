<?php
declare(strict_types=1);

namespace app\Core;

/** 极简 HTTP 客户端（LLM API / 更新检查 / 推送用） */
class Http
{
    public static function post(string $url, string $body, array $headers = [], int $timeout = 60): array
    {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    public static function get(string $url, array $headers = [], int $timeout = 15): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    private static function request(string $method, ?string $url, ?string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PsyIsleCMS/' . CMS_VERSION,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno) { return [false, $status, 'curl#' . $errno . ': ' . $error]; }
        return [true, $status, (string)$resp];
    }
}
