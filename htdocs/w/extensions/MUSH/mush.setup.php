<?


$wgExtensionFunctions[] = 'efMUSHSetup';
$wgHooks['ArticleSaveComplete'][] = 'efMediaInfoSave';
$wgHooks['ArticleDelete'][] = 'efMediaInfoDelete';
$wgHooks['ArticleDeleteComplete'][] = 'efMediaInfoDeleteComplete';
$wgHooks['ArticleSaveComplete'][] = 'efLogInfoSave';
$wgHooks['ArticleDelete'][] = 'efLogInfoDelete';
$wgHooks['ArticleDeleteComplete'][] = 'efLogInfoDeleteComplete';
$wgAutoloadClasses['LogInfo'] = dirname(__FILE__) . '/LogInfo.php';
$wgAutoloadClasses['MediaInfo'] = dirname(__FILE__) . '/MediaInfo.php';
$wgAutoloadClasses['Searchable'] = dirname(__FILE__) . '/Searchable.php';
$wgAutoloadClasses['LogSearch'] = dirname(__FILE__) . '/LogSearch.php';
$wgAutoloadClasses['MediaSearch'] = dirname(__FILE__) . '/MediaSearch.php';
$wgAutoloadClasses['MUSHRobot'] = dirname(__FILE__) . '/MUSHRobot.php';
$wgAutoloadClasses['LogUploader'] = dirname(__FILE__) . '/LogUploader.php';
$wgAutoloadClasses['LogScrubber'] = dirname(__FILE__) . '/LogScrubber.php';
$wgSpecialPages['LogUploader'] = 'LogUploader';
$wgLocalPath = str_replace("\\", "/", dirname(dirname(dirname(dirname(__FILE__)))));

# Upload Log Permission
$wgGroupPermissions['*']['uploadlog'] = false;
$wgGroupPermissions['user']['uploadlog'] = true;
$wgAvailableRights[] = 'uploadlog';




function efMUSHSetup() {
    global $wgParser;
    $wgParser->setHook( 'mediainfo', 'efMediaInfoRender' );
    $wgParser->setHook( 'mediasearch', 'efMediaSearchRender' );
    $wgParser->setHook( 'loginfo', 'efLogInfoRender' );
    $wgParser->setHook( 'logsearch', 'efLogSearchRender' );
	$wgParser->setHook( 'date', "displayDate");
}
 
function efMediaInfoRender( $input, $args, $parser ) {
	$parser->disableCache();
	global $mediaInfo;
	$mediaInfo = new MediaInfo();
    $mediaInfo->parse( $input );
    return $parser->recursiveTagParse( $mediaInfo->render() );
}

function efMediaSearchRender( $input, $args, $parser ) {
	$parser->disableCache();
	$mediaSearch = new MediaSearch( $args );
    $mediaSearch->parse( $input );
	$output = $mediaSearch->render();
	return $output;
}

function efMediaInfoSave( &$article ) {
	global $mediaInfo;		
	if ( !$mediaInfo ) {
		$mediaInfo = new MediaInfo();
	}
	
	return $mediaInfo->save( $article );
}

function efMediaInfoDelete( &$article ) {
	global $mediaInfo;
	$mediaInfo = new MediaInfo();
	$mediaInfo->page_id = $article->getID();
	return true;
}

function efMediaInfoDeleteComplete( &$article ) {
	global $mediaInfo;
	return $mediaInfo->delete( $article );
}

function efLogInfoRender( $input, $args, $parser ) {
	$parser->disableCache();
	global $logInfo;
	$logInfo = new LogInfo();
    $logInfo->parse( $input );
    return $parser->recursiveTagParse( $logInfo->render() );
}

function efLogSearchRender( $input, $args, $parser ) {
	$parser->disableCache();
	$logSearch = new LogSearch( $args );
    $logSearch->parse( $input );
	$output = $logSearch->render();
	return $output;
}

function efLogInfoSave( &$article ) {
	global $logInfo;		
	if ( !$logInfo ) {
		$logInfo = new LogInfo();
	}
	
	return $logInfo->save( $article );
}

function efLogInfoDelete( &$article ) {
	global $logInfo;
	$logInfo = new LogInfo();
	$logInfo->page_id = $article->getID();
	return true;
}

function efLogInfoDeleteComplete( &$article ) {
	global $logInfo;
	return $logInfo->delete( $article );
}

function displayDate($paramstring = "", $params = array()) {
	global $wgParser, $wgUser, $wgScriptPath, $wgLocalPath, $wgOut;
	$wgParser->disableCache();
	// grab the page title
	// check for the date "name" parameter.
	$name = "";
	if (isset($params["name"])) {
		$name = $params["name"];
	}

	$page = "";
	if (isset($params["page"])) {
		$page = $params["page"];
	}
	
	$path = str_replace($wgLocalPath, '', dirname(__FILE__));

	//http://champs.jaburo.net/wiki/extensions/CalendarAdjust.php?year=2055&month=12&title=Test_Calendar&name=Media&referer=%2Fwiki%2Findex.php%2FTest_Calendar
	if (preg_match('/(\d{4}).(\d{2}).(\d{2})/', $paramstring, $matches)) {
		$year = $matches[1];
		$month = $matches[2];
		$day = $matches[3];
		$datestring = sprintf('%02d/%02d/%s', intval($month), intval($day), $year);
		$url = sprintf($path . '/CalendarAdjust.php?year=%s&month=%s&title=%s&name=%s&referer=/wiki/%s', 
				$year, 
				$month, 
				'calendar', 
				htmlspecialchars($name), 
				$page
		);
		
		return "<a href=\"" . $url . "\">" . $datestring . "</a>";
	}

	return $paramstring;
}

?>