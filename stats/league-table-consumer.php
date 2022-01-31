<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    // Constants
    const TABLE_SENTINEL = "\u{a0}";

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

        public function consume_league_tables($season)
        {
            $db = $this->_db;

            // Clear existing league table data for this season
            db_delete_season_from_table($db, "LeagueTableEntry", $season);

            // Fetch raw input HTML
            $input_mapper = $this->_config->getInputDataMapper();

            $divisions = get_league_divisions_for_season($season);
            foreach ($divisions as $division)
            {
                log\info("  Division $division");
                $table_path = $input_mapper->getLeagueTablePath($season, $division);
                if (!is_null($table_path))
                {
                    $table_str = file_get_contents($table_path);
                    if ($table_str)
                    {
                        if ($this->_config->dumpInputs())
                        {
                            $dump_path = $this->_config->getInputDumpDataMapper()->getLeagueTablePath(
                                $season, $division
                                );
                            $dump_dir = dirname($dump_path);

                            if (!file_exists($dump_dir))
                                \plough\mkdirs($dump_dir);

                            file_put_contents($dump_path, $table_str);
                        }

                        // Parse HTML to extract league table data
                        // Note that the HTML is usually malformed so we disable libxml error / warning
                        // generation to avoid repeatedly triggering the debugger's "stop on exception"
                        // behaviour during development.
                        $doc = new \DOMDocument();
                        \libxml_use_internal_errors(true);
                        @$doc->loadhtml($table_str);
                        \libxml_use_internal_errors(false);
                        $xpath = new \DOMXPath($doc);
                        $rows = $xpath->query('//article/table/tbody')->item(0)->getElementsByTagName('tr');

                        // Add each league table entry to the database
                        $db->exec('BEGIN');
                        $cell_map = array();
                        foreach ($rows as $idx => $row)
                        {
                            $cells = $row->getElementsByTagName('td');
                            if ($idx == 0)
                            {
                                // This is the header - map cell header names to indices
                                foreach ($cells as $idx => $cell)
                                    $cell_map[$cell->textContent] = $idx;
                            }
                            else
                            {
                                $club = $cells->item($cell_map["Club"])->textContent;
                                $length = strlen($club);
                                if ($club !== TABLE_SENTINEL)
                                {
                                    $inserter = db_create_insert_league_table_entry($db);
                                    $inserter->bindValue(":Season", $season);
                                    $inserter->bindValue(":Division", $division);
                                    $inserter->bindValue(":Position", $idx);
                                    $inserter->bindValue(":Club", $club);
                                    $inserter->bindValue(":Abandoned", $cells->item($cell_map["A"])->textContent);
                                    $inserter->bindValue(":Played", $cells->item($cell_map["P"])->textContent);
                                    $inserter->bindValue(":Won", $cells->item($cell_map["W"])->textContent);
                                    $inserter->bindValue(":Lost", $cells->item($cell_map["L"])->textContent);
                                    $inserter->bindValue(":Tied", $cells->item($cell_map["T"])->textContent);
                                    $inserter->bindValue(":BonusPoints", $cells->item($cell_map["Bonus Points"])->textContent);
                                    $inserter->bindValue(":PenaltyPoints", $cells->item($cell_map["Penalty Points"])->textContent);
                                    $inserter->bindValue(":TotalPoints", $cells->item($cell_map["Total Points"])->textContent);

                                    $average = $cells->item($cell_map["Avge"])->textContent;
                                    if ($average === TABLE_SENTINEL)
                                        $average = "-";
                                    $inserter->bindValue(":AveragePoints", $average);

                                    $inserter->execute();
                                }
                            }
                        }

                        $db->exec('COMMIT');
                    }
                }
            }
        }
    }
?>
