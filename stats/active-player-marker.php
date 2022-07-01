<?php
    namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");
    require_once("config.php");
    require_once("db.php");
    require_once("helpers.php");

    class ActivePlayerMarker
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

        public function mark_active_players()
        {
            $db = $this->_db;

            // Start with static base active data that we update at the end of each season
            $player_active_map = $this->load_active_players_base();

            // Update with all players that played a game in the current season (making them active)
            $statement = $db->prepare(
               'SELECT DISTINCT
                    p.Name
                FROM Player p
                INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
                INNER JOIN Match m on m.MatchId = pp.MatchId
                WHERE
                        m.Season = :Season
                    AND m.CompetitionType <> \'Tour\'
                ORDER BY Name
                ');
            $statement->bindValue(":Season", $this->_config->getCurrentSeason());
            $result = $statement->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC))
                $player_active_map[$row["Name"]] = true;

            // Set active flags in DB
            $update_player = db_create_update_player_active($db);
            foreach ($player_active_map as $player_name => $active)
            {
                $update_player->bindValue(":Name", $player_name);
                $update_player->bindValue(":Active", $active);
                $update_player->execute();
            }
        }

        private function load_active_players_base()
        {
            $result = array();
            $active_players_path = $this->_config->getStaticDir() . "/active-players-base.csv";
            if (file_exists($active_players_path))
            {
                $base = fopen($active_players_path, "r");
                while ($row = fgetcsv($base))
                {
                    if ($row[0] == "Player")
                    {
                        $idx = array_flip($row);
                    }
                    else
                    {
                        $name = $row[$idx["Player"]];
                        $active_str = $row[$idx["Active"]];
                        $active = ($active_str == "Y");

                        $result[$name] = $active;
                    }
                }
            }

            return $result;
        }
    }
?>
