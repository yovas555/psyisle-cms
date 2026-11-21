<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 自研 SMTP 客户端（SSL/STARTTLS/AUTH LOGIN），零依赖
 * 配置来源 settings: mail.host / mail.port / mail.user / mail.pass / mail.from / mail.from_name / mail.encryption
 */
class Mailer
{
    private $sock = null;
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $from;
    private string $fromName;
    private string $encryption; // ssl / tls / none
    private string $log = '';

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?: self::config();
        $this->host = (string)($cfg['host'] ?? '');
        $this->port = (int)($cfg['port'] ?? 465);
        $this->user = (string)($cfg['user'] ?? '');
        $this->pass = (string)($cfg['pass'] ?? '');
        $this->from = (string)($cfg['from'] ?? $this->user);
        $this->fromName = (string)($cfg['from_name'] ?? '一二三CMS');
        $this->encryption = (string)($cfg['encryption'] ?? 'ssl');
    }

    public static function config(): array
    {
        // trim+去换行：粘贴污染的授权码（含 \r\n）base64 后会拆断 SMTP 命令行 → 502 Invalid input
        $clean = fn ($v) => trim(preg_replace('/[\r\n]/', '', (string)$v));
        return [
            'host' => $clean(setting('mail.host')),
            'port' => setting('mail.port') ?: 465,
            'user' => $clean(setting('mail.user')),
            'pass' => $clean(setting('mail.pass')),
            'from' => $clean(setting('mail.from') ?: setting('mail.user')),
            'from_name' => $clean(setting('mail.from_name') ?: '一二三CMS'),
            'encryption' => $clean(setting('mail.encryption') ?: 'ssl'),
        ];
    }

    public static function enabled(): bool
    {
        return (bool)setting('mail.enabled') && (bool)setting('mail.host');
    }

    /** 直接发送（不进队列） */
    public static function send(string $to, string $subject, string $html): array
    {
        if (!self::enabled()) return [false, 'SMTP 未配置或未启用'];
        try {
            $m = new self();
            return $m->deliver($to, $subject, $html);
        } catch (\Throwable $e) {
            Logger::error('mailer', $e->getMessage(), ['to' => $to]);
            return [false, $e->getMessage()];
        }
    }

    private function deliver(string $to, string $subject, string $html): array
    {
        // SMTP 头注入防御：收件人/主题不允许 CR/LF
        if (preg_match('/[\r\n]/', $to . $subject)) {
            throw new \RuntimeException('非法的邮件地址或主题（含换行）');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('收件邮箱格式不合法');
        }
        $this->connect();
        $this->expect([220]);
        $hostName = parse_url(base_url(), PHP_URL_HOST) ?: 'psyisle.local';
        $this->cmd('EHLO ' . $hostName, [250]);

        if ($this->encryption === 'tls') {
            $this->cmd('STARTTLS', [220]);
            stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->cmd('EHLO ' . $hostName, [250]);
        }
        if ($this->user !== '') {
            // v2.19.1：授权码缺失/被清空时快速失败并给出明确指引（空密码 base64 会被服务器 502 拒收）
            if ($this->pass === '') {
                throw new \RuntimeException('SMTP 授权码未配置：请在后台 系统设置→邮件 填入邮箱 SMTP 授权码');
            }
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($this->user), [334]);
            $r = $this->cmd(base64_encode($this->pass), [235]);
            if (strpos($r, '235') !== 0) {
                throw new \RuntimeException('SMTP 认证失败：' . $r);
            }
        }
        $this->cmd('MAIL FROM:<' . $this->from . '>', [250]);
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd('DATA', [354]);

        $headers = [
            'From: =?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $hostName . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: PsyIsleCMS',
        ];
        $body = chunk_split(base64_encode($html), 76, "\r\n");
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $this->sendRaw($payload);
        $this->expect([250]);
        $this->cmd('QUIT', [221]);
        $this->close();
        return [true, ''];
    }

    private function connect(): void
    {
        // 顶层键只能是协议名（timeout 是 stream_socket_client 参数/流超时，塞进 context 顶层会报
        // 「Options should have the form ["wrappername"]["optionname"]」并使每次发信失败）
        $opts = [];
        $target = $this->host . ':' . $this->port;
        if ($this->encryption === 'ssl') {
            $target = 'ssl://' . $target;
            $opts['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
        }
        $ctx = stream_context_create($opts);
        $this->sock = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) {
            throw new \RuntimeException("SMTP 连接失败 $errno: $errstr");
        }
        stream_set_timeout($this->sock, 15);
    }

    private function cmd(string $line, array $expect): string
    {
        $this->sendRaw($line);
        return $this->expect($expect);
    }

    private function sendRaw(string $line): void
    {
        fwrite($this->sock, $line . "\r\n");
    }

    private function expect(array $codes): string
    {
        $data = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') { break; }
        }
        $code = (int)substr(trim($data), 0, 3);
        $this->log .= $data;
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('SMTP 响应异常: ' . trim($data));
        }
        return trim($data);
    }

    private function close(): void
    {
        if (is_resource($this->sock)) { fclose($this->sock); }
        $this->sock = null;
    }
}
