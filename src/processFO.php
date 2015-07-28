<?php

//set this if you need to see errors
if($_GET['errors'] == 'true'){
  error_reporting(E_ALL);
  ini_set('display_errors',1);
}

// Tweak some PHP configurations if needed
//  ini_set('memory_limit','1536M'); // 1.5 GB
ini_set('max_execution_time', 300); // 5 min

//clean up working directory
foreach (new DirectoryIterator(($_SERVER['DOCUMENT_ROOT'] . '/files/working')) as $fileInfo) {
  if(!$fileInfo->isDot()) {
    unlink($fileInfo->getPathname());
  }
}

//Download today file
$todayDate = date('Ymd');
$todayFile = "GrantsDBExtract{$todayDate}";
$xmlUrl = "http://training.grants.gov/web/grants/xml-extract.html?p_p_id=xmlextract_WAR_grantsxmlextractportlet_INSTANCE_5NxW0PeTnSUa&p_p_lifecycle=2&p_p_state=normal&p_p_mode=view&p_p_cacheability=cacheLevelPage&p_p_col_id=column-1&p_p_col_pos=1&p_p_col_count=2&download={$todayFile}.zip";
$xmlZipFile = ($_SERVER['DOCUMENT_ROOT'] . "/files/working/{$todayFile}.zip");
$temp_file_contents = collect_file($xmlUrl);
write_to_file($temp_file_contents, $xmlZipFile);

//unzip the file
$xmlExtractDir = ($_SERVER['DOCUMENT_ROOT'] . '/files/working');
$zip = new ZipArchive;
if ($zip->open($xmlZipFile) === TRUE) {
  $zip->extractTo($xmlExtractDir);
  $zip->close();
  echo 'ok. File Extracted';
} else {
  echo 'failed';
  return;
}


$xmlExtractedFile = "{$xmlExtractDir}/{$todayFile}.xml";
try{

  if (!file_exists($xmlExtractedFile)) {
    print 'File does not exist'; return;
  }else {
    print "File {$xmlExtractedFile} exists";
  }

  $handle = fopen($xmlExtractedFile, 'r');
  //$dataRows = array();
  $db = pg_connect(getenv('DATABASE_URL'));
  if (!$db) {
    echo "Database connection error.";
    exit;
  }
  // Get the nodestring incrementally from the xml file by defining a callback
  // In this case using a anon function.
  nodeStringFromXMLFile($handle, '<FundingOppSynopsis>', '</FundingOppSynopsis>', function($nodeText, $db){
    // Transform the XMLString into an array and
    $dataRow = getArrayFromXMLString($nodeText);
    processDataRow($db, $dataRow);
  }, $db);

  fclose($handle);

}catch (Exception $e){
  print ('Exception >>>>>>>>>>>>' . $e);
}

include_once('FundingOpportunity.php');

function processDataRow($db, $dataRow){
  try{

      $fo = new FundingOpportunity($dataRow);

      $query = "INSERT INTO fundingopportunity(
            postdate, modificationnumber, fundinginstrumenttype, fundingactivitycategory,
            othercategoryexplanation, numberofawards, estimatedfunding, awardceiling,
            awardfloor, agencymailingaddress, fundingopptitle, fundingoppnumber,
            applicationsduedate, applicationsduedateexplanation, archivedate,
            location, office, agency, fundingoppdescription, cfdanumber,
            eligibilitycategory, additionaleligibilityinfo, costsharing,
            obtainfundingopptext, fundingoppurl, agencycontact, agencyemailaddress,
            agencyemaildescriptor)
      /* VALUES ('12/30/2015', 10, 'G;CA', 'AG;AR',
            'othercategoryexplanation-prab', 10, 111111.11, 22222.22,
            3333.33, 'agencymailingaddress', 'New FO 08', 'DHS-15-MT-082-01-02',
            '01/31/2015', 'test', '03/31/2015',
            'location', 'OCO NDGRANTS 02', 'Department of Homeland Security - FEMA', 'test', '97.082',
            '00;01', 'Not Available', 'N',
            'News', 'http://www.cnn.com', 'Salman Arshad&lt;br/&gt;Tester&lt;br/&gt;Phone 123456789', 'sarshad@dminc.com',
            'Contact');*/
        VALUES ($fo->getFormattedData('PostDate'), $fo->getFormattedData('ModificationNumber'), $fo->getFormattedData('FundingInstrumentType'), $fo->getFormattedData('FundingActivityCategory'),
                $fo->getFormattedData('OtherCategoryExplanation'), $fo->getFormattedData('NumberOfAwards'), $fo->getFormattedData('EstimatedFunding'), $fo->getFormattedData('AwardCeiling'),
                $fo->getFormattedData('AwardFloor'), $fo->getFormattedData('AgencyMailingAddress'), $fo->getFormattedData('FundingOppTitle'), $fo->getFormattedData('FundingOppNumber'),
                $fo->getFormattedData('ApplicationsDueDate'), $fo->getFormattedData('ApplicationsDueDateExplanation'), $fo->getFormattedData('ArchiveDate'),
                $fo->getFormattedData('Location'), $fo->getFormattedData('Office'), $fo->getFormattedData('Agency'), $fo->getFormattedData('FundingOppDescription'), $fo->getFormattedData('CFDANumber'),
                $fo->getFormattedData('EligibilityCategory'), $fo->getFormattedData('AdditionalEligibilityInfo'), $fo->getFormattedData('CostSharing'),
                $fo->getFormattedData('ObtainFundingOppText'), $fo->getFormattedData('FundingOppURL'), $fo->getFormattedData('AgencyContact'), $fo->getFormattedData('AgencyEmailAddress'),
                $fo->getFormattedData('AgencyEmailDescriptor'));";

      pg_query($db, $query);
  }catch (Exception $e){
    print $e;
  }

}

function collect_file($url){
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_VERBOSE, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_AUTOREFERER, false);
  curl_setopt($ch, CURLOPT_REFERER, "http://www.xcontest.org");
  curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  $result = curl_exec($ch);
  curl_close($ch);
  return($result);
}

function write_to_file($text, $new_filename){
  $fp = fopen($new_filename, 'w');
  fwrite($fp, $text);
  fclose($fp);
}

/**
 * For every node that starts with $startNode and ends with $endNode call $callback
 * with the string as an argument
 *
 * Note: Sometimes it returns two nodes instead of a single one, this could easily be
 * handled by the callback though. This function primary job is to split a large file
 * into manageable XML nodes.
 *
 * the callback will receive one parameter, the XML node(s) as a string
 *
 * @param resource $handle - a file handle
 * @param string $startNode - what is the start node name e.g <item>
 * @param string $endNode - what is the end node name e.g </item>
 * @param callable $callback - an anonymous function
 */
function nodeStringFromXMLFile($handle, $startNode, $endNode, $callback=null, $db) {
  $cnt = 0;
  $cursorPos = 0;
  while(true) {
    // Find start position
    $startPos = getPos($handle, $startNode, $cursorPos);
    // We reached the end of the file or an error
    if($startPos === false) {
      break;
    }
    // Find where the node ends
    $endPos = getPos($handle, $endNode, $startPos) + strlen($endNode);
    // Jump back to the start position
    fseek($handle, $startPos);
    // Read the data
    $data = fread($handle, ($endPos-$startPos));
    // pass the $data into the callback
    if(!empty($data))
      $callback($data, $db);
    // next iteration starts reading from here

    $cnt++;
    if($cnt > 0){
      return;
    }
    $cursorPos = ftell($handle);
  }
}

/**
 * This function will return the first string it could find in a resource that matches the $string.
 *
 * By using a $startFrom it recurses and seeks $chunk bytes at a time to avoid reading the
 * whole file at once.
 *
 * @param resource $handle - typically a file handle
 * @param string $string - what string to search for
 * @param int $startFrom - strpos to start searching from
 * @param int $chunk - chunk to read before rereading again
 * @return int|bool - Will return false if there are EOL or errors
 */
function getPos($handle, $string, $startFrom=0, $chunk=1024, $prev='') {
  // Set the file cursor on the startFrom position
  fseek($handle, $startFrom, SEEK_SET);
  // Read data
  $data = fread($handle, $chunk);
  // Try to find the search $string in this chunk
  $stringPos = strpos($prev.$data, $string);
  // We found the string, return the position
  if($stringPos !== false ) {
    return $stringPos+$startFrom - strlen($prev);
  }
  // We reached the end of the file
  if(feof($handle)) {
    return false;
  }
  // Recurse to read more data until we find the search $string it or run out of disk
  return getPos($handle, $string, $chunk+$startFrom, $chunk, $data);
}

/**
 * Turn a string version of XML and turn it into an array by using the
 * SimpleXML
 *
 * @param string $nodeAsString - a string representation of a XML node
 * @return array
 */
function getArrayFromXMLString($nodeAsString) {
  $simpleXML = simplexml_load_string($nodeAsString);
  if(libxml_get_errors()) {
    user_error('Libxml throws some errors.', implode(',', libxml_get_errors()));
  }
  return simplexml2array($simpleXML);
}

/**
 * Turns a SimpleXMLElement into an array
 *
 * @param SimpleXMLelem $xml
 * @return array
 */
function simplexml2array($xml) {
  if(is_object($xml) && get_class($xml) == 'SimpleXMLElement') {
    $attributes = $xml->attributes();
    foreach($attributes as $k=>$v) {
      $a[$k] = (string) $v;
    }
    $x = $xml;
    $xml = get_object_vars($xml);
  }

  if(is_array($xml)) {
    if(count($xml) == 0) {
      return (string) $x;
    }
    $r = array();
    foreach($xml as $key=>$value) {
      $r[$key] = simplexml2array($value);
    }
    // Ignore attributes
    if (isset($a)) {
      $r['@attributes'] = $a;
    }
    return $r;
  }
  return (string) $xml;
}