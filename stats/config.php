<?php
    namespace plough\stats;
    
    require_once("data-mapper.php");
    require_once("utils.php");
    
    const KEY_INPUT_MAPPER_NAME = "InputMapperName";
    const KEY_INPUT_MAPPER_DIR = "InputMapperDir";
    const KEY_INPUT_DUMP_DIR = "InputDumpDir";
    const KEY_OUTPUT_DIR = "OutputDir";
    const KEY_CLEAR_DB = "ClearDb";
    
    const REPLACE_STATS_ROOT = "stats_root";
    
    class Config
    {   
        private $_params;
        
        public function __construct($params)
        {
            $this->_params = $params;
        }
        
        public static function fromXmlFile($xml_path)
        {
            $xml = simplexml_load_file($xml_path);
            $pairs = $xml->xpath("/StatsConfig/Pair");
            
            $params = array();
            foreach ($pairs as $pair)
            {
                $attr = $pair->attributes();
                $params[(string) $attr["Key"]] = (string) $attr["Value"];
            }
            
            return new Config($params);
        }
        
        public function getInputDataMapper()
        {
            $mapper_name = $this->get_param(KEY_INPUT_MAPPER_NAME);
            if ($mapper_name == "web")
            {
                return new WebDataMapper();
            }
            else if ($mapper_name == "file")
            {
                return new FileDataMapper($this->get_param(KEY_INPUT_MAPPER_DIR));
            }
            else
            {
                throw new \Exception("Unknown data mapper [" . $mapper_name . "]");
            }
        }
        
        public function dumpInputs()
        {
            return array_key_exists(KEY_INPUT_DUMP_DIR, $this->_params);
        }
        
        public function getInputDumpDir()
        {
            return $this->get_param(KEY_INPUT_DUMP_DIR);
        }
        
        public function getInputDumpDataMapper()
        {
            return new FileDataMapper($this->getInputDumpDir());
        }
        
        public function getOutputDir()
        {
            return $this->get_param(KEY_OUTPUT_DIR);
        }
        
        public function clearDb()
        {
            return bool_from_str($this->get_param(KEY_CLEAR_DB));
        }
        
        private function get_param($key)
        {
            $result = $this->_params[$key];
            
            // Do replacements
            $result = str_replace("{" . REPLACE_STATS_ROOT . "}", get_stats_root(), $result);
            
            return $result;
        }
    }
?>