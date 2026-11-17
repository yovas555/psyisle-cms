<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\PluginManager;
use app\Core\Request;

/** 前台插件网关：/plugin/{slug}/{path} */
class PluginGate
{
    public function front(Request $req, array $p): void
    {
        PluginManager::frontPage((string)($p['slug'] ?? ''), $req, ltrim((string)($p['path'] ?? ''), '/'));
    }
}
