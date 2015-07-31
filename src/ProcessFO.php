<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/src/FundingOpportunity.php');
fileLog('Started processing Funding opportunities from Grants.gov');
$timeStart = gettimeofday();

//set this if you need to see errors
if(isset($_GET['errors']) && $_GET['errors'] == 'true'){
  error_reporting(E_ALL);
  ini_set('display_errors',1);
}

// Tweak some PHP configurations if needed
//  ini_set('memory_limit','1536M'); // 1.5 GB
//ini_set('max_execution_time', 900); // 5 min
set_time_limit(0);

//clean up working directory
foreach (new DirectoryIterator(($_SERVER['DOCUMENT_ROOT'] . '/files/working')) as $fileInfo) {
  if(!$fileInfo->isDot()) {
    unlink($fileInfo->getPathname());
  }
}
fileLog('Cleaned up directory '. ($_SERVER['DOCUMENT_ROOT'] . '/files/working'));
//Download today file
$todayDate = date('Ymd');
$todayFile = "GrantsDBExtract{$todayDate}";
$xmlUrl = "http://www.grants.gov/web/grants/xml-extract.html?p_p_id=xmlextract_WAR_grantsxmlextractportlet_INSTANCE_5NxW0PeTnSUa&p_p_lifecycle=2&p_p_state=normal&p_p_mode=view&p_p_cacheability=cacheLevelPage&p_p_col_id=column-1&p_p_col_pos=1&p_p_col_count=2&download={$todayFile}.zip";
$xmlZipFile = ($_SERVER['DOCUMENT_ROOT'] . "/files/working/{$todayFile}.zip");
$temp_file_contents = collect_file($xmlUrl);
write_to_file($temp_file_contents, $xmlZipFile);
fileLog("Downloaded file from {$xmlUrl}");
//unzip the file
$xmlExtractDir = ($_SERVER['DOCUMENT_ROOT'] . '/files/working');
$zip = new ZipArchive;
if ($zip->open($xmlZipFile) === TRUE) {
  $zip->extractTo($xmlExtractDir);
  $zip->close();
  fileLog("File Extracted.");
} else {
  fileLog("File Extraction failed.");
  return;
}

$xmlExtractedFile = "{$xmlExtractDir}/{$todayFile}.xml";

global $fo, $element, $elementParsed, $elements;

try{

  if (!file_exists($xmlExtractedFile)) {
    fileLog("File {$xmlExtractedFile} does not exist."); exit;
  }else {
    fileLog("Extracted Downloaded file {$xmlExtractedFile}");
  }

  setDBConn();

  $elements = array('PostDate','ModificationNumber','FundingInstrumentType','FundingActivityCategory',
    'OtherCategoryExplanation','NumberOfAwards','EstimatedFunding','AwardCeiling',
    'AwardFloor','AgencyMailingAddress','FundingOppTitle','FundingOppNumber',
    'ApplicationsDueDate','ApplicationsDueDateExplanation','ArchiveDate',
    'Location','Office','Agency','FundingOppDescription','CFDANumber',
    'EligibilityCategory','AdditionalEligibilityInfo','CostSharing',
    'ObtainFundingOppText','FundingOppURL','AgencyContact','AgencyEmailAddress',
    'AgencyEmailDescriptor');


  // Creates a new XML parser and returns a resource handle referencing it to be used by the other XML functions.
  $parser = xml_parser_create();

  xml_set_element_handler($parser, "startElements", "endElements");
  xml_set_character_data_handler($parser, "characterData");
  //xml_parser_set_option ($parser , XML_OPTION_TARGET_ENCODING , 'UTF-8' );
  xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "US-ASCII");
  xml_parser_set_option ($parser , XML_OPTION_CASE_FOLDING , 0 );

// open xml file
  if (!($handle = fopen($xmlExtractedFile, "r"))){
    die("could not open XML input");
  }

  while($data = fread($handle, 4096)) // read xml file
  {
    xml_parse($parser, $data, feof($handle));  // start parsing an xml document
  }

  xml_parser_free($parser); // deletes the parser



  closeDBConn();

  $end = gettimeofday();
  $totalTime = ($end['sec'] - $timeStart['sec']);
  fileLog("Total Time take to process: {$totalTime} sec.");
  fileLog("Completed processing the file.");

}catch (Exception $e){
  fileLog('Exception >>>>>>>>>>>>' . $e);
  closeDBConn();

  $end = gettimeofday();
  $totalTime = ($end['sec'] - $timeStart['sec']);
  fileLog("Exception: Total Time take to process: {$totalTime} ms");
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

// Called to this function when tags are opened
function startElements($parser, $name, $attrs)
{
  /*echo "\nSTART - {$name}\n";
  print_r($attrs);
  echo "\n";*/
  global $fo, $element, $elementParsed;

  if(!empty($name))
  {
    if ($name == 'FundingOppModSynopsis' || $name == 'FundingOppSynopsis') {
      $fo = new FundingOpportunity();
    }

    $element = $name;
    $elementParsed[$element] = 'N';
    //print_r($elementParsed);
  }
}

// Called to this function when tags are closed
function endElements($parser, $name)
{ //echo "\nEND - {$name}";
  global $fo, $db, $element, $elementParsed;
  if(!empty($name))
  {
    $elementParsed[$element] = 'Y';
    if ($name == 'FundingOppModSynopsis' || $name == 'FundingOppSynopsis') {
      if(is_object($fo)){
        resetDBConn();
        $fo->processData($db);
      }
    }
  }
}

function resetDBConn(){
  global $db, $dbConnStartTime;

  $dbConnEndTime = gettimeofday();
  $totalTime = ($dbConnEndTime['sec'] - $dbConnStartTime['sec']);
  if(($totalTime / 60) > 2){
    fileLog("Closing current database connection after {$totalTime} seconds");
    closeDBConn();
    fileLog("Getting new database connection");
    setDBConn();
  }
}

function setDBConn(){
  global $db, $dbConnStartTime;

  $dbConnStartTime = gettimeofday();

  $db = pg_connect(getenv('DATABASE_URL'));//postgresql
  //$db = mysql_connect('localhost:3306', 'root', '') or die("Unable to connect to MySQL");//mysql
  //$selected = mysql_select_db("performance_1204",$db) or die("Could not select examples");//mysql

  if (!$db) {
    fileLog("Database connection error."); exit;
  }

  fileLog("Got the database connection");
}

function closeDBConn(){
  global $db;

  if($db){
    pg_close($db);
    //mysql_close($db);//mysql
    $db = null;
  }
}

// Called on the text between the start and end of the tags
function characterData($parser, $data)
{
  global $fo, $element, $elementParsed, $elements;
  //echo ">>>>>>>>>.element>>>>{$element}";
  if($elementParsed[$element] == 'N'){
    //echo "\nDATA -> {$element} = {$data}";
    if (in_array($element, $elements))
    {
      $fo->setData($element, htmlspecialchars($data));
    }
  }
}

function fileLog($message){
  error_log($message);
}
