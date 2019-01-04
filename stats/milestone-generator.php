<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("db.php");

    // Constants
    // Milestone states
    const MS_STATE_ACHIEVED = "Achieved";
    const MS_STATE_NEXT = "Next";

    // Milestone types
    const MS_TYPE_GENERAL = "General";
    const MS_TYPE_BATTING = "Batting";
    const MS_TYPE_BOWLING = "Bowling";
    const MS_TYPE_FIELDING = "Fielding";
    const MS_TYPE_KEEPING = "Keeping";

    // Milestone gaps to next - only record "next" milestone if within this gap
    const MS_GAP_MATCHES = 3;
    const MS_GAP_RUNS = 50;
    const MS_GAP_WICKETS = 5;
    const MS_GAP_CATCHES = 3;
    const MS_GAP_KEEPING_CATCHES = 3;

    // Helpers
    class MilestoneResult
    {
        public $achieved = array();
        public $next;
    }

    function calculate_milestones($start_value, $current_value, $milestone_values)
    {
        $result = new MilestoneResult();

        foreach ($milestone_values as $idx => $milestone)
        {
            // Find first potential milestone after the start value
            if ($milestone > $start_value)
            {
                if ($milestone <= $current_value)
                {
                    // Milestone achieved this season
                    array_push($result->achieved, $milestone);
                }
                else
                {
                    // Found next milestone - stop here
                    $result->next = $milestone;
                    break;
                }
            }
        }

        return $result;
    }

    function get_milestone_description($value, $name, $gap = null)
    {
        $result = $value . " " . strtolower($name);

        if ($gap)
            $result = $result . "\n($gap needed)";

        return $result;
    }

    class MilestoneGenerator
    {
        // Properties
        private $_db;

        // Milestone values
        // General milestones
        private $_msValuesMatches;

        // Batting
        private $_msValuesRuns;

        // Bowling
        private $_msValuesWickets;

        // Fielding
        private $_msValuesCatches;

        // Keeping
        private $_msValuesKeepingCatches;

        // Public methods
        public function __construct(\SQLite3 $db)
        {
            $this->_db = $db;

            // Build milestone value lists
            $this->_msValuesMatches = range(50, 10000, 50);
            $this->_msValuesRuns = range(1000, 50000, 1000);
            $this->_msValuesWickets = range(50, 5000, 50);
            $this->_msValuesCatches = range(25, 500, 25);
            $this->_msValuesKeepingCatches = range(25, 500, 25);
        }

        public function clear_milestones()
        {
            $db = $this->_db;
            db_truncate_table($db, "Milestone");
        }

        public function generate_milestones($season)
        {
            $db = $this->_db;
            $inserter = db_create_insert_milestone($db);

            // Fetch start / season data for fields of interest
            // Assumes that this function is called immediately after the season
            // summary is calculated for $season but before it is added to the
            // career summary.
            $statement = $db->prepare(
               'SELECT
                     p.PlayerId
                    ,p.Active as PlayerActive
                    ,bac.Matches as MatchesStart
                    ,bas.Matches as MatchesSeason
                    ,bac.Runs as RunsStart
                    ,bas.Runs as RunsSeason
                    ,boc.Wickets as WicketsStart
                    ,bos.Wickets as WicketsSeason
                    ,fc.CatchesFielding as CatchesStart
                    ,fs.CatchesFielding as CatchesSeason
                    ,fc.CatchesKeeping as KeepingCatchesStart
                    ,fs.CatchesKeeping as KeepingCatchesSeason
                FROM Player p
                LEFT JOIN CareerBattingSummary bac ON bac.PlayerId = p.PlayerId
                INNER JOIN BattingSummary bas ON bas.PlayerId = p.PlayerId
                LEFT JOIN CareerBowlingSummary boc ON boc.PlayerId = p.PlayerId
                INNER JOIN BowlingSummary bos ON bos.PlayerId = p.PlayerId
                LEFT JOIN CareerFieldingSummary fc ON fc.PlayerId = p.PlayerId
                INNER JOIN FieldingSummary fs ON fs.PlayerId = p.PlayerId
                --WHERE
                --        bas.Season = :Season
                --    and bos.Season = :Season
                --    and fs.Season = :Season
                ORDER BY p.PlayerId
                ');
            $statement->bindValue(":Season", $season);
            $result = $statement->execute();

            // Calculate relevant milestones for active players
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
            {
                if ($row["PlayerActive"])
                {
                    // General
                    $this->calculate_and_store(
                        $inserter, $season, $row, MS_TYPE_GENERAL, "Matches", $this->_msValuesMatches, MS_GAP_MATCHES
                        );

                    // Batting
                    $this->calculate_and_store(
                        $inserter, $season, $row, MS_TYPE_BATTING, "Runs", $this->_msValuesRuns, MS_GAP_RUNS
                        );

                    // Bowling
                    $this->calculate_and_store(
                        $inserter, $season, $row, MS_TYPE_BOWLING, "Wickets", $this->_msValuesWickets, MS_GAP_WICKETS
                        );

                    // Fielding
                    $this->calculate_and_store(
                        $inserter, $season, $row, MS_TYPE_FIELDING, "Catches", $this->_msValuesCatches, MS_GAP_CATCHES
                        );

                    // Keeping
                    $this->calculate_and_store(
                        $inserter, $season, $row, MS_TYPE_FIELDING, "KeepingCatches", $this->_msValuesKeepingCatches, MS_GAP_KEEPING_CATCHES, "keeping catches"
                        );
                }
            }
        }

        // Assumes the player name is in the first column of each row
        public function join_milestones_to_player_rows($rows, $milestone_types)
        {
            $db = $this->_db;

            // Build map from player name to milestone text
            $milestone_types_str = "'" . implode("', '", $milestone_types) . "'";
            $statement = $db->prepare(
               'SELECT
                     p.Name
                    ,m.State
                    ,m.Description
                FROM Player p
                INNER JOIN Milestone m on m.PlayerId = p.PlayerId
                WHERE
                        m.Type in (' . $milestone_types_str . ')
                ORDER BY p.Name, m.State, m.Description
                ');
            $query_result = $statement->execute();

            $name_to_milestone_text = array();
            while ($row = $query_result->fetchArray(SQLITE3_ASSOC))
            {
                $name = $row["Name"];
                $state = $row["State"];

                if ($state == MS_STATE_ACHIEVED)
                    $css_class = "achieved-milestone";
                else
                    $css_class = "next-milestone";

                $text_for_this_milestone =
                    "<span class='$css_class'>" . $row["Description"] . "</span>";

                if (!array_key_exists($name, $name_to_milestone_text))
                    $name_to_milestone_text[$name] = "";

                $current_text = $name_to_milestone_text[$name];
                if (strlen($current_text) > 0)
                    $current_text = $current_text . PHP_EOL;

                $current_text = $current_text . $text_for_this_milestone;
                $name_to_milestone_text[$name] = $current_text;
            }

            $rows_with_milestones = array();
            foreach ($rows as $row)
            {
                $row_with_milestones = $row;
                $name = $row_with_milestones[0];

                $milestone_text = "";
                if (array_key_exists($name, $name_to_milestone_text))
                    $milestone_text = $name_to_milestone_text[$name];

                array_push($row_with_milestones, $milestone_text);
                array_push($rows_with_milestones, $row_with_milestones);
            }

            return $rows_with_milestones;
        }

        private function calculate_and_store(
            $inserter, $season, $player_data, $type, $name, $value_list, $max_gap_to_next, $name_for_desc = null
            )
        {
            // Calculate
            $start_value = $player_data[$name . "Start"];
            if (is_null($start_value))
                $start_value = 0;
            $current_value = $start_value + $player_data[$name . "Season"];
            $result = calculate_milestones($start_value, $current_value, $value_list);

            // Store
            $inserter->bindValue(":PlayerId", $player_data["PlayerId"]);
            $inserter->bindValue(":Season", $season);
            $inserter->bindValue(":Type", $type);
            $name_to_use = (is_null($name_for_desc) ? $name : $name_for_desc);
            foreach ($result->achieved as $milestone)
            {
                $inserter->bindValue(":State", MS_STATE_ACHIEVED);
                $inserter->bindValue(":Description", get_milestone_description($milestone, $name_to_use));
                $inserter->execute();
            }

            $gap = $result->next - $current_value;
            if ($gap <= $max_gap_to_next)
            {
                $inserter->bindValue(":State", MS_STATE_NEXT);
                $inserter->bindValue(":Description", get_milestone_description($result->next, $name_to_use, $gap));
                $inserter->execute();
            }
        }
    }
?>
