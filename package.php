<?php
	namespace plough;
	
	const DIST_DIR = "dist";
	const ZIP_PATH = DIST_DIR . "/" . "plough.zip";
	
	function add_file($zip, $file_path)
	{
        echo "Adding [$file_path]" . PHP_EOL;
        if (!file_exists($file_path))
            throw new \Exception("File not found: " . $file_path);
        
		$zip->addFile($file_path, "plough/" . $file_path);
	}
	
	function create_zip()
	{
        echo PHP_EOL;
        
		if (!file_exists(DIST_DIR))
			mkdir(DIST_DIR);

		if (file_exists(ZIP_PATH))
			unlink(ZIP_PATH);

		$zip = new \ZipArchive;
		if ($zip->open(ZIP_PATH, \ZipArchive::CREATE) === TRUE)
		{
            add_file($zip, "plough.php");
            add_file($zip, "utils.php");
            
			add_file($zip, "logger.php");
            add_file($zip, "Psr/Log/AbstractLogger.php");
            add_file($zip, "Psr/Log/LoggerInterface.php");
            add_file($zip, "Psr/Log/LogLevel.php");
            
		    add_file($zip, "stats/config.php");
            add_file($zip, "stats/data-mapper.php");
            add_file($zip, "stats/db.php");
            add_file($zip, "stats/init.php");
			add_file($zip, "stats/updater.php");
            
            add_file($zip, "stats/config/default.xml");
            
            add_file($zip, "stats/static/career-stats-batting-end-2017.csv");
            add_file($zip, "stats/static/career-stats-bowling-end-2017.csv");
            add_file($zip, "stats/static/career-stats-fielding-end-2017.csv");

		    $zip->close();
		}

		echo "\nGenerated plugin zip at " . ZIP_PATH . PHP_EOL;
	}
	
	create_zip();
?>