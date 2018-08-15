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
?>