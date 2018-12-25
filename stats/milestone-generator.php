<?php
    namespace plough\stats;

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
            $result = $result . " ($gap needed)";

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

        public function generate_milestones()
        {
            $db = $this->_db;
            $inserter = db_create_insert_milestone($db);
            db_truncate_table($db, "Milestone");

            // Fetch start / current data for fields of interest
            $statement = $db->prepare(
               'SELECT
                     p.PlayerId
                    ,p.Active as PlayerActive
                    ,bab.Matches as MatchesStart
                    ,bac.Matches as MatchesCurrent
                    ,bab.Runs as RunsStart
                    ,bac.Runs as RunsCurrent
                    ,bob.Wickets as WicketsStart
                    ,boc.Wickets as WicketsCurrent
                    ,fb.CatchesFielding as CatchesStart
                    ,fc.CatchesFielding as CatchesCurrent
                    ,fb.CatchesKeeping as KeepingCatchesStart
                    ,fc.CatchesKeeping as KeepingCatchesCurrent
                FROM Player p
                LEFT JOIN CareerBattingSummaryBase bab ON bab.PlayerId = p.PlayerId
                INNER JOIN CareerBattingSummary bac ON bac.PlayerId = p.PlayerId
                LEFT JOIN CareerBowlingSummaryBase bob ON bob.PlayerId = p.PlayerId
                INNER JOIN CareerBowlingSummary boc ON boc.PlayerId = p.PlayerId
                LEFT JOIN CareerFieldingSummaryBase fb ON fb.PlayerId = p.PlayerId
                INNER JOIN CareerFieldingSummary fc ON fc.PlayerId = p.PlayerId
                ORDER BY p.PlayerId
                ');
            $result = $statement->execute();

            // Calculate relevant milestones for active players
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
            {
                if ($row["PlayerActive"])
                {
                    // General
                    $this->calculate_and_store(
                        $inserter, $row, MS_TYPE_GENERAL, "Matches", $this->_msValuesMatches, MS_GAP_MATCHES
                        );

                    // Batting
                    $this->calculate_and_store(
                        $inserter, $row, MS_TYPE_BATTING, "Runs", $this->_msValuesRuns, MS_GAP_RUNS
                        );

                    // Bowling
                    $this->calculate_and_store(
                        $inserter, $row, MS_TYPE_BOWLING, "Wickets", $this->_msValuesWickets, MS_GAP_WICKETS
                        );

                    // Fielding
                    $this->calculate_and_store(
                        $inserter, $row, MS_TYPE_FIELDING, "Catches", $this->_msValuesCatches, MS_GAP_CATCHES
                        );

                    // Keeping
                    $this->calculate_and_store(
                        $inserter, $row, MS_TYPE_FIELDING, "KeepingCatches", $this->_msValuesKeepingCatches, MS_GAP_KEEPING_CATCHES, "keeping catches"
                        );
                }
            }
        }

        private function calculate_and_store(
            $inserter, $player_data, $type, $name, $value_list, $max_gap_to_next, $name_for_desc = null
            )
        {
            // Calculate
            $start_value = $player_data[$name . "Start"];
            if (is_null($start_value))
                $start_value = 0;
            $current_value = $player_data[$name . "Current"];
            $result = calculate_milestones($start_value, $current_value, $value_list);

            // Store
            $inserter->bindValue(":PlayerId", $player_data["PlayerId"]);
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
