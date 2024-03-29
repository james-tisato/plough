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

    function add_dir($zip, $dir_path) {
        $nodes = glob($dir_path . '/*');
        foreach ($nodes as $node)
        {
            if (is_dir($node))
                add_dir($zip, $node);
            else if (is_file($node))
                add_file($zip, $node);
        }
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

            add_file($zip, "stats/active-player-marker.php");
            add_file($zip, "stats/career-summary-generator.php");
            add_file($zip, "stats/config.php");
            add_file($zip, "stats/csv-generator.php");
            add_file($zip, "stats/data-mapper.php");
            add_file($zip, "stats/db.php");
            add_file($zip, "stats/helpers.php");
            add_file($zip, "stats/init.php");
            add_file($zip, "stats/league-table-consumer.php");
            add_file($zip, "stats/match-consumer.php");
            add_file($zip, "stats/milestone-generator.php");
            add_file($zip, "stats/season-summary-generator.php");
            add_file($zip, "stats/summary-aggregator.php");
            add_file($zip, "stats/updater.php");

            add_file($zip, "stats/config/default.xml");

            add_file($zip, "stats/static/active-players-base.csv");
            add_file($zip, "stats/static/career-stats-batting-end-2017.csv");
            add_file($zip, "stats/static/career-stats-bowling-end-2017.csv");
            add_file($zip, "stats/static/career-stats-fielding-end-2017.csv");
            add_file($zip, "stats/static/career-stats-matches-end-2017.csv");
            add_file($zip, "stats/static/career-stats-partnerships-end-2017.csv");

            add_file($zip, "tablepress/jquery.datatables.sorting-plugins-plough.js");
            add_file($zip, "tablepress/sorting_plugins.php");

            add_dir($zip, "vendor");

            $zip->close();
        }

        echo "\nGenerated plugin zip at " . ZIP_PATH . PHP_EOL;
    }

    create_zip();
?>
