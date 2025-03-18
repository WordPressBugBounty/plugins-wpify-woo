<?php

namespace WpifyWooDeps\Wpify\Log;

class RotatingFileHandler extends \WpifyWooDeps\Monolog\Handler\RotatingFileHandler
{
    public function get_glob_pattern(): string
    {
        return $this->getGlobPattern();
    }
}
