<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    // Helpers
    function get_milestone_col_header($season)
    {
        return $season . " Career Milestones";
    }

    function get_table_and_output_names(
        $period_type,
        $discipline_type,
        $season,
        $match_type,
        $table_prefix_override = null
        )
    {
        $table_prefix = (is_null($table_prefix_override) ? $discipline_type : $table_prefix_override);

        if ($period_type == PERIOD_CAREER)
        {
            return [
                "CareerMatchesSummary",
                "Career" . $table_prefix . "Summary",
                strtolower($discipline_type) . "_" . $season . "_career" . ($match_type === "Tour" ? "_tour" : "") . "_ind_summary"
                ];
        }
        else if ($period_type == PERIOD_SEASON)
        {
            return [
                "SeasonMatchesSummary",
                "Season" . $table_prefix . "Summary",
                strtolower($discipline_type) . "_" . $season . ($match_type === "Tour" ? "_tour" : "") . "_ind_summary"
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
            $this->generate_batting_summary_csvs(PERIOD_SEASON, $season);
            log\info("    Bowling");
            $this->generate_bowling_summary_csvs(PERIOD_SEASON, $season);
            log\info("    Fielding");
            $this->generate_fielding_summary_csvs(PERIOD_SEASON, $season);
            log\info("    Keeping");
            $this->generate_keeping_summary_csvs(PERIOD_SEASON, $season);
            log\info("    Partnerships");
            $this->generate_partnership_csvs(PERIOD_SEASON, $season);
        }

        public function generate_career_csv_files($season)
        {
            log\info("    Batting");
            $this->generate_batting_summary_csvs(PERIOD_CAREER, $season);
            log\info("    Bowling");
            $this->generate_bowling_summary_csvs(PERIOD_CAREER, $season);
            log\info("    Fielding");
            $this->generate_fielding_summary_csvs(PERIOD_CAREER, $season);
            log\info("    Keeping");
            $this->generate_keeping_summary_csvs(PERIOD_CAREER, $season);
            log\info("    Partnerships");
            $this->generate_partnership_csvs(PERIOD_CAREER, $season);
        }

        public function generate_other_csv_files($season)
        {
            log\info("    League tables for $season");
            $this->generate_league_table_csvs($season);
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

        private function generate_league_table_csvs($season)
        {
            $db = $this->_db;

            $header = array(
                "Team", "P", "A", "W", "L", "T", "Bonus", "Penalty", "Total", "Average"
                );

            $divisions = get_league_divisions_for_season($season);
            foreach ($divisions as $division)
            {
                $statement = $db->prepare(
                   'SELECT
                          Club
                         ,Played
                         ,Abandoned
                         ,Won
                         ,Lost
                         ,Tied
                         ,BonusPoints
                         ,PenaltyPoints
                         ,TotalPoints
                         ,AveragePoints
                    FROM LeagueTableEntry
                    WHERE
                            Season = :Season
                        AND Division = :Division
                    ORDER BY Position
                    ');
                $statement->bindValue(":Season", $season);
                $statement->bindValue(":Division", $division);

                $rows = get_formatted_rows_from_query($statement);
                $this->generate_csv_output("league_table_{$season}_div_{$division}", $rows, $header);
            }
        }

        private function generate_batting_summary_csvs($period_type, $season)
        {
            $db = $this->_db;

            $match_types = $period_type === PERIOD_CAREER ? ["Regular", "Tour"] : ["Regular"];
            foreach ($match_types as $match_type)
            {
                [ $matches_table_name, $table_name, $output_name ] = get_table_and_output_names(
                    $period_type, "Batting", $season, $match_type
                    );

                // Summary
                $include_milestones = $match_type === "Regular";
                $header = array(
                    "Player", "Mat", "Inns", "NO", "Runs", "Ave", "SR", "HS",
                    "50s", "100s", "0s", "4s", "6s", "Balls", "Active"
                    );
                if ($include_milestones)
                    array_push($header,  get_milestone_col_header($season));

                $statement = $db->prepare(
                    'SELECT
                        p.Name
                        ,ms.Matches
                        ,bs.Innings
                        ,bs.NotOuts
                        ,bs.Runs
                        ,bs.Average
                        ,bs.StrikeRate
                        ,CASE
                            WHEN bs.HighScoreMatchId IS NOT NULL THEN
                                "<a href=https://ploughmans.play-cricket.com/website/results/" || m.PcMatchId || ">"
                                || CAST(bs.HighScore AS TEXT) || (CASE bs.HighScoreNotOut WHEN 1 THEN \'*\' ELSE \'\' END) || "</a>"
                            ELSE
                                CAST(bs.HighScore AS TEXT) || (CASE bs.HighScoreNotOut WHEN 1 THEN \'*\' ELSE \'\' END)
                        END AS HighScore
                        ,bs.Fifties
                        ,bs.Hundreds
                        ,bs.Ducks
                        ,bs.Fours
                        ,bs.Sixes
                        ,bs.Balls
                        ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                    FROM Player p
                    INNER JOIN ' . $table_name . ' bs on bs.PlayerId = p.PlayerId
                    INNER JOIN ' . $matches_table_name . ' ms on ms.PlayerId = p.PlayerId
                    LEFT JOIN Match m on m.MatchId = bs.HighScoreMatchId
                    WHERE
                            bs.Innings > 0
                        AND bs.Season = :Season AND bs.MatchType = :MatchType
                        AND ms.Season = :Season AND ms.MatchType = :MatchType
                    ORDER by bs.Runs DESC, bs.Average DESC, bs.Innings DESC, bs.NotOuts DESC, ms.Matches DESC, p.Name
                    ');
                $statement->bindValue(":Season", $season);
                $statement->bindValue(":MatchType", $match_type);

                $rows = get_formatted_rows_from_query($statement);
                if ($include_milestones)
                    $rows = $this->_milestone_generator->join_milestones_to_player_rows(
                        $season, $rows, [ MS_TYPE_GENERAL, MS_TYPE_BATTING ]
                        );
                $this->generate_csv_output($output_name, $rows, $header);

                // Fifties and hundreds
                $header = array(
                    "Player", "Pos", "Runs", "Balls", "SR", "4s", "6s",
                    "Opposition", "Team", "Type", "Date"
                    );

                $competition_type_clause = "AND m.CompetitionType" . ($match_type === "Tour" ? "=" : "<>") . " 'Tour'";
                $season_clause = $period_type == PERIOD_SEASON ? "AND m.Season = " . $season : "AND m.Season > " . $this->_config->getCareerBaseSeason();
                $runs_clauses = array(
                    "50s" => "bp.Runs >= 50 AND bp.Runs < 100",
                    "100s" => "bp.Runs >= 100"
                    );
                foreach($runs_clauses as $runs_type => $runs_clause)
                {
                    $statement = $db->prepare(
                        'SELECT
                            p.Name
                            ,bp.Position
                            ,"<a href=https://ploughmans.play-cricket.com/website/results/" || m.PcMatchId || ">"
                                || CAST(bp.Runs AS TEXT) || (CASE WHEN bp.HowOut = "not out" || bp.HowOut = "retired hurt" || bp.HowOut = "retired not out" THEN \'*\' ELSE \'\' END) || "</a>"
                            ,bp.Balls
                            ,((CAST(bp.Runs AS FLOAT) / bp.Balls) * 100.0) AS StrikeRate
                            ,bp.Fours
                            ,bp.Sixes
                            ,CASE m.OppoClubName WHEN "" THEN m.OppoTeamName ELSE m.OppoClubName END
                            ,m.PloughTeamName
                            ,m.CompetitionType
                            ,STRFTIME("%d-%m-%Y", m.MatchDate)
                        FROM BattingPerformance bp
                        INNER JOIN PlayerPerformance pp on pp.PlayerPerformanceId = bp.PlayerPerformanceId
                        INNER JOIN Match m on m.MatchId = pp.MatchId
                        INNER JOIN Player p on p.PlayerId = bp.PlayerId
                        WHERE
                            ' . $runs_clause . '
                            ' . $season_clause . '
                            ' . $competition_type_clause . '
                        ORDER BY bp.Runs DESC, bp.Balls, p.Name
                        ');

                    $rows = get_formatted_rows_from_query($statement);
                    $this->generate_csv_output($output_name . "_" . $runs_type, $rows, $header);
                }
            }
        }

        private function generate_bowling_summary_csvs($period_type, $season)
        {
            $db = $this->_db;

            $match_types = $period_type === PERIOD_CAREER ? ["Regular", "Tour"] : ["Regular"];
            foreach ($match_types as $match_type)
            {
                [ $matches_table_name, $table_name, $output_name ] = get_table_and_output_names(
                    $period_type, "Bowling", $season, $match_type
                    );

                // Summary
                $include_milestones = $match_type === "Regular";
                $header = array(
                    "Player", "Mat", "Overs", "Mdns", "Runs", "Wkts", "Ave",
                    "Econ", "SR", "Best", "5wi", "Wides", "NBs", "Active"
                    );
                if ($include_milestones)
                    array_push($header,  get_milestone_col_header($season));

                $statement = $db->prepare(
                    'SELECT
                        p.Name
                        ,ms.Matches
                        ,(CAST(bs.CompletedOvers AS TEXT) || \'.\' || CAST(bs.PartialBalls AS TEXT)) as Overs
                        ,bs.Maidens
                        ,bs.Runs
                        ,bs.Wickets
                        ,bs.Average
                        ,bs.EconomyRate
                        ,bs.StrikeRate
                        ,CASE
                            WHEN bs.BestBowlingMatchId IS NOT NULL THEN
                                "<a href=https://ploughmans.play-cricket.com/website/results/" || m.PcMatchId || ">"
                                || CAST(bs.BestBowlingWickets AS TEXT) || \'/\' || CAST(bs.BestBowlingRuns AS TEXT) || "</a>"
                            ELSE
                                CAST(bs.BestBowlingWickets AS TEXT) || \'/\' || CAST(bs.BestBowlingRuns AS TEXT)
                        END AS BestBowling
                        ,bs.FiveFors
                        ,bs.Wides
                        ,bs.NoBalls
                        ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                    FROM Player p
                    INNER JOIN ' . $table_name . ' bs on bs.PlayerId = p.PlayerId
                    INNER JOIN ' . $matches_table_name . ' ms on ms.PlayerId = p.PlayerId
                    LEFT JOIN Match m on m.MatchId = bs.BestBowlingMatchId
                    WHERE
                            (bs.CompletedOvers > 0 OR bs.PartialBalls > 0)
                        AND bs.Season = :Season AND bs.MatchType = :MatchType
                        AND ms.Season = :Season AND ms.MatchType = :MatchType
                    ORDER by bs.Wickets DESC, bs.Average, bs.EconomyRate
                    ');
                $statement->bindValue(":Season", $season);
                $statement->bindValue(":MatchType", $match_type);

                $rows = get_formatted_rows_from_query($statement);
                if ($include_milestones)
                    $rows = $this->_milestone_generator->join_milestones_to_player_rows(
                        $season, $rows, [ MS_TYPE_GENERAL, MS_TYPE_BOWLING ]
                        );
                $this->generate_csv_output($output_name, $rows, $header);

                // Five-fors
                $header = array(
                    "Player", "Figures", "Overs", "Mdns", "Runs", "Wkts", "Econ", "SR",
                    "Opposition", "Team", "Type", "Date"
                    );

                $competition_type_clause = "AND m.CompetitionType" . ($match_type === "Tour" ? "=" : "<>") . " 'Tour'";
                $season_clause = $period_type == PERIOD_SEASON ? "AND m.Season = " . $season : "AND m.Season > " . $this->_config->getCareerBaseSeason();
                $statement = $db->prepare(
                    'SELECT
                        p.Name
                        ,"<a href=https://ploughmans.play-cricket.com/website/results/" || m.PcMatchId || ">"
                            || CAST(bp.Wickets AS TEXT) || "/" || CAST(bp.Runs AS TEXT) || "</a>"
                        ,bp.CompletedOvers || (CASE WHEN bp.PartialBalls > 0 THEN "." || bp.PartialBalls ELSE "" END)
                        ,bp.Maidens
                        ,bp.Runs
                        ,bp.Wickets
                        ,(bp.Runs / ((bp.CompletedOvers * 6 + bp.PartialBalls) / 6.0)) AS EconomyRate
                        ,(CAST((bp.CompletedOvers * 6 + bp.PartialBalls) AS FLOAT) / bp.Wickets) AS StrikeRate
                        ,CASE m.OppoClubName WHEN "" THEN m.OppoTeamName ELSE m.OppoClubName END
                        ,m.PloughTeamName
                        ,m.CompetitionType
                        ,STRFTIME("%d-%m-%Y", m.MatchDate)
                    FROM BowlingPerformance bp
                    INNER JOIN PlayerPerformance pp on pp.PlayerPerformanceId = bp.PlayerPerformanceId
                    INNER JOIN Match m on m.MatchId = pp.MatchId
                    INNER JOIN Player p on p.PlayerId = bp.PlayerId
                    WHERE
                        bp.Wickets >= 5
                        ' . $season_clause . '
                        ' . $competition_type_clause . '
                    ORDER BY bp.Wickets DESC, bp.Runs, p.Name
                    ');

                $rows = get_formatted_rows_from_query($statement);
                $this->generate_csv_output($output_name . "_5fors", $rows, $header);
            }
        }

        private function generate_fielding_summary_csvs($period_type, $season)
        {
            $db = $this->_db;

            $match_types = $period_type === PERIOD_CAREER ? ["Regular", "Tour"] : ["Regular"];
            foreach ($match_types as $match_type)
            {
                [ $matches_table_name, $table_name, $output_name ] = get_table_and_output_names(
                    $period_type, "Fielding", $season, $match_type
                    );

                $include_milestones = $match_type === "Regular";
                $header = array(
                    "Player", "Mat", "Ct", "RO", "Total", "Active"
                    );
                if ($include_milestones)
                    array_push($header, get_milestone_col_header($season));

                $matches_field_name = $period_type == PERIOD_SEASON ? "MatchesFielding" : "Matches";
                $statement = $db->prepare(
                'SELECT
                        p.Name
                        ,ms.' . $matches_field_name . '
                        ,fs.CatchesFielding
                        ,fs.RunOuts
                        ,fs.TotalFieldingWickets
                        ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                    FROM Player p
                    INNER JOIN ' . $table_name . ' fs on fs.PlayerId = p.PlayerId
                    INNER JOIN ' . $matches_table_name . ' ms on ms.PlayerId = p.PlayerId
                    WHERE
                            fs.TotalFieldingWickets > 0
                        AND fs.Season = :Season AND fs.MatchType = :MatchType
                        AND ms.Season = :Season AND ms.MatchType = :MatchType
                    ORDER by fs.TotalFieldingWickets DESC, fs.CatchesFielding DESC, ms.Matches DESC, p.Name
                    ');
                $statement->bindValue(":Season", $season);
                $statement->bindValue(":MatchType", $match_type);

                $rows = get_formatted_rows_from_query($statement);
                if ($include_milestones)
                    $rows = $this->_milestone_generator->join_milestones_to_player_rows(
                        $season, $rows, [ MS_TYPE_FIELDING ]
                        );
                $this->generate_csv_output($output_name, $rows, $header);
            }
        }

        private function generate_keeping_summary_csvs($period_type, $season)
        {
            $db = $this->_db;

            $match_types = $period_type === PERIOD_CAREER ? ["Regular", "Tour"] : ["Regular"];
            foreach ($match_types as $match_type)
            {
                [ $matches_table_name, $table_name, $output_name ] = get_table_and_output_names(
                    $period_type, "Keeping", $season, $match_type, "Fielding"
                    );

                $include_milestones = $match_type === "Regular";
                $header = array(
                    "Player", "Mat", "Wk Ct", "St", "Wk Total", "Active"
                    );
                if ($include_milestones)
                    array_push($header, get_milestone_col_header($season));

                $matches_field_name = $period_type == PERIOD_SEASON ? "MatchesKeeping" : "Matches";
                $filter_clause = $period_type == PERIOD_SEASON ? "ms.MatchesKeeping > 0" : "fs.TotalKeepingWickets > 0";
                $statement = $db->prepare(
                'SELECT
                        p.Name
                        ,ms.' . $matches_field_name . '
                        ,fs.CatchesKeeping
                        ,fs.Stumpings
                        ,fs.TotalKeepingWickets
                        ,CASE p.Active WHEN 1 THEN "Y" ELSE "N" END AS Active
                    FROM Player p
                    INNER JOIN ' . $table_name . ' fs on fs.PlayerId = p.PlayerId
                    INNER JOIN ' . $matches_table_name . ' ms on ms.PlayerId = p.PlayerId
                    WHERE
                            ' . $filter_clause . '
                        AND fs.Season = :Season AND fs.MatchType = :MatchType
                        AND ms.Season = :Season AND ms.MatchType = :MatchType
                    ORDER by fs.TotalKeepingWickets DESC, fs.CatchesKeeping DESC, ms.Matches DESC, p.Name
                    ');
                $statement->bindValue(":Season", $season);
                $statement->bindValue(":MatchType", $match_type);

                $rows = get_formatted_rows_from_query($statement);
                if ($include_milestones)
                    $rows = $this->_milestone_generator->join_milestones_to_player_rows(
                        $season, $rows, [ MS_TYPE_KEEPING ]
                        );
                $this->generate_csv_output($output_name, $rows, $header);
            }
        }

        private function generate_partnership_csvs($period_type, $season)
        {
            $match_types = $period_type === PERIOD_CAREER ? ["Regular", "Tour"] : ["Regular"];
            foreach ($match_types as $match_type)
            {
                $header = array(
                    "Wicket", "Runs", "Batter 1", "Score", "Batter 2", "Score",
                    "Opposition", "Team", "Type", "Date"
                    );

                $output_prefix = "batting_partnerships_" . $season . "_" . ($period_type === PERIOD_CAREER ? "career_" : "")
                                 . ($match_type === "Tour" ? "tour_" : "");

                // Top partnerships for any wicket
                $num_all_wickets = $period_type === PERIOD_CAREER ? 50 : 25;
                $this->generate_csv_output(
                    $output_prefix . "all", 
                    $this->get_partnership_rows($period_type, $season, $match_type, NULL, $num_all_wickets), 
                    $header
                    );

                $best_per_wicket_rows = array();
                $per_wicket_rows = array();
                $num_per_wicket = $period_type === PERIOD_CAREER ? 20 : 10;
                foreach (range(1, 10) as $wicket)
                {
                    // Get rows for top X for this wicket and append to overall per-wicket list
                    $rows_this_wicket = $this->get_partnership_rows($period_type, $season, $match_type, $wicket, $num_per_wicket);
                    $per_wicket_rows = array_merge($per_wicket_rows, $rows_this_wicket);

                    // Get best partnership(s) for each wicket
                    if (count($rows_this_wicket) > 0)
                    {
                        $best_runs = strip_link_html($rows_this_wicket[0][1]);
                        foreach ($rows_this_wicket as $row)
                        {
                            $runs_this_row = strip_link_html($row[1]);
                            if ($runs_this_row === $best_runs)
                                array_push($best_per_wicket_rows, $row);
                            else
                                break;
                        }
                    }
                }

                $this->generate_csv_output($output_prefix . "wickets", $per_wicket_rows, $header);
                $this->generate_csv_output($output_prefix . "best", $best_per_wicket_rows, $header);
            }
        }

        private function get_partnership_rows($period_type, $season, $match_type, $wicket, $top_n)
        {
            $db = $this->_db;

            $wicket_clause = is_null($wicket) ? "" : "AND part.Wicket = " . $wicket;
            $season_clause = $period_type == PERIOD_CAREER ? "AND m.Season <= " . $season : "AND m.Season = " . $season;
            $competition_type_clause = "AND m.CompetitionType" . ($match_type === "Tour" ? "=" : "<>") . " 'Tour'";
            $limit_clause = is_null($top_n) ? "" : "LIMIT " . $top_n;
            $statement = $db->prepare(
                'SELECT
                     CASE part.Wicket
                         WHEN 1 THEN "1st"
                         WHEN 2 THEN "2nd"
                         WHEN 3 THEN "3rd"
                         ELSE (CAST(part.Wicket AS TEXT) || "th")
                     END
                    ,CASE
                        WHEN m.PcMatchId IS NOT NULL THEN
                            "<a href=https://ploughmans.play-cricket.com/website/results/" || m.PcMatchId || ">"
                            || (CAST(part.Runs AS TEXT) || CASE part.NotOut WHEN 1 THEN "*" ELSE "" END) || "</a>"
                        ELSE
                            CAST(part.Runs AS TEXT) || CASE part.NotOut WHEN 1 THEN "*" ELSE "" END
                    END
                    ,CASE WHEN bpi.Position < bpo.Position THEN pi.Name ELSE po.Name END
                    ,CASE
                        WHEN bpi.Position < bpo.Position THEN
                            CAST(bpi.Runs AS TEXT) || (CASE bpi.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)
                        ELSE
                            CAST(bpo.Runs AS TEXT) || (CASE bpo.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)
                    END
                    ,CASE WHEN bpi.Position < bpo.Position THEN po.Name ELSE pi.Name END
                    ,CASE
                        WHEN bpi.Position < bpo.Position THEN
                            CAST(bpo.Runs AS TEXT) || (CASE bpo.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)
                        ELSE
                            CAST(bpi.Runs AS TEXT) || (CASE bpi.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)
                    END
                    ,CASE m.OppoClubName WHEN "" THEN m.OppoTeamName ELSE m.OppoClubName END
                    ,m.PloughTeamName
                    ,m.CompetitionType
                    ,STRFTIME("%d-%m-%Y", m.MatchDate)
                FROM BattingPartnership part
                INNER JOIN BattingPerformance bpo ON bpo.BattingPerformanceId = part.BattingPerformanceIdOut
                INNER JOIN Player po ON po.PlayerId = bpo.PlayerId
                INNER JOIN BattingPerformance bpi ON bpi.BattingPerformanceId = part.BattingPerformanceIdIn
                INNER JOIN Player pi ON pi.PlayerId = bpi.PlayerId
                INNER JOIN PlayerPerformance ppo ON ppo.PlayerPerformanceId = bpo.PlayerPerformanceId
                INNER JOIN Match m ON m.MatchId = ppo.MatchId
                WHERE 1=1
                    ' . $wicket_clause . '
                    ' . $season_clause . '
                    ' . $competition_type_clause . '
                ORDER BY part.Runs DESC, part.NotOut DESC, m.MatchDate ASC
                ' . $limit_clause . '
                ');
                return get_formatted_rows_from_query($statement);
        }

        // Private helpers
        private function generate_csv_output($output_name, $rows, $header = null)
        {
            $output_dir = $this->_config->getOutputDir();
            $out = fopen("$output_dir/$output_name.csv", "w");

            if ($header)
                fputcsv($out, $header);

            foreach ($rows as $row)
                fputcsv($out, $row);

            fclose($out);
        }
    }
?>
