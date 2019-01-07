<?php
    namespace plough\stats;

    interface DataMapper
    {
        public function getMatchesPath($season, $from_date);
        public function getMatchDetailPath($season, $pc_match_id);

        public function getLeagueTablePath($season);
    }

    class FileDataMapper implements DataMapper
    {
        private $_root_path;

        public function __construct($root_path)
        {
            $this->_root_path = $root_path;
        }

        public function getMatchesPath($season, $from_date)
        {
            return "$this->_root_path/$season/matches.json";
        }

        public function getMatchDetailPath($season, $pc_match_id)
        {
            return "$this->_root_path/$season/match_" . $pc_match_id . ".json";
        }

        public function getLeagueTablePath($season)
        {
            return "$this->_root_path/$season/league_table.html";
        }
    }

    class WebDataMapper implements DataMapper
    {
        const URL_PREFIX = "http://play-cricket.com/api/v2/";
        const URL_SITE_ID = "8087";
        const URL_API_TOKEN = "cd3d9f47cef70496b9b3bfbab5231214";

        public function getMatchesPath($season, $from_date)
        {
            return $this->getPlayCricketUrlPrefix("matches") . "&site_id=" . WebDataMapper::URL_SITE_ID .
                "&season=" . $season . "&from_entry_date=" . $from_date;
        }

        public function getMatchDetailPath($season, $pc_match_id)
        {
            return $this->getPlayCricketUrlPrefix("match_detail") . "&match_id=" . $pc_match_id;
        }

        public function getLeagueTablePath($season)
        {
            return "";
        }

        private function getPlayCricketUrlPrefix($command)
        {
            return WebDataMapper::URL_PREFIX . $command . ".json?api_token=" . WebDataMapper::URL_API_TOKEN;
        }
    }
?>
