<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Request;

class Help extends Base
{
    protected string $permNode = 'help';

    public function index(Request $req, array $p): void
    {
        $this->view('help', ['title' => '使用手册']);
    }
}
