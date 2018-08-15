<?php
	namespace plough;
	
	const DIST_DIR = "dist";
	const ZIP_PATH = DIST_DIR . "/" . "plough.zip";
	
	function add_file($zip, $file_path)
	{
		$zip->addFile($file_path, "plough/" . $file_path);
	}
	
	function create_zip()
	{
		if (!file_exists(DIST_DIR))
			mkdir(DIST_DIR);

		if (file_exists(ZIP_PATH))
			unlink(ZIP_PATH);

		$zip = new \ZipArchive;
		if ($zip->open(ZIP_PATH, \ZipArchive::CREATE) === TRUE)
		{
			add_file($zip, "plough.php");
		    add_file($zip, "stats/init.php");
			add_file($zip, "stats/db.php");
			add_file($zip, "stats/update.php");

		    $zip->close();
		}

		echo "Generated plugin zip at " . ZIP_PATH . PHP_EOL;
	}
	
	create_zip();
?>