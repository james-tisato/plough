<?php
    namespace plough\stats;

    require_once("data-mapper.php");
    require_once(__DIR__ . "/../utils.php");

    // Config keys
    const KEY_CAREER_BASE_SEASON = "CareerBaseSeason";
    const KEY_CURRENT_SEASON = "CurrentSeason";

    const KEY_CLEAR_DB = "ClearDb";
    const KEY_DB_DIR = "DbDir";

    const KEY_STATIC_DIR = "StaticDir";

    const KEY_INPUT_MAPPER_NAME = "InputMapperName";
    const KEY_INPUT_MAPPER_DIR = "InputMapperDir";
    const KEY_INPUT_DUMP_DIR = "InputDumpDir";

    const KEY_OUTPUT_DIR = "OutputDir";


    const REPLACE_PLUGIN_ROOT = "plugin_root";
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

        // Seasons
        public function getCareerBaseSeason()
        {
            return intval($this->getParam(KEY_CAREER_BASE_SEASON));
        }

        public function getCurrentSeason()
        {
            return intval($this->getParam(KEY_CURRENT_SEASON));
        }

        // Database
        public function clearDb()
        {
            return \plough\bool_from_str($this->getParam(KEY_CLEAR_DB));
        }

        public function getDbDir()
        {
            return $this->getParam(KEY_DB_DIR);
        }

        // Static
        public function getStaticDir()
        {
            return $this->getParam(KEY_STATIC_DIR);
        }

        // Input
        public function getInputDataMapper()
        {
            $mapper_name = $this->getParam(KEY_INPUT_MAPPER_NAME);
            if ($mapper_name == "web")
            {
                return new WebDataMapper();
            }
            else if ($mapper_name == "file")
            {
                return new FileDataMapper($this->getParam(KEY_INPUT_MAPPER_DIR));
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
            return $this->getParam(KEY_INPUT_DUMP_DIR);
        }

        public function getInputDumpDataMapper()
        {
            return new FileDataMapper($this->getInputDumpDir());
        }

        // Output
        public function getOutputDir()
        {
            return $this->getParam(KEY_OUTPUT_DIR);
        }

        // Private functions
        private function getParam($key)
        {
            $result = $this->_params[$key];

            // Do replacements
            $result = str_replace("{" . REPLACE_PLUGIN_ROOT . "}", \plough\get_plugin_root(), $result);
            $result = str_replace("{" . REPLACE_STATS_ROOT . "}", \plough\get_stats_root(), $result);

            return $result;
        }
    }
?>
