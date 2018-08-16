<?php
    namespace plough\stats;

    interface DataMapper
    {
        public function getMatchesPath($season, $from_date);
        public function getMatchDetailPath($pc_match_id);
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
            return $this->_root_path . "/matches.json";
        }
        
        public function getMatchDetailPath($pc_match_id)
        {
            return $this->_root_path . "/match_" . $pc_match_id . ".json";
        }
    }
    
    class WebDataMapper implements DataMapper
    {
        const URL_PREFIX = "http://play-cricket.com/api/v2/";
        const URL_SITE_ID = "8087";
        const URL_API_TOKEN = "cd3d9f47cef70496b9b3bfbab5231214";
    
        public function getMatchesPath($season, $from_date)
        {
            return $this->getUrlPrefix("matches") . "&site_id=" . WebDataMapper::URL_SITE_ID . 
                "&season=" . $season . "&from_entry_date=" . $from_date;
        }
        
        public function getMatchDetailPath($pc_match_id)
        {
            return $this->getUrlPrefix("match_detail") . "&match_id=" . $pc_match_id;
        }
        
        private function getUrlPrefix($command)
        {
            return WebDataMapper::URL_PREFIX . $command . ".json?api_token=" . WebDataMapper::URL_API_TOKEN;
        }
    }
?>