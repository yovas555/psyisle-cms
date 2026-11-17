<?php
declare(strict_types=1);

namespace app\Core;

/** 声明式验证器 */
class Validator
{
    private array $errors = [];

    /**
     * rules: ['field' => 'required|maxlen:40|phone', ...]
     * data:  待验数据数组
     * labels: ['field' => '姓名', ...]
     */
    public function __construct(array $data, array $rules, array $labels = [])
    {
        foreach ($rules as $field => $ruleStr) {
            $value = $data[$field] ?? '';
            $label = $labels[$field] ?? $field;
            foreach (explode('|', $ruleStr) as $rule) {
                if ($rule === '') continue;
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $ok = true;
                $isBlank = is_string($value) ? trim($value) === '' : ($value === null || $value === []);
                switch ($name) {
                    case 'required':   $ok = !$isBlank; break;
                    case 'maxlen':     $ok = $isBlank || mb_strlen((string)$value) <= (int)$arg; break;
                    case 'minlen':     $ok = $isBlank || mb_strlen((string)$value) >= (int)$arg; break;
                    case 'phone':      $ok = $isBlank || preg_match('/^1[3-9]\d{9}$/', (string)$value) === 1; break;
                    case 'phone_or_qq':
                        $ok = $isBlank || preg_match('/^1[3-9]\d{9}$/', (string)$value) === 1
                            || preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{5,19}$/', (string)$value) === 1
                            || preg_match('/^\d{5,12}$/', (string)$value) === 1;
                        break;
                    case 'email':      $ok = $isBlank || filter_var((string)$value, FILTER_VALIDATE_EMAIL) !== false; break;
                    case 'date':       $ok = $isBlank || strtotime((string)$value) !== false; break;
                    case 'int':        $ok = $isBlank || preg_match('/^\d+$/', (string)$value) === 1; break;
                    case 'in':         $ok = $isBlank || in_array((string)$value, explode(',', (string)$arg), true); break;
                    case 'len':        $ok = $isBlank || mb_strlen((string)$value) === (int)$arg; break;
                    case 'safe_text':  // 禁 <script 等
                        $ok = $isBlank || preg_match('/<\s*script|javascript\s*:|on\w+\s*=/i', (string)$value) !== 1;
                        break;
                }
                if (!$ok) { $this->errors[$field] = sprintf('%s 格式不正确', $label); break; }
            }
        }
    }

    public function fails(): bool { return $this->errors !== []; }

    public function firstError(): ?string
    {
        return $this->errors ? reset($this->errors) : null;
    }

    public function all(): array { return $this->errors; }
}
