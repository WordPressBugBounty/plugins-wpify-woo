<?php

namespace WpifyWooDeps\Wpify\Log;

use WpifyWooDeps\Monolog\Formatter\FormatterInterface;
use WpifyWooDeps\Monolog\Formatter\JsonFormatter;
use WpifyWooDeps\Monolog\Logger;
class RotatingFileLog extends Log
{
    /**
     * @param string                  $channel
     * @param string                  $path
     * @param FormatterInterface|null $formatter
     */
    public function __construct(string $channel, string $path = '', ?FormatterInterface $formatter = null)
    {
        $filename = sprintf('wpify_log_%s', $channel);
        if (!$path) {
            $dir = wp_get_upload_dir();
            $path = trailingslashit($dir['basedir']) . 'logs' . \DIRECTORY_SEPARATOR . $filename;
            $key = $channel;
            if (defined('NONCE_KEY')) {
                $key = $channel . \NONCE_KEY;
            }
            $path = sprintf('%s_%s.log', $path, hash('md5', $key));
        }
        $handler = new RotatingFileHandler($path, 5, Logger::DEBUG, \true, 0660);
        $handler->setFormatter($formatter ?? new JsonFormatter());
        parent::__construct($channel, [$handler]);
    }
}
