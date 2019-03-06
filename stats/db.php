<?php
	namespace plough\stats;
    function db_insert_and_return_id($db, $insert_statement)
    {
        $insert_statement->execute();
        return $db->querySingle('SELECT last_insert_rowid()');
    }

	function db_bind_values_from_row($insert_statement, $row)
	{
		foreach($row as $key => $value)
			$insert_statement->bindValue(":$key", $value);
	}

    function db_truncate_table($db, $table_name)
    {
        $statement = $db->prepare('DELETE FROM ' . $table_name);
        $statement->execute();
    }

    function db_delete_season_from_table($db, $table_name, $season)
    {
        $statement = $db->prepare(
            'DELETE FROM ' . $table_name .
            ' WHERE Season = ' . $season
            );
        $statement->execute();
    }

    function db_delete_player_from_table($db, $table_name, $player_id)
    {
        $statement = $db->prepare(
            'DELETE FROM ' . $table_name .
            ' WHERE PlayerId = ' . $player_id
            );
        $statement->execute();
    }

    function db_enable_foreign_keys($db)
    {
        $db->exec('PRAGMA foreign_keys = ON;');
    }

    function db_create_player_view($db, $table_name)
    {
        $statement = $db->prepare(
           'CREATE VIEW ' . $table_name . 'View AS
            SELECT
                 p.Name
                ,s.*
            FROM ' . $table_name . ' s
            INNER JOIN Player p on p.PlayerId = s.PlayerId
            ');
        $statement->execute();
    }

    function db_create_player_with_matches_view($db, $table_name, $matches_table_name)
    {
        $statement = $db->prepare(
           'CREATE VIEW ' . $table_name . 'View AS
            SELECT
                 p.Name
                ,ms.Matches
                ,s.*
            FROM ' . $table_name . ' s
            INNER JOIN Player p on p.PlayerId = s.PlayerId
            INNER JOIN ' . $matches_table_name . ' ms on ms.PlayerId = s.PlayerId
            WHERE
                s.Season = ms.Season
            ');
        $statement->execute();
    }

    const MATCHES_SUMMARY_COLS = '
        Season INTEGER,
        Matches INTEGER,
        ';

    const MATCHES_SUMMARY_INSERT = '(
            PlayerId, Season, Matches
            )
        VALUES (
            :PlayerId, :Season, :Matches
        )';

    const BATTING_SUMMARY_COLS = '
        Season INTEGER,
        Innings INTEGER,
        NotOuts INTEGER,
        Runs INTEGER,
        Average REAL,
        StrikeRate REAL,
        HighScore INTEGER,
        HighScoreNotOut INTEGER,
        Fifties INTEGER,
        Hundreds INTEGER,
        Ducks INTEGER,
        Balls INTEGER,
        Fours INTEGER,
        Sixes INTEGER,
        ';

    CONST BATTING_SUMMARY_INSERT = '(
            PlayerId, Season, Innings, NotOuts, Runs, Average, StrikeRate,
            HighScore, HighScoreNotOut, Fifties, Hundreds, Ducks, Balls, Fours, Sixes
            )
         VALUES (
             :PlayerId, :Season, :Innings, :NotOuts, :Runs, :Average, :StrikeRate,
             :HighScore, :HighScoreNotOut, :Fifties, :Hundreds, :Ducks, :Balls, :Fours, :Sixes
             )';

    const BOWLING_SUMMARY_COLS = '
        Season INTEGER,
        CompletedOvers INTEGER,
        PartialBalls INTEGER,
        Maidens INTEGER,
        Runs INTEGER,
        Wickets INTEGER,
        Average REAL,
        EconomyRate REAL,
        StrikeRate REAL,
        BestBowlingWickets INTEGER,
        BestBowlingRuns INTEGER,
        FiveFors INTEGER,
        Wides INTEGER,
        NoBalls INTEGER,
        ';

    const BOWLING_SUMMARY_INSERT = '(
            PlayerId, Season, CompletedOvers, PartialBalls, Maidens, Runs, Wickets, Average,
            EconomyRate, StrikeRate, BestBowlingWickets, BestBowlingRuns, FiveFors, Wides, NoBalls
            )
         VALUES (
             :PlayerId, :Season, :CompletedOvers, :PartialBalls, :Maidens, :Runs, :Wickets, :Average,
             :EconomyRate, :StrikeRate, :BestBowlingWickets, :BestBowlingRuns, :FiveFors, :Wides, :NoBalls
             )';

    const FIELDING_SUMMARY_COLS = '
        Season INTEGER,
        CatchesFielding INTEGER,
        RunOuts INTEGER,
        TotalFieldingWickets INTEGER,
        CatchesKeeping INTEGER,
        Stumpings INTEGER,
        TotalKeepingWickets INTEGER,
        ';

    const FIELDING_SUMMARY_INSERT = '(
            PlayerId, Season, CatchesFielding, RunOuts, TotalFieldingWickets,
            CatchesKeeping, Stumpings, TotalKeepingWickets
			)
        VALUES (
            :PlayerId, :Season, :CatchesFielding, :RunOuts, :TotalFieldingWickets,
			:CatchesKeeping, :Stumpings, :TotalKeepingWickets
		    )';

    function db_create_schema($db)
    {
        // Tables
        $db->query('CREATE TABLE DbUpdate (
            UpdateId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            UpdateTime DATETIME
            )');

        $db->query('CREATE TABLE Match (
            MatchId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PcMatchId INTEGER,
            Status TEXT,
            Season INTEGER,
            MatchDate DATETIME,
            CompetitionType TEXT,
            HomeClubId INTEGER,
            HomeClubName TEXT,
            HomeTeamId INTEGER,
            HomeTeamName TEXT,
            AwayClubId INTEGER,
            AwayClubName TEXT,
            AwayTeamId INTEGER,
            AwayTeamName TEXT,
            IsPloughMatch INTEGER,
            IsPloughHome INTEGER,
            Result TEXT,
            ResultAppliedToTeamId INTEGER,
            TossWonByTeamId INTEGER,
            BattedFirstTeamId INTEGER
            )');

        $db->query('CREATE TABLE Player (
            PlayerId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PcPlayerId INTEGER,
            Name TEXT,
            Active INTEGER
            )');

        $db->query('CREATE TABLE PlayerPerformance (
            PlayerPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            MatchId INTEGER,
            PlayerId INTEGER,
            Captain INTEGER,
            Wicketkeeper INTEGER,
            FOREIGN KEY(MatchId) REFERENCES Match(MatchId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');

		// Raw performances
        $db->query('CREATE TABLE BattingPerformance (
            BattingPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerPerformanceId INTEGER,
            PlayerId INTEGER,
            Position INTEGER,
            HowOut TEXT,
            Runs INTEGER,
            Balls INTEGER,
            Fours INTEGER,
            Sixes INTEGER,
            FOREIGN KEY(PlayerPerformanceId) REFERENCES PlayerPerformance(PlayerPerformanceId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');

        $db->query('CREATE TABLE BowlingPerformance (
            BowlingPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerPerformanceId INTEGER,
            PlayerId INTEGER,
            Position INTEGER,
            CompletedOvers INTEGER,
            PartialBalls INTEGER,
            Maidens INTEGER,
            Runs INTEGER,
            Wickets INTEGER,
            Wides INTEGER,
            NoBalls INTEGER,
            FOREIGN KEY(PlayerPerformanceId) REFERENCES PlayerPerformance(PlayerPerformanceId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');

        $db->query('CREATE TABLE FieldingPerformance (
            FieldingPerformanceId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            PlayerPerformanceId INTEGER,
            PlayerId INTEGER,
            Catches INTEGER,
            RunOuts INTEGER,
            Stumpings INTEGER,
            FOREIGN KEY(PlayerPerformanceId) REFERENCES PlayerPerformance(PlayerPerformanceId) ON DELETE CASCADE,
            FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId) ON DELETE CASCADE
            )');

		// Performance summaries
        $db->query('CREATE TABLE MatchesSummary (
			MatchesSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. MATCHES_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerMatchesSummaryBase (
			CareerMatchesSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER, '
			. MATCHES_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerMatchesSummary (
			CareerMatchesSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER, '
			. MATCHES_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

		$db->query('CREATE TABLE BattingSummary (
			BattingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. BATTING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerBattingSummaryBase (
			CareerBattingSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER, '
			. BATTING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerBattingSummary (
			CareerBattingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER, '
			. BATTING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

		$db->query('CREATE TABLE BowlingSummary (
			BowlingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. BOWLING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerBowlingSummaryBase (
			CareerBowlingSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. BOWLING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerBowlingSummary (
			CareerBowlingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. BOWLING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

		$db->query('CREATE TABLE FieldingSummary (
			FieldingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. FIELDING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerFieldingSummaryBase (
			CareerFieldingSummaryBaseId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. FIELDING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE CareerFieldingSummary (
			CareerFieldingSummaryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,'
			. FIELDING_SUMMARY_COLS .
			'FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE Milestone (
			MilestoneId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			PlayerId INTEGER,
            Season INTEGER,
            State TEXT,
            Type TEXT,
            Description TEXT,
			FOREIGN KEY(PlayerId) REFERENCES Player(PlayerId)
			)');

        $db->query('CREATE TABLE LeagueTableEntry (
			LeagueTableEntryId INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            Season INTEGER,
            Position INTEGER,
            Club TEXT,
            Abandoned INTEGER,
            Played INTEGER,
            Won INTEGER,
            Lost INTEGER,
            Tied INTEGER,
            BonusPoints INTEGER,
            PenaltyPoints INTEGER,
            TotalPoints INTEGER,
            AveragePoints REAL
			)');

        // Views
        db_create_player_view($db, "PlayerPerformance");

        db_create_player_view($db, "MatchesSummary");
        db_create_player_view($db, "CareerMatchesSummary");
        db_create_player_view($db, "CareerMatchesSummaryBase");

        db_create_player_view($db, "BattingPerformance");
        db_create_player_with_matches_view($db, "BattingSummary", "MatchesSummary");
        db_create_player_with_matches_view($db, "CareerBattingSummary", "CareerMatchesSummary");
        db_create_player_with_matches_view($db, "CareerBattingSummaryBase", "CareerMatchesSummaryBase");

        db_create_player_view($db, "BowlingPerformance");
        db_create_player_with_matches_view($db, "BowlingSummary", "MatchesSummary");
        db_create_player_with_matches_view($db, "CareerBowlingSummary", "CareerMatchesSummary");
        db_create_player_with_matches_view($db, "CareerBowlingSummaryBase", "CareerMatchesSummaryBase");

        db_create_player_view($db, "FieldingPerformance");
        db_create_player_with_matches_view($db, "FieldingSummary", "MatchesSummary");
        db_create_player_with_matches_view($db, "CareerFieldingSummary", "CareerMatchesSummary");
        db_create_player_with_matches_view($db, "CareerFieldingSummaryBase", "CareerMatchesSummaryBase");

        db_create_player_view($db, "Milestone");
    }

    function db_create_insert_update($db)
    {
        return $db->prepare(
            'INSERT INTO DbUpdate (
				UpdateTime
				)
             VALUES (
				 :UpdateTime
			 	)'
            );
    }

    function db_create_insert_match($db)
    {
        return $db->prepare(
            'INSERT INTO Match (
				PcMatchId, Status, Season, MatchDate, CompetitionType, HomeClubId, HomeClubName, HomeTeamId, HomeTeamName,
                AwayClubId, AwayClubName, AwayTeamId, AwayTeamName, IsPloughMatch, IsPloughHome,
                Result, ResultAppliedToTeamId, TossWonByTeamId, BattedFirstTeamId
				)
             VALUES (
				 :PcMatchId, :Status, :Season, :MatchDate, :CompetitionType, :HomeClubId, :HomeClubName, :HomeTeamId, :HomeTeamName,
                 :AwayClubId, :AwayClubName, :AwayTeamId, :AwayTeamName, :IsPloughMatch, :IsPloughHome,
                 :Result, :ResultAppliedToTeamId, :TossWonByTeamId, :BattedFirstTeamId
			 	)'
            );
    }

    function db_create_delete_match($db)
    {
        return $db->prepare(
            'DELETE FROM Match WHERE PcMatchId = :PcMatchId'
            );
    }

    function db_create_insert_player($db)
    {
        return $db->prepare(
            'INSERT INTO Player (
				PcPlayerId, Name, Active
				)
             VALUES (
				 :PcPlayerId, :Name, :Active
			 	)'
            );
    }

    function db_create_update_player($db)
    {
        return $db->prepare(
            'UPDATE Player SET Name = :Name WHERE PcPlayerId = :PcPlayerId'
            );
    }

    function db_create_insert_player_performance($db)
    {
        return $db->prepare(
            'INSERT INTO PlayerPerformance (
				MatchId, PlayerId, Captain, Wicketkeeper
				)
             VALUES (
				 :MatchId, :PlayerId, :Captain, :Wicketkeeper
			 	)'
            );
    }

    function db_create_insert_batting_performance($db)
    {
        return $db->prepare(
            'INSERT INTO BattingPerformance (
				PlayerPerformanceId, PlayerId, Position, HowOut, Runs, Balls, Fours, Sixes
				)
             VALUES (
				 :PlayerPerformanceId, :PlayerId, :Position, :HowOut, :Runs, :Balls, :Fours, :Sixes
			 	)'
            );
    }

    function db_create_insert_bowling_performance($db)
    {
        return $db->prepare(
            'INSERT INTO BowlingPerformance (
				PlayerPerformanceId, PlayerId, Position, CompletedOvers, PartialBalls, Maidens, Runs, Wickets, Wides, NoBalls
				)
             VALUES (
				 :PlayerPerformanceId, :PlayerId, :Position, :CompletedOvers, :PartialBalls, :Maidens, :Runs, :Wickets, :Wides, :NoBalls
			 	)'
            );
    }

    function db_create_insert_fielding_performance($db)
    {
        return $db->prepare(
            'INSERT INTO FieldingPerformance (
				PlayerPerformanceId, PlayerId, Catches, RunOuts, Stumpings
				)
             VALUES (
				 :PlayerPerformanceId, :PlayerId, :Catches, :RunOuts, :Stumpings
			 	)'
            );
    }

    function db_create_insert_matches_summary($db)
    {
        return $db->prepare('INSERT INTO MatchesSummary ' . MATCHES_SUMMARY_INSERT);
    }

    function db_create_insert_career_matches_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerMatchesSummaryBase ' . MATCHES_SUMMARY_INSERT);
    }

    function db_create_insert_career_matches_summary($db)
    {
        return $db->prepare('INSERT INTO CareerMatchesSummary ' . MATCHES_SUMMARY_INSERT);
    }

    function db_create_insert_batting_summary($db)
    {
        return $db->prepare('INSERT INTO BattingSummary ' . BATTING_SUMMARY_INSERT);
    }

    function db_create_insert_career_batting_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerBattingSummaryBase ' . BATTING_SUMMARY_INSERT);
    }

    function db_create_insert_career_batting_summary($db)
    {
        return $db->prepare('INSERT INTO CareerBattingSummary ' . BATTING_SUMMARY_INSERT);
    }

    function db_create_insert_bowling_summary($db)
    {
        return $db->prepare('INSERT INTO BowlingSummary ' . BOWLING_SUMMARY_INSERT);
    }

    function db_create_insert_career_bowling_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerBowlingSummaryBase ' . BOWLING_SUMMARY_INSERT);
    }

    function db_create_insert_career_bowling_summary($db)
    {
        return $db->prepare('INSERT INTO CareerBowlingSummary ' . BOWLING_SUMMARY_INSERT);
    }

    function db_create_insert_fielding_summary($db)
    {
        return $db->prepare('INSERT INTO FieldingSummary ' . FIELDING_SUMMARY_INSERT);
    }

    function db_create_insert_career_fielding_summary_base($db)
    {
        return $db->prepare('INSERT INTO CareerFieldingSummaryBase ' . FIELDING_SUMMARY_INSERT);
    }

    function db_create_insert_career_fielding_summary($db)
    {
        return $db->prepare('INSERT INTO CareerFieldingSummary ' . FIELDING_SUMMARY_INSERT);
    }

    function db_create_insert_milestone($db)
    {
        return $db->prepare(
            'INSERT INTO Milestone (
                PlayerId, Season, State, Type, Description
                )
             VALUES (
                 :PlayerId, :Season, :State, :Type, :Description
                )'
            );
    }

    function db_create_insert_league_table_entry($db)
    {
        return $db->prepare(
            'INSERT INTO LeagueTableEntry (
                Season, Position, Club, Abandoned, Played, Won, Lost, Tied,
                BonusPoints, PenaltyPoints, TotalPoints, AveragePoints
                )
             VALUES (
                 :Season, :Position, :Club, :Abandoned, :Played, :Won, :Lost, :Tied,
                 :BonusPoints, :PenaltyPoints, :TotalPoints, :AveragePoints
                )'
            );
    }
?>
