<?php
	namespace plough\stats;
    
    require_once("config.php");
	require_once("data-mapper.php");
    require_once("db.php");
    require_once("utils.php");

    // Constants
    const CLUB_NAME = "Ploughmans CC";
    const DELETED = "Deleted";
    
    // Modes of dismissal
    const DID_NOT_BAT = "did not bat";
	const NOT_OUT = "no";
    const CAUGHT = "ct";
    const RUN_OUT = "ro";
    const STUMPED = "st";
    
    class Updater
    {
        // Properties
        private $_config;
        
        // Public methods
        public function __construct(Config $config)
        {
            $this->_config = $config;
        }
        
        public function update_stats()
        {
            // Config
            $output_dir = $this->_config->getOutputDir();
            if (!file_exists($output_dir))
                mkdir($output_dir);
            
            if ($this->_config->dumpInputs())
            {
                $dump_dir = $this->_config->getInputDumpDir();
                $dump_data_mapper = $this->_config->getInputDumpDataMapper();
                
                if (!file_exists($dump_dir))
                    mkdir($dump_dir);
            }
            
            $input_mapper = $this->_config->getInputDataMapper();
         
            $db_path = $output_dir . "/stats_db.sqlite";
            if ($this->_config->clearDb())
            {
                // Delete previous database
                unlink($db_path);
            }
            
            // Open database and create schema if required
            $db = new \SQLite3($db_path);
            db_create_schema($db); 
            
            // Prepare statements
            $insert_match = db_create_insert_match($db);
            $insert_player = db_create_insert_player($db);
            $insert_player_perf = db_create_insert_player_performance($db);
            $insert_batting_perf = db_create_insert_batting_performance($db);
            $insert_bowling_perf = db_create_insert_bowling_performance($db);
            $insert_fielding_perf = db_create_insert_fielding_performance($db);
            
            // Set up match and player cache
            $match_cache = array();
            $player_cache = array();
            
            // Get match list
            echo "Fetching match list..." . PHP_EOL;
            $season = "2018";
            $matches_from_date = "01/01/2018";
            $matches_path = $input_mapper->getMatchesPath($season, $matches_from_date);
            $matches_str = file_get_contents($matches_path);
        
            if ($this->_config->dumpInputs())
                file_put_contents($dump_data_mapper->getMatchesPath($season, $matches_from_date), $matches_str);
            
            $matches = json_decode($matches_str, true)["matches"];
            $num_matches = count($matches);
            echo "  $num_matches matches found" . PHP_EOL . PHP_EOL;
            
            echo "Fetching match details..." . PHP_EOL;
            foreach ($matches as $match_idx => $match)
            {
                // Player performance cache for this match
                $player_perf_cache = array();
                $pc_match_id = $match["id"];
                
                echo "  Processing match $match_idx (Play-Cricket id $pc_match_id)..." . PHP_EOL;
                
                // Get match detail
                $match_detail_path = $input_mapper->getMatchDetailPath($pc_match_id);
                $match_detail_str = file_get_contents($match_detail_path);
                
                if ($this->_config->dumpInputs())
                    file_put_contents($dump_data_mapper->getMatchDetailPath($pc_match_id), $match_detail_str);
                
                $match_detail = json_decode($match_detail_str, true)["match_details"][0];
                
                if ($match_detail["status"] == DELETED)
                {
                    echo "    Skipping match because it was deleted..." . PHP_EOL;
                }
                else if (empty($match_detail["result"]))
                {
                    echo "    Skipping match because it is a future fixture..." . PHP_EOL;
                }
                else
                {
                    // Start transaction for whole of match
                    $db->exec('BEGIN');
                    
                    // Determine home team and get team info
                    if ($match_detail["home_club_name"] == CLUB_NAME)
                    {
                        $home_match = 1;
                        $plough_team = $match_detail["home_team_name"];
                        $plough_team_id = $match_detail["home_team_id"];
                        $oppo_club = $match_detail["away_club_name"];
                        $oppo_team = $match_detail["away_team_name"];
                        $oppo_team_id = $match_detail["away_team_id"];
                        $players = $match_detail["players"][0]["home_team"];
                    }
                    else
                    {
                        $home_match = 0;
                        $plough_team = $match_detail["away_team_name"];
                        $plough_team_id = $match_detail["away_team_id"];
                        $oppo_club = $match_detail["home_club_name"];
                        $oppo_team = $match_detail["home_team_name"];
                        $oppo_team_id = $match_detail["home_team_id"];
                        $players = $match_detail["players"][1]["away_team"];
                    }
                    
                    // Insert match
                    $insert_match->bindValue(":pc_match_id", $pc_match_id);
                    $insert_match->bindValue(":status", $match_detail["status"]);
                    $insert_match->bindValue(":match_date", $match_detail["match_date"]);
                    $insert_match->bindValue(":plough_team", $plough_team);
                    $insert_match->bindValue(":plough_team_id", $plough_team_id);
                    $insert_match->bindValue(":oppo_club", $oppo_club);
                    $insert_match->bindValue(":oppo_team", $oppo_team);
                    $insert_match->bindValue(":oppo_team_id", $oppo_team_id);
                    $insert_match->bindValue(":home", $home_match);
                    $insert_match->bindValue(":result", $match_detail["result"]);
                    $match_id = db_insert_and_return_id($db, $insert_match);
                    $match_cache[$pc_match_id] = $match_id;
                    
                    foreach ($players as $player)
                    {
                        $pc_player_id = $player["player_id"];
                        
                        // Insert player
                        if (!array_key_exists($pc_player_id, $player_cache))
                        {
                            $insert_player->bindValue(":pc_player_id", $pc_player_id);
                            $insert_player->bindValue(":name", $player["player_name"]);
                            $player_id = db_insert_and_return_id($db, $insert_player);
                            $player_cache[$pc_player_id] = $player_id;
                        }
                        
                        // Insert player performance
                        $player_id = $player_cache[$pc_player_id];
                        $insert_player_perf->bindValue(":match_id", $match_id);
                        $insert_player_perf->bindValue(":player_id", $player_id);
                        $insert_player_perf->bindValue(":captain", int_from_bool($player["captain"]));
                        $insert_player_perf->bindValue(":wicketkeeper", int_from_bool($player["wicket_keeper"]));
                        $player_perf_id = db_insert_and_return_id($db, $insert_player_perf);
                        $player_perf_cache[$pc_player_id] = $player_perf_id;
                    }
                    
                    $innings = $match_detail["innings"];
                    foreach ($innings as $inning_idx => $inning)
                    {
                        if ($inning["team_batting_id"] == $plough_team_id)
                        {
                            // Plough batting
                            $batting_perfs = $inning["bat"];
                            foreach ($batting_perfs as $batting_perf_idx => $batting_perf)
                            {
                                $pc_player_id = $batting_perf["batsman_id"];
                                
                                if (!empty($pc_player_id))
                                {
                                    $player_id = $player_cache[$pc_player_id];
                                    $player_perf_id = $player_perf_cache[$pc_player_id];
                                    
                                    $how_out = $batting_perf["how_out"];
                                    if ($how_out != DID_NOT_BAT)
                                    {
                                        $insert_batting_perf->bindValue(":player_perf_id", $player_perf_id);
                                        $insert_batting_perf->bindValue(":player_id", $player_id);
                                        $insert_batting_perf->bindValue(":position", $batting_perf["position"]);
                                        $insert_batting_perf->bindValue(":how_out", $how_out);
                                        $insert_batting_perf->bindValue(":runs", $batting_perf["runs"]);
                                        $insert_batting_perf->bindValue(":balls", $batting_perf["balls"]);
                                        $insert_batting_perf->bindValue(":fours", $batting_perf["fours"]);
                                        $insert_batting_perf->bindValue(":sixes", $batting_perf["sixes"]);
                                        $insert_batting_perf->execute();
                                    }
                                }
                            }
                        }
                        else
                        {
                            // Plough bowling
                            $bowling_perfs = $inning["bowl"];
                            foreach ($bowling_perfs as $bowling_perf_idx => $bowling_perf)
                            {
                                $pc_player_id = $bowling_perf["bowler_id"];
                                $player_id = $player_cache[$pc_player_id];
                                $player_perf_id = $player_perf_cache[$pc_player_id];
                                
                                // Handle full and partial overs
                                $over_parts = explode(".", $bowling_perf["overs"]);
                                $completed_overs = $over_parts[0];
                                if (count($over_parts) > 1)
                                    $partial_balls = $over_parts[1];
                                else
                                    $partial_balls = 0;
                                
                                $insert_bowling_perf->bindValue(":player_perf_id", $player_perf_id);
                                $insert_bowling_perf->bindValue(":player_id", $player_id);
                                $insert_bowling_perf->bindValue(":position", $bowling_perf_idx + 1);
                                $insert_bowling_perf->bindValue(":completed_overs", $completed_overs);
                                $insert_bowling_perf->bindValue(":partial_balls", $partial_balls);
                                $insert_bowling_perf->bindValue(":maidens", $bowling_perf["maidens"]);
                                $insert_bowling_perf->bindValue(":runs", $bowling_perf["runs"]);
                                $insert_bowling_perf->bindValue(":wickets", $bowling_perf["wickets"]);
                                $insert_bowling_perf->bindValue(":wides", $bowling_perf["wides"]);
                                $insert_bowling_perf->bindValue(":no_balls", $bowling_perf["no_balls"]);
                                $insert_bowling_perf->execute();
                            }
                            
                            // Plough fielding
                            $player_to_fielding = array();
                            
                            $batting_perfs = $inning["bat"];
                            foreach ($batting_perfs as $batting_perf_idx => $batting_perf)
                            {
                                $pc_player_id = $batting_perf["fielder_id"];
                                if (!empty($pc_player_id))
                                {
                                    if (!array_key_exists($pc_player_id, $player_to_fielding))
                                    {
                                        $player_to_fielding[$pc_player_id] = array(
                                            "catches" => 0,
                                            "run_outs" => 0,
                                            "stumpings" => 0
                                            );
                                    }
                                    
                                    $how_out = $batting_perf["how_out"];
                                    if ($how_out == CAUGHT)
                                        $player_to_fielding[$pc_player_id]["catches"]++;
                                    else if ($how_out == RUN_OUT)
                                        $player_to_fielding[$pc_player_id]["run_outs"]++;
                                    else if ($how_out == STUMPED)
                                        $player_to_fielding[$pc_player_id]["stumpings"]++;
                                }
                            }
                            
                            foreach($player_to_fielding as $pc_player_id => $fielding)
                            {
                                $player_id = $player_cache[$pc_player_id];
                                $player_perf_id = $player_perf_cache[$pc_player_id];
                                $insert_fielding_perf->bindValue(":player_perf_id", $player_perf_id);
                                $insert_fielding_perf->bindValue(":player_id", $player_id);
                                $insert_fielding_perf->bindValue(":catches", $fielding["catches"]);
                                $insert_fielding_perf->bindValue(":run_outs", $fielding["run_outs"]);
                                $insert_fielding_perf->bindValue(":stumpings", $fielding["stumpings"]);
                                $insert_fielding_perf->execute();
                            }
                        }
                    }
                    
                    // End transaction for whole of match
                    $db->exec('COMMIT');
                }
            }
            
            // Build summaries
            echo PHP_EOL . "Building summary tables..." . PHP_EOL;
            $players = array();
            $statement = $db->prepare('SELECT PlayerId FROM "Player" ORDER BY PlayerId');
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
                array_push($players, $row["PlayerId"]);
            
            echo "  Batting" . PHP_EOL;
            $this->generate_batting_summary($players, $db);
            echo "  Bowling" . PHP_EOL;
            $this->generate_bowling_summary($players, $db);
            echo "  Fielding" . PHP_EOL;
            $this->generate_fielding_summary($players, $db);
            
            // Generate outputs
            echo PHP_EOL . "Generating CSV output..." . PHP_EOL;
            echo "  Batting" . PHP_EOL;
            $this->generate_batting_summary_csv($db);
            echo "  Bowling" . PHP_EOL;
            $this->generate_bowling_summary_csv($db);
            echo "  Fielding" . PHP_EOL;
            $this->generate_fielding_summary_csv($db);
            echo "  Keeping" . PHP_EOL;
            $this->generate_keeping_summary_csv($db);
        }
        
        // Private helpers
        private function generate_csv_output($output_name, $header, $statement)
        {
            $output_dir = $this->_config->getOutputDir();
            $out = fopen("$output_dir/$output_name.csv", "w");
            fputcsv($out, $header);
            
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
            {
                $formatted_row = array();
                foreach ($row as $key => $value)
                {
                    $formatted_value = $value;
                    
                    if (is_float($value))
                        $formatted_value = sprintf("%.2f", $value);
                    
                    array_push($formatted_row, $formatted_value);
                }
                
                fputcsv($out, $formatted_row);
            }
            fclose($out);
        }
        
        private function generate_batting_summary($players, $db)
        {
            $insert_batting_summary = db_create_insert_batting_summary($db);
            
            foreach ($players as $player_id)
            {	
                // Filter
                $db->query('DROP TABLE IF EXISTS "IncludedPerformance"');
                $db->query('CREATE TEMPORARY TABLE "IncludedPerformance" (
                    "PlayerPerformanceId" INTEGER PRIMARY KEY
                    )');
                $db->query(
                    'INSERT INTO "IncludedPerformance"
                     SELECT pp.PlayerPerformanceId FROM "PlayerPerformance" pp
                     INNER JOIN "BattingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                     WHERE
                            bp.Position in (8)
                    ');

                // Basic fields
                $statement = $db->prepare('               
                    SELECT
                         p.PlayerId as player_id
                        ,COUNT(pp.PlayerPerformanceId) AS matches
                        ,COUNT(bp.BattingPerformanceId) AS innings
                        ,SUM(CASE bp.HowOut WHEN "no" THEN 1 ELSE 0 END) AS not_outs
                        ,SUM(bp.Runs) AS runs
                        ,(CAST(SUM(bp.Runs) AS FLOAT) / (COUNT(bp.BattingPerformanceId) - SUM(CASE bp.HowOut WHEN "no" THEN 1 ELSE 0 END))) AS average
                        ,((CAST(SUM(bp.Runs) AS FLOAT) / SUM(bp.Balls)) * 100.0) AS strike_rate
                        ,SUM(CASE WHEN bp.Runs >= 50 AND bp.Runs < 100 THEN 1 ELSE 0 END) AS fifties
                        ,SUM(CASE WHEN bp.Runs >= 100 THEN 1 ELSE 0 END) AS hundreds
                        ,SUM(CASE WHEN bp.Runs = 0 and bp.HowOut <> "no" THEN 1 ELSE 0 END) AS ducks
                        ,SUM(bp.Balls) as balls
                        ,SUM(bp.Fours) as fours
                        ,SUM(bp.Sixes) as sixes
                    FROM "Player" p
                    INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
                    --INNER JOIN "IncludedPerformance" ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN "BattingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :player_id
                    GROUP BY p.PlayerId, p.Name
                    '
                    );
                $statement->bindValue(":player_id", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;
                
                db_bind_values_from_row($insert_batting_summary, $result);
            
                // High score
                $statement = $db->prepare('
                    SELECT
                         p.PlayerId as player_id
                        ,bp.Runs as high_score
                        ,(CASE bp.HowOut WHEN "no" THEN 1 ELSE 0 END) as high_score_not_out
                    FROM "Player" p
                    LEFT JOIN "BattingPerformance" bp on bp.PlayerId = p.PlayerId
                    WHERE
                            p.PlayerId = :player_id
                    ORDER BY high_score DESC, high_score_not_out DESC
                    LIMIT 1
                    ');
                $statement->bindValue(":player_id", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                db_bind_values_from_row($insert_batting_summary, $result);
            
                // Insert
                $insert_batting_summary->execute();
            }
        }
            
        private function generate_batting_summary_csv($db)
        {
            $header = array(
                "Player", "Matches", "Innings", "Not Outs", "Runs", "Average", "Strike Rate", 
                "High Score", "50s", "100s", "Ducks", "Fours", "Sixes"
                );
            
            $statement = $db->prepare('
                SELECT
                      p.Name
                     ,bs.Matches
                     ,bs.Innings
                     ,bs.NotOuts
                     ,bs.Runs
                     ,bs.Average
                     ,bs.StrikeRate
                     ,(CAST(bs.HighScore AS TEXT) || CASE bs.HighScoreNotOut WHEN 1 THEN \'*\' ELSE \'\' END)
                     ,bs.Fifties
                     ,bs.Hundreds
                     ,bs.Ducks
                     ,bs.Fours
                     ,bs.Sixes
                FROM "Player" p
                INNER JOIN "BattingSummary" bs on bs.PlayerId = p.PlayerId
                WHERE bs.Innings > 0
                ORDER by bs.Runs DESC, bs.Average DESC
                '
                );
            
            $this->generate_csv_output("batting_ind_summary", $header, $statement);
        }
            
        private function generate_bowling_summary($players, $db)
        {
            $insert_bowling_summary = db_create_insert_bowling_summary($db);
            
            foreach ($players as $player_id)
            {
                // Filter
                $db->query('DROP TABLE IF EXISTS "IncludedPerformance"');
                $db->query('CREATE TEMPORARY TABLE "IncludedPerformance" (
                    "PlayerPerformanceId" INTEGER PRIMARY KEY
                    )');
                $db->query(
                    'INSERT INTO "IncludedPerformance"
                     SELECT pp.PlayerPerformanceId FROM "PlayerPerformance" pp
                     INNER JOIN "BowlingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                     WHERE
                            bp.Position in (1, 2)
                    ');
                
                // Basic fields
                $statement = $db->prepare('               
                    SELECT
                         p.PlayerId as player_id
                        ,COUNT(pp.PlayerPerformanceId) AS matches
                        ,SUM(bp.Maidens) as maidens
                        ,SUM(bp.Runs) AS runs
                        ,SUM(bp.Wickets) AS wickets
                        ,(CAST(SUM(bp.Runs) AS FLOAT) / (SUM(bp.Wickets))) AS average
                        ,SUM(CASE WHEN bp.Wickets >= 5 THEN 1 ELSE 0 END) AS five_fors
                        ,SUM(bp.Wides) as wides
                        ,SUM(bp.NoBalls) as no_balls
                    FROM "Player" p
                    INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
                    --INNER JOIN "IncludedPerformance" ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN "BowlingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :player_id
                    GROUP BY p.PlayerId, p.Name
                    '
                    );
                $statement->bindValue(":player_id", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;
                
                $runs = $result["runs"];
                $wickets = $result["wickets"];
                db_bind_values_from_row($insert_bowling_summary, $result);
        
                // Overs, balls, economy rate, strike rate
                $statement = $db->prepare('
                    SELECT
                         p.PlayerId
                        ,SUM(bp.CompletedOvers) as completed_overs
                        ,SUM(bp.PartialBalls) as partial_balls
                    FROM "Player" p
                    JOIN "BowlingPerformance" bp on bp.PlayerId = p.PlayerId
                    WHERE
                        p.PlayerId = :player_id
                    ');
                $statement->bindValue(":player_id", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                $partial_overs = floor($result["partial_balls"] / 6);
                $partial_balls = $result["partial_balls"] % 6;
                $completed_overs = $result["completed_overs"] + $partial_overs;
                $total_balls = $completed_overs * 6 + $partial_balls;
                
                $economy_rate = NULL;
                if ($total_balls > 0)
                    $economy_rate = $runs / ($total_balls / 6.0);
                $strike_rate = NULL;
                if ($wickets > 0)
                    $strike_rate = $total_balls / $wickets;
                $insert_bowling_summary->bindValue(":completed_overs", $completed_overs);
                $insert_bowling_summary->bindValue(":partial_balls", $partial_balls);
                $insert_bowling_summary->bindValue(":economy_rate", $economy_rate);
                $insert_bowling_summary->bindValue(":strike_rate", $strike_rate);
        
                // Best bowling
                $statement = $db->prepare('
                    SELECT
                         p.PlayerId as player_id
                        ,bp.Wickets as best_bowling_wickets
                        ,bp.Runs as best_bowling_runs
                    FROM "Player" p
                    LEFT JOIN "BowlingPerformance" bp on bp.PlayerId = p.PlayerId
                    WHERE
                            p.PlayerId = :player_id
                    ORDER BY best_bowling_wickets DESC, best_bowling_runs ASC
                    LIMIT 1
                    ');
                $statement->bindValue(":player_id", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                db_bind_values_from_row($insert_bowling_summary, $result);
        
                // Insert
                $insert_bowling_summary->execute();
            }
        }
        
        private function generate_bowling_summary_csv($db)
        {
            $header = array(
                "Player", "Matches", "Overs", "Maidens", "Runs", "Wickets", "Average", 
                "Economy Rate", "Strike Rate", "Best Bowling", "5wi", "Wides", "No Balls"
                );
            
            $statement = $db->prepare('
                SELECT
                      p.Name
                     ,bs.Matches
                     ,(CAST(bs.CompletedOvers AS TEXT) || \'.\' || CAST(bs.PartialBalls AS TEXT)) 
                     ,bs.Maidens
                     ,bs.Runs
                     ,bs.Wickets
                     ,bs.Average
                     ,bs.EconomyRate
                     ,bs.StrikeRate
                     ,(CAST(bs.BestBowlingWickets AS TEXT) || \'/\' || CAST(bs.BestBowlingRuns AS TEXT))
                     ,bs.FiveFors
                     ,bs.Wides
                     ,bs.NoBalls
                FROM "Player" p
                INNER JOIN "BowlingSummary" bs on bs.PlayerId = p.PlayerId
                WHERE (bs.CompletedOvers > 0 OR bs.PartialBalls > 0)
                ORDER by bs.Wickets DESC, bs.Average
                '
                );
            
            $this->generate_csv_output("bowling_ind_summary", $header, $statement);
        }
        
        private function generate_fielding_summary($players, $db)
        {
            $insert_fielding_summary = db_create_insert_fielding_summary($db);
            
            foreach ($players as $player_id)
            {	
                // Filter
                

                // Basic fields
                $statement = $db->prepare('               
                    SELECT
                         p.PlayerId as player_id
                        ,COUNT(pp.PlayerPerformanceId) AS matches
                        ,SUM(CASE WHEN pp.Wicketkeeper = 0 THEN fp.Catches ELSE 0 END) as catches_fielding
                        ,SUM(fp.RunOuts) as run_outs
                        ,SUM(CASE WHEN pp.Wicketkeeper = 1 THEN fp.Catches ELSE 0 END) as catches_keeping
                        ,SUM(fp.Stumpings) as stumpings
                    FROM "Player" p
                    INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
                    LEFT JOIN "FieldingPerformance" fp on fp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :player_id
                    GROUP BY p.PlayerId, p.Name
                    '
                    );
                $statement->bindValue(":player_id", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;
                db_bind_values_from_row($insert_fielding_summary, $result);
                
                // Totals
                $total_fielding = $result["catches_fielding"] + $result["run_outs"];
                $insert_fielding_summary->bindValue("total_fielding_wickets", $total_fielding);
                $total_keeping = $result["catches_keeping"] + $result["stumpings"];
                $insert_fielding_summary->bindValue("total_keeping_wickets", $total_keeping);
            
                // Insert
                $insert_fielding_summary->execute();
            }
        }
        
        private function generate_fielding_summary_csv($db)
        {
            $header = array(
                "Player", "Matches", "Fielding Catches", "Run Outs", "Fielding Dismissals"
                );
            
            $statement = $db->prepare('
                SELECT
                      p.Name
                     ,fs.Matches
                     ,fs.CatchesFielding
                     ,fs.RunOuts
                     ,fs.TotalFieldingWickets
                FROM "Player" p
                INNER JOIN "FieldingSummary" fs on fs.PlayerId = p.PlayerId
                WHERE fs.TotalFieldingWickets > 0
                ORDER by fs.TotalFieldingWickets DESC, fs.CatchesFielding DESC
                '
                );
            
            $this->generate_csv_output("fielding_ind_summary", $header, $statement);
        }
        
        private function generate_keeping_summary_csv($db)
        {
            $header = array(
                "Player", "Matches", "Keeping Catches", "Stumpings", "Keeping Dismissals"
                );
            
            $statement = $db->prepare('
                SELECT
                      p.Name
                     ,fs.Matches
                     ,fs.CatchesKeeping
                     ,fs.Stumpings
                     ,fs.TotalKeepingWickets
                FROM "Player" p
                INNER JOIN "FieldingSummary" fs on fs.PlayerId = p.PlayerId
                WHERE fs.TotalKeepingWickets > 0
                ORDER by fs.TotalKeepingWickets DESC, fs.CatchesKeeping DESC
                '
                );
            
            $this->generate_csv_output("keeping_ind_summary", $header, $statement);
        }
    }
?>