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
                    $table_str = safe_file_get_contents($table_path);
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

                        if ($season < 2020)
                        {
                            $rows = $this->extract_pre_2020_table_data($table_str);
                        }
                        else
                        {
                            $rows = $this->extract_table_data($table_str);
                        }

                        // Add each league table entry to the database
                        $db->exec('BEGIN');
                        foreach ($rows as $row_idx => $row)
                        {
                            $inserter = db_create_insert_league_table_entry($db);
                            $inserter->bindValue(":Season", $season);
                            $inserter->bindValue(":Division", $division);
                            $inserter->bindValue(":Position", $row_idx + 1);
                            $inserter->bindValue(":Club", $row["Club"]);
                            $inserter->bindValue(":Abandoned", $row["A"]);
                            $inserter->bindValue(":Played", $row["P"]);
                            $inserter->bindValue(":Won", $row["W"]);
                            $inserter->bindValue(":Lost", $row["L"]);
                            $inserter->bindValue(":Tied", $row["T"]);
                            $inserter->bindValue(":BonusPoints", $row["Bonus Points"]);
                            $inserter->bindValue(":PenaltyPoints", $row["Penalty Points"]);
                            $inserter->bindValue(":TotalPoints", $row["Total Points"]);
                            $inserter->bindValue(":AveragePoints", $row["Avge"]);
                            $inserter->execute();
                        }

                        $db->exec('COMMIT');
                    }
                }
            }
        }

        private function extract_table_data($table_str)
        {
            $table = json_decode($table_str, true)["league_table"][0];

            // Build mapping from heading name to column number
            $header_to_column = array();
            $headings = $table["headings"];
            foreach($headings as $column => $header)
                $header_to_column[$header] = $column;

            // Build table rows
            $rows = array();
            $raw_rows = $table["values"];
            foreach ($raw_rows as $raw_row)
            {
                $row = array(
                    "Club" => $raw_row[$header_to_column["Team"]],
                    "A" => $raw_row[$header_to_column["a"]],
                    "P" => $raw_row[$header_to_column["p"]],
                    "W" => $raw_row[$header_to_column["w"]] + $raw_row[$header_to_column["wcn"]],
                    "L" => $raw_row[$header_to_column["l"]] + $raw_row[$header_to_column["lcn"]],
                    "T" => $raw_row[$header_to_column["t"]],
                    "Bonus Points" => $raw_row[$header_to_column["BatP"]] + $raw_row[$header_to_column["BowlP"]],
                    "Penalty Points" => $raw_row[$header_to_column["Pen"]],
                    "Total Points" => $raw_row[$header_to_column["Pts"]],
                    "Avge" => $raw_row[$header_to_column["Ave"]]
                    );
                array_push($rows, $row);
            }

            return $rows;
        }

        private function extract_pre_2020_table_data($table_str)
        {
            // Parse HTML to extract league table data
            // Note that the HTML is usually malformed so we disable libxml error / warning
            // generation to avoid repeatedly triggering the debugger's "stop on exception"
            // behaviour during development.
            $doc = new \DOMDocument();
            \libxml_use_internal_errors(true);
            @$doc->loadhtml($table_str);
            \libxml_use_internal_errors(false);
            $xpath = new \DOMXPath($doc);
            $raw_rows = $xpath->query('//article/table/tbody')->item(0)->getElementsByTagName('tr');

            $cell_map = array();
            $rows = array();
            foreach ($raw_rows as $row_idx => $raw_row)
            {
                $cells = $raw_row->getElementsByTagName('td');
                if ($row_idx == 0)
                {
                    // This is the header - map cell header names to indices
                    foreach ($cells as $cell_idx => $cell)
                        $cell_map[$cell->textContent] = $cell_idx;
                }
                else
                {
                    $club = $cells->item($cell_map["Club"])->textContent;
                    if ($club !== TABLE_SENTINEL)
                    {
                        // Extract data from each row using cell map built from header
                        $row = array();
                        foreach ($cell_map as $key => $cell_idx)
                            $row[$key] = $cells->item($cell_idx)->textContent;

                        if ($row["Avge"] === TABLE_SENTINEL)
                            $row["Avge"] = "-";

                        array_push($rows, $row);
                    }
                }
            }

            return $rows;
        }
    }
?>
