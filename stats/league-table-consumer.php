<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    class LeagueTableConsumer
    {
        // Properties
        private $_config;
        private $_db;

        // Public functions
        public function __construct($config, $db)
        {
            $this->_config = $config;
            $this->_db = $db;
        }

        public function consume_league_table($season)
        {
            $db = $this->_db;
            $input_mapper = $this->_config->getInputDataMapper();

            $table_path = $input_mapper->getLeagueTablePath($season);
            $table_str = file_get_contents($table_path);

            if ($this->_config->dumpInputs())
            {
                $dump_path = $this->_config->getInputDumpDataMapper()->getLeagueTablePath(
                    $season
                    );
                $dump_dir = dirname($dump_path);

                if (!file_exists($dump_dir))
                    \plough\mkdirs($dump_dir);

                file_put_contents($dump_path, $table_str);
            }

            //$table_html = \simplexml_load_string($table_str);
        }

        // Private functions
    }
?>
