<?php
    namespace plough\log;
    
    require_once("Psr/Log/LoggerInterface.php");
    require_once("Psr/Log/LogLevel.php");
    require_once("Psr/Log/AbstractLogger.php");
    require_once("utils.php");

    // Constants
    const SEPARATOR_LINE = "---------------------------------------------------------------------------------------------------------";
    
    // Global logger instance
    $logger;
    
    class Logger extends \Psr\Log\AbstractLogger
    {
        private $_date_str;
        private $_log_dir;
        private $_log_path;
        private $_log_file;
        
        public function __construct()
        {
            $this->_log_dir = \plough\get_plugin_root() . "/logs";
        }
        
        private function init($date_str)
        {
            if (!file_exists($this->_log_dir))
                mkdir($this->_log_dir);
            
            $this->_date_str = $date_str;
            $this->_log_path = $this->_log_dir . "/plough-" . $this->_date_str . ".log";
            $this->_log_file = fopen($this->_log_path, "a");
            fwrite($this->_log_file, PHP_EOL . SEPARATOR_LINE . PHP_EOL);
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
            $current_datetime_str = $current_datetime->format("Y-m-d H:i:s");
            
            if ($this->_date_str != $current_date_str)
                $this->init($current_date_str);
            
            $line_no_date = strtoupper($level) . " " . $message . PHP_EOL;
            echo $line_no_date;
            fwrite($this->_log_file, $current_datetime_str . " " . $line_no_date);
        }
    }
    
    function init()
    {
        global $logger;
        $logger = new Logger();
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