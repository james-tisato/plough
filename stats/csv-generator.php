<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    // Constants
    // Stats period types
    const PERIOD_CAREER = 1;
    const PERIOD_SEASON = 2;

    // Helpers
    function get_milestone_col_header($current_season)
    {
        return $current_season . " Career Milestones";
    }

    function get_table_and_output_names($period_type, $discipline_type, $table_prefix_override = null)
    {
        $table_prefix = (is_null($table_prefix_override) ? $discipline_type : $table_prefix_override);

        if ($period_type == PERIOD_CAREER)
        {
            return [
                "Career" . $table_prefix . "Summary",
                strtolower($discipline_type) . "_career_ind_summary"
                ];
        }
        else if ($period_type == PERIOD_SEASON)
        {
            return [
                $table_prefix . "Summary",
                strtolower($discipline_type) . "_ind_summary"
                ];
        }
    }

    function get_formatted_rows_from_query($statement)
    {
        $rows = array();

        $query_result = $statement->execute();
        while ($row = $query_result->fetchArray(SQLITE3_ASSOC))
        {
            $formatted_row = array();
            foreach ($row as $key => $value)
            {
                $formatted_value = $value;

                if (is_null($value))
                    $formatted_value = "-";
                else if (is_float($value))
                    $formatted_value = sprintf("%.2f", $value);

                array_push($formatted_row, $formatted_value);
            }

            array_push($rows, $formatted_row);
        }

        return $rows;
    }

    class CsvGenerator
    {
        // Properties
        private $_config;
        private $_db;
        private $_milestone_generator;

        // Public functions
        public function __construct($config, $db, $milestone_generator)
        {
            $this->_config = $config;
            $this->_db = $db;
            $this->_milestone_generator = $milestone_generator;
        }

        public function generate_season_csv_files($season)
        {
            log\info("    Batting");
            $this->generate_batting_summary_csv(PERIOD_SEASON);
            log\info("    Bowling");
            $this->generate_bowling_summary_csv(PERIOD_SEASON);
            log\info("    Fielding");
            $this->generate_fielding_summary_csv(PERIOD_SEASON);
            log\info("    Keeping");
            $this->generate_keeping_summary_csv(PERIOD_SEASON);
        }

        public function generate_career_csv_files()
        {
            log\info("    Batting");
            $this->generate_batting_summary_csv(PERIOD_CAREER);
            log\info("    Bowling");
            $this->generate_bowling_summary_csv(PERIOD_CAREER);
            log\info("    Fielding");
            $this->generate_fielding_summary_csv(PERIOD_CAREER);
            log\info("    Keeping");
            $this->generate_keeping_summary_csv(PERIOD_CAREER);
        }

        public function generate_other_csv_files()
        {
            log\info("    Last updated");
            $this->generate_last_updated_csv();
        }

        // Private generators
        private function generate_last_updated_csv()
        {
            $db = $this->_db;
            $last_update = get_last_update_datetime($db)->format(DATETIME_FRIENDLY_FORMAT);

            $statement = $db->prepare(
               'SELECT
                     m.HomeClubName
                    ,m.AwayClubName
                    ,m.CompetitionType
                FROM Match m
                ORDER BY MatchDate DESC
                LIMIT 1
                ');
            $last_match = $statement->execute()->fetchArray(SQLITE3_ASSOC);
            $last_match_str =
                $last_match["HomeClubName"] . " vs " . $last_match["AwayClubName"] .
                " (" . $last_match["CompetitionType"] . ")";

            $table = array();
            array_push($table, array("Last updated", $last_update));
            array_push($table, array("Last match", $last_match_str));

            $this->generate_csv_output("last_updated", $table);
        }

        private function generate_batting_summary_csv($period_type)
        {
            $db = $this->_db;
            $current_season = $this->_config->getCurrentSeason();

            [ $table_name, $output_name ] = get_table_and_output_names($period_type, "Batting");

            $header = array(
                "Player", "Mat", "Inns", "NO", "Runs", "Ave", "SR", "HS",
                "50s", "100s", "0s", "4s", "6s", "Balls", "Active", get_milestone_col_header($current_season)
                );

            $statement = $db->prepare(
               'SELECT
                      p.Name
                     ,bs.Matches
                     ,bs.Innings
                     ,bs.NotOuts
                     ,bs.Runs
                     ,bs.Average
                     ,bs.StrikeRate
                     ,(CAST(bs.HighScore AS TEXT) || CASE bs.HighScoreNotOut WHEN 1 THEN \'*\' ELSE \'\' END) as HighScore
                     ,bs.Fifties
                     ,bs.Hundreds
                     ,bs.Ducks
                     ,bs.Fours
                     ,bs.Sixes
                     ,bs.Balls
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' bs on bs.PlayerId = p.PlayerId
                WHERE bs.Innings > 0
                ORDER by bs.Runs DESC, bs.Average DESC, bs.Innings DESC, bs.NotOuts DESC, bs.Matches DESC, p.Name
                ');

            $rows = get_formatted_rows_from_query($statement);
            $rows_with_milestones = $this->_milestone_generator->join_milestones_to_player_rows(
                $rows, [ MS_TYPE_GENERAL, MS_TYPE_BATTING ]
                );
            $this->generate_csv_output($output_name, $rows_with_milestones, $header);
        }

        private function generate_bowling_summary_csv($period_type)
        {
            $db = $this->_db;
            $current_season = $this->_config->getCurrentSeason();

            [ $table_name, $output_name ] = get_table_and_output_names($period_type, "Bowling");

            $header = array(
                "Player", "Mat", "Overs", "Mdns", "Runs", "Wkts", "Ave",
                "Econ", "SR", "Best", "5wi", "Wides", "NBs", "Active", get_milestone_col_header($current_season)
                );

            $statement = $db->prepare(
                'SELECT
                      p.Name
                     ,bs.Matches
                     ,(CAST(bs.CompletedOvers AS TEXT) || \'.\' || CAST(bs.PartialBalls AS TEXT)) as Overs
                     ,bs.Maidens
                     ,bs.Runs
                     ,bs.Wickets
                     ,bs.Average
                     ,bs.EconomyRate
                     ,bs.StrikeRate
                     ,(CAST(bs.BestBowlingWickets AS TEXT) || \'/\' || CAST(bs.BestBowlingRuns AS TEXT)) as BestBowling
                     ,bs.FiveFors
                     ,bs.Wides
                     ,bs.NoBalls
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' bs on bs.PlayerId = p.PlayerId
                WHERE
                        (bs.CompletedOvers > 0 OR bs.PartialBalls > 0)
                ORDER by bs.Wickets DESC, bs.Average, bs.EconomyRate
                ');

            $rows = get_formatted_rows_from_query($statement);
            $rows_with_milestones = $this->_milestone_generator->join_milestones_to_player_rows(
                $rows, [ MS_TYPE_GENERAL, MS_TYPE_BOWLING ]
                );
            $this->generate_csv_output($output_name, $rows_with_milestones, $header);
        }

        private function generate_fielding_summary_csv($period_type)
        {
            $db = $this->_db;
            $current_season = $this->_config->getCurrentSeason();

            [ $table_name, $output_name ] = get_table_and_output_names($period_type, "Fielding");

            $header = array(
                "Player", "Mat", "Ct", "RO", "Total", "Active", get_milestone_col_header($current_season)
                );

            $statement = $db->prepare(
               'SELECT
                      p.Name
                     ,fs.Matches
                     ,fs.CatchesFielding
                     ,fs.RunOuts
                     ,fs.TotalFieldingWickets
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' fs on fs.PlayerId = p.PlayerId
                WHERE
                        fs.TotalFieldingWickets > 0
                ORDER by fs.TotalFieldingWickets DESC, fs.CatchesFielding DESC, fs.Matches DESC, p.Name
                ');

            $rows = get_formatted_rows_from_query($statement);
            $rows_with_milestones = $this->_milestone_generator->join_milestones_to_player_rows(
                $rows, [ MS_TYPE_FIELDING ]
                );
            $this->generate_csv_output($output_name, $rows_with_milestones, $header);
        }

        private function generate_keeping_summary_csv($period_type)
        {
            $db = $this->_db;
            $current_season = $this->_config->getCurrentSeason();

            [ $table_name, $output_name ] = get_table_and_output_names(
                $period_type, "Keeping", "Fielding"
                );

            $header = array(
                "Player", "Mat", "Wk Ct", "St", "Wk Total", "Active", get_milestone_col_header($current_season)
                );

            $statement = $db->prepare(
               'SELECT
                      p.Name
                     ,fs.Matches
                     ,fs.CatchesKeeping
                     ,fs.Stumpings
                     ,fs.TotalKeepingWickets
                     ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                FROM Player p
                INNER JOIN ' . $table_name . ' fs on fs.PlayerId = p.PlayerId
                WHERE
                        fs.TotalKeepingWickets > 0
                ORDER by fs.TotalKeepingWickets DESC, fs.CatchesKeeping DESC, fs.Matches DESC, p.Name
                ');

            $rows = get_formatted_rows_from_query($statement);
            $rows_with_milestones = $this->_milestone_generator->join_milestones_to_player_rows(
                $rows, [ MS_TYPE_KEEPING ]
                );
            $this->generate_csv_output($output_name, $rows_with_milestones, $header);
        }

        // Private helpers
        private function generate_csv_output($output_name, $rows, $header = null)
        {
            $output_dir = $this->_config->getOutputDir();
            $out = fopen("$output_dir/$output_name.csv", "w");

            if ($header)
                \plough\fputcsv_eol($out, $header);

            foreach ($rows as $row)
                \plough\fputcsv_eol($out, $row);

            fclose($out);
        }

        private function generate_csv_output_from_query($output_name, $statement, $header = null)
        {
            $rows = get_formatted_rows_from_query($statement);
            $this->generate_csv_output($output_name, $rows, $header);
        }
    }
?>
