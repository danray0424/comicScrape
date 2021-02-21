#!/usr/bin/php

<?

require_once __DIR__.'/vendor/autoload.php';

use Cocur\Slugify\Slugify;

$getopt = new \GetOpt\GetOpt([
    \GetOpt\Option::create('t', 'title', \GetOpt\GetOpt::MULTIPLE_ARGUMENT),
    \GetOpt\Option::create('s', 'silent'),
]);
$getopt->process();
$titles = $getopt->getOption('title');
$silent = $getopt->getOption('silent') ? true : false;


$file = file_get_contents('./scrapeConfig.json');
$config = json_decode($file);


if (array_count_values($titles) > 0) {
	$titles = file($config->titlesFile);
}

ini_set('memory_limit', '10G');


$savedIssues = [''];
foreach ($titles as $title) { //Go through titles from file

	// handle case where files have to be searched with different strings
	// than the download filename ("Once & Future" has to be searched "Once and Future")
	$searchterm = rtrim($title);
	if ( stristr($title, '|')) {
		[$searchterm, $filename] = explode('|', $title);
		$title = $filename ? $filename : $searchterm;
	}

	//Clean up filename, make directory if not exist
	$title = rtrim($title);
	$targetdir = $config->comicDirectory . '/' . $title;
	if (!file_exists($targetdir)) {
	    mkdir($targetdir, 0777, true);
	}

	//Look in the directory for existing files, find the highest-numbered one.
	$files = scandir($targetdir);
	
	$latestIssueOwned = 0;
	foreach (array_reverse($files) as $file) {
		if (preg_match("/$title\s+#?v?(\d+)/i", $file, $matches)) {
			$foundIssue = (Int)$matches[1];
			$latestIssueOwned = $foundIssue > $latestIssueOwned ? $foundIssue : $latestIssueOwned;
		}
	}
	if (!$silent) echo "Last saved issue of $title is $latestIssueOwned\n";
	$wanted = $latestIssueOwned + 1;


	//collect up our search results page contents
	$pages = '';
	if (!$silent) echo "Getting search page ";
	for ($i=1; $i <= $config->maxPageDepth ; $i++) { 
		//Get whatever page we're on
		$pagebase = $config->siteUrl . "/page/$i";
		$searchurl = $pagebase . '/' . $config->queryFormat . urlencode($searchterm) ;
		if (!$silent) echo "$i... ";
		$pages  .= @file_get_contents($searchurl); 
			// Fun fact! "@" in front of this command suppresses errors. It's entirely
			// valid that one of our searches might not have a second or third page, and
			// we don't need to bug the user about that.

	}
	if (!$silent) echo "\n";

	while(true) {
		// Clean up our inputs and define the issue slug we're going to searh for.
		$slugify = new Slugify();
		$titleSlug = $slugify->slugify($searchterm);
		$comicvine_search = urlencode($searchterm . " " . $wanted);
		$targeturl = $config->siteUrl . '/[^/]+/' . $titleSlug . '-' . $wanted . '[^\d"]+';
		$targeturl = str_replace('/', '\/', $targeturl);


		$found = false;
		if (preg_match("/$targeturl/", $pages, $matches)) { //Found it
			$issueurl = $matches[0];

			// Get the issue detail page
			$issuepage = file_get_contents($issueurl);
#			file_put_contents("issue.txt", $issuepage);

			// Search that page for the download link
			$targettag = '/href="([^"]+)"[^>]+title="Download Now"/';
			preg_match($targettag, $issuepage, $matches);
			$issueURL = $matches[1];
		
			// If we didn't find our target url, break this loop and try the next page.	
			if (! $issueURL) {
				continue;
			}

			// Set up the download with good referer value (anti-antiscraping measure)
			$referer = $issueurl;
			$opts = array(
			       'http'=>array(
			           'header'=>array("Referer: $referer\r\n")
			       )
			);
			$context = stream_context_create($opts);

			// Hit comicvine for details about this issue
			$cvdoc = file_get_contents("https://comicvine.gamespot.com/api/search/?api_key=$config->ComicvineKey&format=json&sort=name:asc&resources=issue&query=" . urlencode($comicvine_search));
			$cvdata = json_decode($cvdoc);
			$issue_title = $cvdata->results[0]->name;
			if ($issue_title) {
				$issue_title = " - $issue_title";
			}
			$issue_date = $cvdata->results[0]->cover_date;
			$issue_date = substr($issue_date, 0, 4);
			$issue_date = " ($issue_date)";


			if (!$silent) echo "  Found issue $wanted. Downloading... ";

			//Get the actual thing and save it.
			$issuebinary = file_get_contents($issueURL, false, $context);

			//Figure out where to save it (get filename from headers, or fall back to comicvine details)
			$filename = get_real_filename($http_response_header, $issueURL);
			if (!$silent) echo "  Done. Filename: $filename\n";
			if (!$filename) {
				$filename = $title . " " . sprintf("%03d", $wanted) . $issue_title . $issue_date . '.cbz';
			}
			$targetfilename = $config->comicDirectory . '/' . $title . '/' . $filename;
			$savedIssues[] = $targetfilename;

			file_put_contents($targetfilename, $issuebinary);

			// Set up flags and switches for next loop
			$found = true;
			$wanted++;
			continue;
		} // end Found It 
		break; //We fell through here without finding a "next" issue, so bail.
	} //end while true
} //end foreach title

if (!$silent) echo "\n------\nRun Complete. " . array_count_values($savedIssues) . " downloaded issues.";
foreach ($savedIssues as $issuepath) {
	if (!$silent) echo " $issuepath\n";
}


function get_real_filename($headers,$url)
{
    foreach($headers as $header)
    {
        if (strpos(strtolower($header),'location') !== false)
        {
            preg_match('/\/([^\/]+)$/', urldecode($header), $matches);
            return $matches[1];
        }
    }
}
