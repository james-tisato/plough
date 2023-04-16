<?php
    namespace plough\log;

    require_once(__DIR__."/Psr/Log/LoggerInterface.php");
    require_once(__DIR__."/Psr/Log/LogLevel.php");
    require_once(__DIR__."/Psr/Log/AbstractLogger.php");
    require_once("utils.php");

    // Global logger instance
    $logger;

    class Logger extends \Psr\Log\AbstractLogger
    {
        private $_date_str;
        private $_log_to_stdout;
        private $_log_dir;
        private $_log_path;
        private $_log_file;

        public function __construct($log_to_stdout)
        {
            $this->_log_dir = \plough\get_plugin_root() . "/logs";
            $this->_log_to_stdout = $log_to_stdout;
        }

        private function init($date_str)
        {
            if (!file_exists($this->_log_dir))
                mkdir($this->_log_dir);

            $this->_date_str = $date_str;
            $this->_log_path = $this->_log_dir . "/plough-" . $this->_date_str . ".log";
            $this->_log_file = fopen($this->_log_path, "a");
            fwrite($this->_log_file, PHP_EOL . \plough\SEPARATOR_LINE . PHP_EOL);
            fwrite($this->_log_file, "Initialised Plough plugin log at " . $this->_log_path . PHP_EOL . PHP_EOL);
        }

        /**
         * Logs with an arbitrary level.
         *
         * @param mixed  $level
         * @param string $message
         * @param array  $context
         *
         * @return void
         */
        public function log($level, $message, array $context = array())
        {
            $current_datetime = new \DateTime("now", new \DateTimeZone("UTC"));
            $current_date_str = $current_datetime->format("Y-m-d");
            $current_datetime_str = $current_datetime->format("Y-m-d H:i:s.v");
            $current_time_str = $current_datetime->format("H:i:s.v");

            if ($this->_date_str != $current_date_str)
                $this->init($current_date_str);

            $log_line = strtoupper($level) . " " . $message . PHP_EOL;
            $line_with_time = $current_time_str . " " . $log_line;
            $line_with_datetime = $current_datetime_str . " " . $log_line;

            if ($this->_log_to_stdout)
                echo $line_with_time;

            fwrite($this->_log_file, $line_with_datetime);
        }
    }

    function init($log_to_stdout = false)
    {
        global $logger;
        $logger = new Logger($log_to_stdout);
    }

    function emergency($message, array $context = array())
    {
        global $logger;
        $logger->emergency($message, $context);
    }

    function alert($message, array $context = array())
    {
        global $logger;
        $logger->alert($message, $context);
    }

    function critical($message, array $context = array())
    {
        global $logger;
        $logger->critical($message, $context);
    }

    function error($message, array $context = array())
    {
        global $logger;
        $logger->error($message, $context);
    }

    function warning($message, array $context = array())
    {
        global $logger;
        $logger->warning($message, $context);
    }

    function notice($message, array $context = array())
    {
        global $logger;
        $logger->notice($message, $context);
    }

    function info($message, array $context = array())
    {
        global $logger;
        $logger->info($message, $context);
    }

    function debug($message, array $context = array())
    {
        global $logger;
        $logger->debug($message, $context);
    }
?>
