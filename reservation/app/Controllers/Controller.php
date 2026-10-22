<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\App;
use App\Support\Response;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return new Response($this->app->view->render($template, $data), $status);
    }
}
