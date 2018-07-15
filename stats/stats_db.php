<?php
    function db_insert_and_return_id($db, $insert_statement)
    {
        $insert_statement->execute();
        return $db->querySingle("SELECT last_insert_rowid()");
    }
	
	function db_bind_values_from_row($insert_statement, $row)
	{
		foreach($row as $key => $value)
			$insert_statement->bindValue(":$key", $value);
	}
    
    function db_create_schema($db)
    {   
        $db->exec('PRAGMA foreign_keys = ON;');
    
        $db->query('CREATE TABLE "Match" (
            "MatchId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PCMatchId" INTEGER,
            "Status" TEXT,
            "MatchDate" DATETIME,
            "PloughTeam" TEXT,
            "PloughTeamId" INTEGER,
            "OppoClub" TEXT,
            "OppoTeam" TEXT,
            "OppoTeamId" INTEGER,
            "Home" INTEGER,
            "Result" TEXT
            )');
            
        $db->query('CREATE TABLE "Player" (
            "PlayerId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PCPlayerId" INTEGER,
            "Name" TEXT
            )');
            
        $db->query('CREATE TABLE "PlayerPerformance" (
            "PlayerPerformanceId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "MatchId" INTEGER,
            "PlayerId" INTEGER,
            "Captain" INTEGER,
            "Wicketkeeper" INTEGER,
            FOREIGN KEY("MatchId") REFERENCES "Match"("MatchId"),
            FOREIGN KEY("PlayerId") REFERENCES "Player"("PlayerId")
            )');
            
		// Raw performances
        $db->query('CREATE TABLE "BattingPerformance" (
            "BattingPerformanceId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PlayerPerformanceId" INTEGER,
            "PlayerId" INTEGER,
            "Position" INTEGER,
            "HowOut" TEXT,
            "Runs" INTEGER,
            "Balls" INTEGER,
            "Fours" INTEGER,
            "Sixes" INTEGER,
            FOREIGN KEY("PlayerPerformanceId") REFERENCES "PlayerPerformance"("PlayerPerformanceId")
            FOREIGN KEY("PlayerId") REFERENCES "Player"("PlayerId")
            )');
            
        $db->query('CREATE TABLE "BowlingPerformance" (
            "BowlingPerformanceId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PlayerPerformanceId" INTEGER,
            "PlayerId" INTEGER,
            "Position" INTEGER,
            "CompletedOvers" INTEGER,
            "PartialBalls" INTEGER,
            "Maidens" INTEGER,
            "Runs" INTEGER,
            "Wickets" INTEGER,
            "Wides" INTEGER,
            "NoBalls" INTEGER,
            FOREIGN KEY("PlayerPerformanceId") REFERENCES "PlayerPerformance"("PlayerPerformanceId")
            FOREIGN KEY("PlayerId") REFERENCES "Player"("PlayerId")
            )');
            
        $db->query('CREATE TABLE "FieldingPerformance" (
            "FieldingPerformanceId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "PlayerPerformanceId" INTEGER,
            "PlayerId" INTEGER,
            "Catches" INTEGER,
            "RunOuts" INTEGER,
            "Stumpings" INTEGER,
            FOREIGN KEY("PlayerPerformanceId") REFERENCES "PlayerPerformance"("PlayerPerformanceId")
            FOREIGN KEY("PlayerId") REFERENCES "Player"("PlayerId")
            )');
			
		// Performance summaries
		$db->query('CREATE TABLE "BattingSummary" (
			"BattingSummaryId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			"PlayerId" INTEGER,
			"Matches" INTEGER,
			"Innings" INTEGER,
			"NotOuts" INTEGER,
			"Runs" INTEGER,
			"Average" REAL,
			"StrikeRate" REAL,
			"HighScore" INTEGER,
			"HighScoreNotOut" INTEGER,
			"Fifties" INTEGER,
			"Hundreds" INTEGER,
			"Ducks" INTEGER,
			"Balls" INTEGER,
			"Fours" INTEGER,
			"Sixes" INTEGER,
			FOREIGN KEY("PlayerId") REFERENCES "Player"("PlayerId")
			)');
			
		$db->query('CREATE TABLE "BowlingSummary" (
			"BowlingSummaryId" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			"PlayerId" INTEGER,
			"Matches" INTEGER,
			"CompletedOvers" INTEGER,
			"PartialBalls" INTEGER,
			"Maidens" INTEGER,
			"Runs" INTEGER,
			"Wickets" INTEGER,
			"Average" REAL,
			"EconomyRate" REAL,
			"StrikeRate" REAL,
			"BestBowlingWickets" INTEGER,
			"BestBowlingRuns" INTEGER,
			"FiveFors" INTEGER,
			"Wides" INTEGER,
			"NoBalls" INTEGER,
			FOREIGN KEY("PlayerId") REFERENCES "Player"("PlayerId")
			)');
    }
    
    function db_create_insert_match($db)
    {
        return $db->prepare(
            'INSERT INTO "Match" (
				"PCMatchId", "Status", "MatchDate", "PloughTeam", "PloughTeamId", "OppoClub", "OppoTeam", "OppoTeamId", "Home", "Result"
				)
             VALUES (
				 :pc_match_id, :status, :match_date, :plough_team, :plough_team_id, :oppo_club, :oppo_team, :oppo_team_id, :home, :result
			 	)'
            );
    }
    
    function db_create_insert_player($db)
    {
        return $db->prepare(
            'INSERT INTO "Player" (
				"PCPlayerId", "Name"
				)
             VALUES (
				 :pc_player_id, :name
			 	)'
            );
    }
    
    function db_create_insert_player_performance($db)
    {
        return $db->prepare(
            'INSERT INTO "PlayerPerformance" (
				"MatchId", "PlayerId", "Captain", "Wicketkeeper"
				)
             VALUES (
				 :match_id, :player_id, :captain, :wicketkeeper
			 	)'
            );
    }
    
    function db_create_insert_batting_performance($db)
    {
        return $db->prepare(
            'INSERT INTO "BattingPerformance" (
				"PlayerPerformanceId", "PlayerId", "Position", "HowOut", "Runs", "Balls", "Fours", "Sixes"
				)
             VALUES (
				 :player_perf_id, :player_id, :position, :how_out, :runs, :balls, :fours, :sixes
			 	)'
            );
    }
    
    function db_create_insert_bowling_performance($db)
    {
        return $db->prepare(
            'INSERT INTO "BowlingPerformance" (
				"PlayerPerformanceId", "PlayerId", "Position", "CompletedOvers", "PartialBalls", "Maidens", "Runs", "Wickets", "Wides", "NoBalls"
				)
             VALUES (
				 :player_perf_id, :player_id, :position, :completed_overs, :partial_balls, :maidens, :runs, :wickets, :wides, :no_balls
			 	)'
            );
    }
    
    function db_create_insert_fielding_performance($db)
    {
        return $db->prepare(
            'INSERT INTO "FieldingPerformance" (
				"PlayerPerformanceId", "PlayerId", "Catches", "RunOuts", "Stumpings"
				)
             VALUES (
				 :player_perf_id, :player_id, :catches, :run_outs, :stumpings
			 	)'
            );
    }
	
    function db_create_insert_batting_summary($db)
    {
        return $db->prepare(
            'INSERT INTO "BattingSummary" (
				"PlayerId", "Matches", "Innings", "NotOuts", "Runs", "Average", "StrikeRate", 
				"HighScore", "HighScoreNotOut", "Fifties", "Hundreds", "Ducks", "Balls", "Fours", "Sixes"
				)
             VALUES (
				 :player_id, :matches, :innings, :not_outs, :runs, :average, :strike_rate, 
				 :high_score, :high_score_not_out, :fifties, :hundreds, :ducks, :balls, :fours, :sixes
			 	)'
            );
    }
	
    function db_create_insert_bowling_summary($db)
    {
        return $db->prepare(
            'INSERT INTO "BowlingSummary" (
				"PlayerId", "Matches", "CompletedOvers", "PartialBalls", "Maidens", "Runs", "Wickets", "Average",  
				"EconomyRate", "StrikeRate", "BestBowlingWickets", "BestBowlingRuns", "FiveFors", "Wides", "NoBalls"
				)
             VALUES (
				 :player_id, :matches, :completed_overs, :partial_balls, :maidens, :runs, :wickets, :average,  
				 :economy_rate, :strike_rate, :best_bowling_wickets, :best_bowling_runs, :five_fors, :wides, :no_balls
			 	)'
            );
    }
?>