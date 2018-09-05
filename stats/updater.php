<?php
	namespace plough\stats;
    
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once(__DIR__ . "/../utils.php");
    
    require_once("config.php");
	require_once("data-mapper.php");
    require_once("db.php");

    // Constants
    const CLUB_NAME = "Ploughmans CC";
    const DELETED = "Deleted";
    const SEASON = 2018;
    const NO_PC_PLAYER_ID = -1;
    
    // Modes of dismissal
    const DID_NOT_BAT = "did not bat";
	const CAUGHT = "ct";
    const RUN_OUT = "ro";
    const STUMPED = "st";
	
	// Stats period types
	const PERIOD_CAREER = 1;
	const PERIOD_SEASON = 2;
    
	// Helpers
	function get_average($innings, $not_outs, $runs)
	{
		$num_outs = $innings - $not_outs;
		if ($num_outs > 0)
			return ($runs / $num_outs);
		else
			return null;
	}
	
	function get_batting_strike_rate($runs, $balls)
	{
		if ($balls)
			return ($runs / $balls) * 100.0;
		else
			return null;
	}
	
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
            $db_dir = $this->_config->getDbDir();
            if (!file_exists($db_dir))
                \plough\mkdirs($db_dir);
            
            $output_dir = $this->_config->getOutputDir();
            if (!file_exists($output_dir))
                \plough\mkdirs($output_dir);
            
            if ($this->_config->dumpInputs())
            {
                $dump_dir = $this->_config->getInputDumpDir();
                $dump_data_mapper = $this->_config->getInputDumpDataMapper();
                
                if (!file_exists($dump_dir))
                    \plough\mkdirs($dump_dir);
            }
            
            $input_mapper = $this->_config->getInputDataMapper();
         
            log\info("");
            $db_path = \plough\get_stats_db_path($this->_config);
            if (file_exists($db_path))
            {
                if ($this->_config->clearDb())
                {
                    log\info("Deleting old database");
                    unlink($db_path);
                    $create_db_schema = true;
                }
                else
                {
                    log\info("Using existing database");
                    $create_db_schema = false;
                }
            }
            else
            {
                log\info("Database does not exist - creating");
                $create_db_schema = true;
            }
            
            // Open database and create schema if required
            $db = new \SQLite3($db_path);
            db_enable_foreign_keys($db);
            
            if ($create_db_schema)
            {
                log\info("Creating database schema");
                db_create_schema($db);
            }
            
            // Prepare statements
            $insert_update = db_create_insert_update($db);
            $insert_match = db_create_insert_match($db);
            $delete_match = db_create_delete_match($db);
            $insert_player = db_create_insert_player($db);
            $update_player = db_create_update_player($db);
            $insert_player_perf = db_create_insert_player_performance($db);
            $insert_batting_perf = db_create_insert_batting_performance($db);
            $insert_bowling_perf = db_create_insert_bowling_performance($db);
            $insert_fielding_perf = db_create_insert_fielding_performance($db);
            
            // Set up player cache, seeding it from the database
            $player_cache = array();
            $statement = $db->prepare('
                SELECT
                     "PCPlayerId",
                     "PlayerId"
                FROM "Player"
                ORDER BY "PlayerId"
                ');
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
                $player_cache[$row["PCPlayerId"]] = $row["PlayerId"];
            
            // Get match list
            log\info("");
            log\info("Fetching match list...");
            
            if ($create_db_schema)
            {
                $matches_from_date = "01/01/" . SEASON;
                log\info("  Since the database was created from scratch, fetching matches updated since [$matches_from_date]");
            }
            else
            {
                $statement = $db->prepare('
                    SELECT
                         UpdateTime
                    FROM "DbUpdate"
                    ORDER BY UpdateTime DESC
                    LIMIT 1
                    ');
                $matches_from_date = $statement->execute()->fetchArray(SQLITE3_ASSOC)["UpdateTime"];
                log\info("  Fetching matches since last update time [$matches_from_date]");
            }
            
            $current_date = gmdate("Y-m-d");
            $matches_path = $input_mapper->getMatchesPath(SEASON, $matches_from_date);
            $matches_str = file_get_contents($matches_path);
        
            if ($this->_config->dumpInputs())
                file_put_contents($dump_data_mapper->getMatchesPath(SEASON, $matches_from_date), $matches_str);
            
            $matches = json_decode($matches_str, true)["matches"];
            $num_matches = count($matches);
            log\info("  $num_matches matches found");
            log\info("");
            
            if ($num_matches > 0)
            {
                log\info("Fetching match details...");
                foreach ($matches as $match_idx => $match)
                {
                    // Player performance cache for this match
                    $player_perf_cache = array();
                    $pc_match_id = $match["id"];
                    
                    log\info("  Processing match $match_idx (Play-Cricket id $pc_match_id)...");
                    
                    // Get match detail
                    $match_detail_path = $input_mapper->getMatchDetailPath($pc_match_id);
                    $match_detail_str = file_get_contents($match_detail_path);
                    
                    if ($this->_config->dumpInputs())
                        file_put_contents($dump_data_mapper->getMatchDetailPath($pc_match_id), $match_detail_str);
                    
                    $match_detail = json_decode($match_detail_str, true)["match_details"][0];
                    
                    if ($match_detail["status"] == DELETED)
                    {
                        log\info("    Skipping match because it was deleted...");
                    }
                    else if (empty($match_detail["result"]))
                    {
                        log\info("    Skipping match because it is a future fixture...");
                    }
                    else
                    {   
                        // Start transaction for deleting whole of match
                        $db->exec('BEGIN');
                        
                        // Delete match (and associated performances) if it has been added to the database before    
                        $delete_match->bindValue(":pc_match_id", $pc_match_id);
                        $delete_match->execute();
                        
                        // End transaction for deleting whole of match
                        $db->exec('COMMIT');
                        
                        // Start transaction for adding whole of match
                        $db->exec('BEGIN');  
                        
                        // Get team info
                        if ($match_detail["home_club_name"] == CLUB_NAME)
                        {
                            $is_plough_match = 1;
                            $is_plough_home = 1;
                            $plough_team_id = $match_detail["home_team_id"];
                            $players = $match_detail["players"][0]["home_team"];
                        }
                        else if ($match_detail["away_club_name"] == CLUB_NAME)
                        {
                            $is_plough_match = 1;
                            $is_plough_home = 0;
                            $plough_team_id = $match_detail["away_team_id"];
                            $players = $match_detail["players"][1]["away_team"];
                        }
                        else
                        {
                            $is_plough_match = 0;
                            $is_plough_home = 0;
                        }
                        
                        // Insert match
                        $insert_match->bindValue(":pc_match_id", $pc_match_id);
                        $insert_match->bindValue(":status", $match_detail["status"]);
                        $insert_match->bindValue(":match_date", $match_detail["match_date"]);
                        $insert_match->bindValue(":home_club_id", $match_detail["home_club_id"]);
                        $insert_match->bindValue(":home_club_name", $match_detail["home_club_name"]);
                        $insert_match->bindValue(":home_team_id", $match_detail["home_team_id"]);
                        $insert_match->bindValue(":home_team_name", $match_detail["home_team_name"]);
                        $insert_match->bindValue(":away_club_id", $match_detail["away_club_id"]);
                        $insert_match->bindValue(":away_club_name", $match_detail["away_club_name"]);
                        $insert_match->bindValue(":away_team_id", $match_detail["away_team_id"]);
                        $insert_match->bindValue(":away_team_name", $match_detail["away_team_name"]);
                        $insert_match->bindValue(":is_plough_match", $is_plough_match);
                        $insert_match->bindValue(":is_plough_home", $is_plough_home);
                        $insert_match->bindValue(":result", $match_detail["result"]);
                        $insert_match->bindValue(":result_applied_to", $match_detail["result_applied_to"]);
                        $insert_match->bindValue(":toss_won_by", $match_detail["toss_won_by_team_id"]);
                        $insert_match->bindValue(":batted_first", $match_detail["batted_first"]);
                        $match_id = db_insert_and_return_id($db, $insert_match);
                        
                        if ($is_plough_match)
                        {
                            foreach ($players as $player)
                            {
                                $pc_player_id = $player["player_id"];
                                $player_name = $player["player_name"];
                                
                                if (!array_key_exists($pc_player_id, $player_cache))
                                {
                                    // Player doesn't exist - insert
                                    $insert_player->bindValue(":pc_player_id", $pc_player_id);
                                    $insert_player->bindValue(":name", $player["player_name"]);
                                    $player_id = db_insert_and_return_id($db, $insert_player);
                                    $player_cache[$pc_player_id] = $player_id;
                                }
                                else
                                {
                                    // Player exists - update player name and/or PC player id in case it has changed
                                    $update_player->bindValue(":pc_player_id", $pc_player_id);
                                    $update_player->bindValue(":name", $player_name);
                                    $update_player->execute();
                                }
                                
                                // Insert player performance
                                $player_id = $player_cache[$pc_player_id];
                                $insert_player_perf->bindValue(":match_id", $match_id);
                                $insert_player_perf->bindValue(":player_id", $player_id);
                                $insert_player_perf->bindValue(":captain", \plough\int_from_bool($player["captain"]));
                                $insert_player_perf->bindValue(":wicketkeeper", \plough\int_from_bool($player["wicket_keeper"]));
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
                        }
                        
                        // End transaction for adding whole of match
                        $db->exec('COMMIT');
                    }
                }
            
                
            }
            else
            {
                log\info("  No update required");
            }
            
            
            // Build summaries
            log\info("");
            log\info("Loading career base tables...");
            log\info("  Batting");
            $this->load_batting_career_summary_base($db);
            
            log\info("");
            log\info("Building summary tables for " . SEASON . "...");
            log\info("  Batting");
            log\info("    Season " . SEASON);
            $this->generate_batting_summary($db);
            log\info("    Career");
            $this->generate_career_batting_summary($db);
            log\info("  Bowling");
            $this->generate_bowling_summary($db);
            log\info("  Fielding");
            $this->generate_fielding_summary($db);
            
            // Mark DB update
            log\info("");
            log\info("Setting update time in database");
            $insert_update->bindValue(":update_time", $current_date);
            $insert_update->execute();
            
                
            // Generate outputs
            log\info("");
            log\info("Generating CSV output...");
            log\info("  Batting");
            log\info("    Season " . SEASON);
            $this->generate_batting_summary_csv($db, PERIOD_SEASON);
            log\info("    Career");
            $this->generate_batting_summary_csv($db, PERIOD_CAREER);
            log\info("  Bowling");
            $this->generate_bowling_summary_csv($db);
            log\info("  Fielding");
            $this->generate_fielding_summary_csv($db);
            log\info("  Keeping");
            $this->generate_keeping_summary_csv($db);
        }
        
        // Private helpers
        private function get_players_by_name($db)
        {
            $players = array();
            $statement = $db->prepare('SELECT * FROM "Player" ORDER BY Name');
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
                $players[$row["Name"]] = $row;
            
            return $players;
        }
        
        private function generate_csv_output($output_name, $header, $statement)
        {
            $output_dir = $this->_config->getOutputDir();
            $out = fopen("$output_dir/$output_name.csv", "w");
            \plough\fputcsv_eol($out, $header);
            
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
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
                
                \plough\fputcsv_eol($out, $formatted_row);
            }
            fclose($out);
        }
		
        private function load_batting_career_summary_base($db)
        {
            $players = $this->get_players_by_name($db);
            
            db_truncate_table($db, "CareerBattingSummaryBase");
            $insert_career_batting_summary_base = db_create_insert_career_batting_summary_base($db);
            $insert_player = db_create_insert_player($db);
            
            $career_base_path = $this->_config->getCareerBaseDir() . "/career-stats-batting-end-" . (SEASON - 1) . ".csv";
            if (file_exists($career_base_path))
            {   
                $base = fopen($career_base_path, "r");
                while ($row = fgetcsv($base))
                {
                    if ($row[0] == "Player")
                    {
                        $idx = array_flip($row);
                    }
                    else
                    {
                        $name = $row[$idx["Player"]];
                        if (!array_key_exists($name, $players))
                        {
                            $insert_player->bindValue(":pc_player_id", NO_PC_PLAYER_ID);
                            $insert_player->bindValue(":name", $name);
                            $player_id = db_insert_and_return_id($db, $insert_player);
                        }
                        else
                        {
                            $player_id = $players[$name]["PlayerId"];
                        }
                        
                        $high_score = $row[$idx["HS"]];
                        $high_score_not_out = (strpos($high_score, "*") !== false);
                        $high_score = str_replace("*", "", $high_score);
                        
						$innings = $row[$idx["Inns"]];
						$not_outs = $row[$idx["NO"]];
						$runs = $row[$idx["Runs"]];
						$average = get_average($innings, $not_outs, $runs);
						$balls_str = $row[$idx["Balls"]];
						$balls = (empty($balls_str) ? null : $balls_str);
						$strike_rate = get_batting_strike_rate($runs, $balls);
						
                        $insert_career_batting_summary_base->bindValue(":PlayerId", $player_id);
                        $insert_career_batting_summary_base->bindValue(":Matches", $row[$idx["Mat"]]);
                        $insert_career_batting_summary_base->bindValue(":Innings", $innings);
                        $insert_career_batting_summary_base->bindValue(":NotOuts", $not_outs);
                        $insert_career_batting_summary_base->bindValue(":Runs", $runs);
                        $insert_career_batting_summary_base->bindValue(":Average", $average);
                        $insert_career_batting_summary_base->bindValue(":StrikeRate", $strike_rate);
                        $insert_career_batting_summary_base->bindValue(":HighScore", $high_score);
                        $insert_career_batting_summary_base->bindValue(":HighScoreNotOut", $high_score_not_out);
                        $insert_career_batting_summary_base->bindValue(":Fifties", $row[$idx["50s"]]);
                        $insert_career_batting_summary_base->bindValue(":Hundreds", $row[$idx["100s"]]);
                        $insert_career_batting_summary_base->bindValue(":Ducks", $row[$idx["0s"]]);
                        $insert_career_batting_summary_base->bindValue(":Balls", $balls);
                        $insert_career_batting_summary_base->bindValue(":Fours", $row[$idx["4s"]]);
                        $insert_career_batting_summary_base->bindValue(":Sixes", $row[$idx["6s"]]);
                        $insert_career_batting_summary_base->execute();
                    }
                }
                
                fclose($base);
            }
        }
        
        private function generate_batting_summary($db)
        {
            $players = $this->get_players_by_name($db);
            
            db_truncate_table($db, "BattingSummary");
            $insert_batting_summary = db_create_insert_batting_summary($db);
            
            foreach ($players as $player_name => $player)
            {	
                $player_id = $player["PlayerId"];
            
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
                         p.PlayerId
                        ,COUNT(pp.PlayerPerformanceId) AS Matches
                        ,COUNT(bp.BattingPerformanceId) AS Innings
                        ,SUM(CASE bp.HowOut 
                            WHEN "no" THEN 1 
                            WHEN "rh" THEN 1
                            ELSE 0 END) AS NotOuts
                        ,SUM(bp.Runs) AS Runs
                        ,(CAST(SUM(bp.Runs) AS FLOAT) / (COUNT(bp.BattingPerformanceId) - SUM(CASE bp.HowOut WHEN "no" THEN 1 WHEN "rh" THEN 1 ELSE 0 END))) AS Average
                        ,((CAST(SUM(bp.Runs) AS FLOAT) / SUM(bp.Balls)) * 100.0) AS StrikeRate
                        ,SUM(CASE WHEN bp.Runs >= 50 AND bp.Runs < 100 THEN 1 ELSE 0 END) AS Fifties
                        ,SUM(CASE WHEN bp.Runs >= 100 THEN 1 ELSE 0 END) AS Hundreds
                        ,SUM(CASE WHEN bp.Runs = 0 and bp.HowOut <> "no" THEN 1 ELSE 0 END) AS Ducks
                        ,SUM(bp.Balls) as Balls
                        ,SUM(bp.Fours) as Fours
                        ,SUM(bp.Sixes) as Sixes
                    FROM "Player" p
                    INNER JOIN "PlayerPerformance" pp on pp.PlayerId = p.PlayerId
                    --INNER JOIN "IncludedPerformance" ip on ip.PlayerPerformanceId = pp.PlayerPerformanceId
                    LEFT JOIN "BattingPerformance" bp on bp.PlayerPerformanceId = pp.PlayerPerformanceId
                    WHERE
                            p.PlayerId = :PlayerId
                    GROUP BY p.PlayerId, p.Name
                    '
                    );
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (empty($result))
                    continue;
                
                db_bind_values_from_row($insert_batting_summary, $result);
            
                // High score
                $statement = $db->prepare('
                    SELECT
                         p.PlayerId as PlayerId
                        ,bp.Runs as HighScore
                        ,(CASE bp.HowOut 
                            WHEN "no" THEN 1 
                            WHEN "rh" THEN 1
                            ELSE 0 END) as HighScoreNotOut
                    FROM "Player" p
                    LEFT JOIN "BattingPerformance" bp on bp.PlayerId = p.PlayerId
                    WHERE
                            p.PlayerId = :PlayerId
                    ORDER BY HighScore DESC, HighScoreNotOut DESC
                    LIMIT 1
                    ');
                $statement->bindValue(":PlayerId", $player_id);
                $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                db_bind_values_from_row($insert_batting_summary, $result);
            
                // Insert
                $insert_batting_summary->execute();
            }
        }
        
        private function generate_career_batting_summary($db)
        {
            $players = $this->get_players_by_name($db);
            
            db_truncate_table($db, "CareerBattingSummary");
            $insert_career_batting_summary = db_create_insert_career_batting_summary($db);
            
            foreach ($players as $player_name => $player)
            {	
                $player_id = $player["PlayerId"];
                
                // Career base
                $statement = $db->prepare('SELECT * FROM "CareerBattingSummaryBase" WHERE PlayerId = :PlayerId');
                $statement->bindValue(":PlayerId", $player_id);
                $career_base = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                
                // Season
                $statement = $db->prepare('SELECT * FROM "BattingSummary" WHERE PlayerId = :PlayerId');
                $statement->bindValue(":PlayerId", $player_id);
                $season = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                
                if (!empty($career_base))
                {
                    if (!empty($season))
                    {
                        // Sum career base and season
                        $career_summary = array();
                        $career_summary["PlayerId"] = $player_id;
                        $career_summary["Matches"] = $career_base["Matches"] + $season["Matches"];
                        $career_summary["Innings"] = $career_base["Innings"] + $season["Innings"];
                        $career_summary["NotOuts"] = $career_base["NotOuts"] + $season["NotOuts"];
                        $career_summary["Runs"] = $career_base["Runs"] + $season["Runs"];
                        
                        $career_summary["Average"] = get_average(
							$career_summary["Innings"], $career_summary["NotOuts"], $career_summary["Runs"]
							);
                            
                        if ($career_base["Balls"])
                        {
                            $career_summary["Balls"] = $career_base["Balls"] + $season["Balls"];
                            $career_summary["StrikeRate"] = get_batting_strike_rate($career_summary["Runs"], $career_summary["Balls"]);
                        }
                        else
                        {
                            $career_summary["Balls"] = null;
                            $career_summary["StrikeRate"] = null;
                        }
                            
                        if ($career_base["HighScore"] > $season["HighScore"])
                        {
                            $career_summary["HighScore"] = $career_base["HighScore"];
                            $career_summary["HighScoreNotOut"] = $career_base["HighScoreNotOut"];
                        }
                        else if ($season["HighScore"] > $career_base["HighScore"])
                        {
                            $career_summary["HighScore"] = $season["HighScore"];
                            $career_summary["HighScoreNotOut"] = $season["HighScoreNotOut"];
                        }
                        else
                        {
                            $career_summary["HighScore"] = $season["HighScore"];
                            $career_summary["HighScoreNotOut"] = max($career_base["HighScoreNotOut"], $season["HighScoreNotOut"]);
                        }
                        
                        $career_summary["Fifties"] = $career_base["Fifties"] + $season["Fifties"];
                        $career_summary["Hundreds"] = $career_base["Hundreds"] + $season["Hundreds"];
                        $career_summary["Ducks"] = $career_base["Ducks"] + $season["Ducks"];
                        $career_summary["Fours"] = $career_base["Fours"] + $season["Fours"];
                        $career_summary["Sixes"] = $career_base["Sixes"] + $season["Sixes"];
                    }
                    else
                    {
                        // No season - use career base
                        $career_summary = $career_base;
                    }
                }
                else if (!empty($season))
                {
                    // No career base - use season
                    $career_summary = $season;
                }
                
                db_bind_values_from_row($insert_career_batting_summary, $career_summary);
                $insert_career_batting_summary->execute();
            }
        }
            
        private function generate_batting_summary_csv($db, $period_type)
        {
			if ($period_type == PERIOD_CAREER)
			{
				$table_name = "CareerBattingSummary";
				$output_name = "batting_career_ind_summary";
			}
			else if ($period_type == PERIOD_SEASON)
			{
				$table_name = "BattingSummary";
				$output_name = "batting_ind_summary";
			}
			
            $header = array(
                "Player", "Mat", "Inns", "NO", "Runs", "Ave", "SR", 
                "HS", "50s", "100s", "0s", "4s", "6s"
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
                     ,(CAST(bs.HighScore AS TEXT) || CASE bs.HighScoreNotOut WHEN 1 THEN \'*\' ELSE \'\' END) as HighScore
                     ,bs.Fifties
                     ,bs.Hundreds
                     ,bs.Ducks
                     ,bs.Fours
                     ,bs.Sixes
                FROM "Player" p
                INNER JOIN "' . $table_name . '" bs on bs.PlayerId = p.PlayerId
                WHERE bs.Innings > 0
                ORDER by bs.Runs DESC, bs.Average DESC, bs.Innings DESC, bs.NotOuts DESC, bs.Matches DESC, p.Name
                '
                );
            
            $this->generate_csv_output($output_name, $header, $statement);
        }
            
        private function generate_bowling_summary($db)
        {
            $players = $this->get_players_by_name($db);
            
            db_truncate_table($db, "BowlingSummary");
            $insert_bowling_summary = db_create_insert_bowling_summary($db);
            
            foreach ($players as $player_name => $player)
            {   
                $player_id = $player["PlayerId"];
                
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
                "Player", "Mat", "Overs", "Mdns", "Runs", "Wkts", "Ave", 
                "Econ", "SR", "Best", "5wi", "Wides", "NBs"
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
                ORDER by bs.Wickets DESC, bs.Average, bs.EconomyRate
                '
                );
            
            $this->generate_csv_output("bowling_ind_summary", $header, $statement);
        }
        
        private function generate_fielding_summary($db)
        {
            $players = $this->get_players_by_name($db);
            
            db_truncate_table($db, "FieldingSummary");
            $insert_fielding_summary = db_create_insert_fielding_summary($db);
            
            foreach ($players as $player)
            {	
                $player_id = $player["PlayerId"];
            
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
                ORDER by fs.TotalFieldingWickets DESC, fs.CatchesFielding DESC, fs.Matches DESC, p.Name
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
                ORDER by fs.TotalKeepingWickets DESC, fs.CatchesKeeping DESC, fs.Matches DESC, p.Name
                '
                );
            
            $this->generate_csv_output("keeping_ind_summary", $header, $statement);
        }
    }
?>