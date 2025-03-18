<?php

namespace WpifyWooDeps\Wpify\Log;

use WpifyWooDeps\Monolog\Handler\HandlerInterface;
use WpifyWooDeps\Monolog\Logger;
class Log
{
    /**
     * @var Logger
     */
    private $logger;
    private $channel;
    private $handlers;
    /**
     * Detailed debug information
     */
    public const DEBUG = 100;
    /**
     * Interesting events
     * Examples: User logs in, SQL logs.
     */
    public const INFO = 200;
    /**
     * Uncommon events
     */
    public const NOTICE = 250;
    /**
     * Exceptional occurrences that are not errors
     * Examples: Use of deprecated APIs, poor use of an API,
     * undesirable things that are not necessarily wrong.
     */
    public const WARNING = 300;
    /**
     * Runtime errors
     */
    public const ERROR = 400;
    /**
     * Critical conditions
     * Example: Application component unavailable, unexpected exception.
     */
    public const CRITICAL = 500;
    /**
     * Action must be taken immediately
     * Example: Entire website down, database unavailable, etc.
     * This should trigger the SMS alerts and wake you up.
     */
    public const ALERT = 550;
    /**
     * Urgent alert.
     */
    public const EMERGENCY = 600;
    /**
     * @param  string  $channel
     * @param  array  $handlers
     */
    public function __construct(string $channel, array $handlers = [])
    {
        $this->channel = $channel;
        $this->handlers = $handlers;
        $this->logger = new Logger($this->channel);
        foreach ($handlers as $handler) {
            $this->logger->pushHandler($handler);
        }
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        foreach ($levels as $level) {
            add_action("wpify_log_{$this->channel}_{$level}", [$this, $level], 10, 2);
        }
        add_filter('wpify_logs', function ($logs) {
            $logs[$this->channel] = $this->logger;
            return $logs;
        });
        if (!apply_filters('wpify_log_tools_initialized', \false)) {
            add_filter('wpify_log_tools_initialized', '__return_true');
            new Tools();
        }
    }
    /**
     * @param $message
     * @param $data
     *
     * @return void
     */
    public function debug($message, array $data = [])
    {
        $this->logger->debug($message, $data);
    }
    /**
     * @param $message
     * @param $data
     *
     * @return void
     */
    public function info($message, array $data = [])
    {
        $this->logger->info($message, $data);
    }
    /**
     * @param $message
     * @param $data
     *
     * @return void
     */
    public function notice($message, array $data = [])
    {
        $this->logger->notice($message, $data);
    }
    /**
     * @param $message
     * @param  array  $data
     *
     * @return void
     */
    public function warning($message, array $data = array())
    {
        $this->logger->warning($message, $data);
    }
    /**
     * @param $message
     * @param  array  $data
     *
     * @return void
     */
    public function error($message, array $data = [])
    {
        $this->logger->error($message, $data);
    }
    /**
     * @param $message
     * @param $data
     *
     * @return void
     */
    public function critical($message, array $data = [])
    {
        $this->logger->critical($message, $data);
    }
    /**
     * @param $message
     * @param  array  $data
     *
     * @return void
     */
    public function alert($message, array $data = [])
    {
        $this->logger->alert($message, $data);
    }
    /**
     * @param $message
     * @param  array  $data
     *
     * @return void
     */
    public function emergency($message, array $data = [])
    {
        $this->logger->emergency($message, $data);
    }
    /**
     * @return string
     */
    public function get_channel(): string
    {
        return $this->channel;
    }
    /**
     * @return array
     */
    public function get_handlers(): array
    {
        return $this->handlers;
    }
    public function push_handler(HandlerInterface $handler)
    {
        $this->logger->pushHandler($handler);
    }
    /**
     * @return Logger
     */
    public function get_logger(): Logger
    {
        return $this->logger;
    }
}
