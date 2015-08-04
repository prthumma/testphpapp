<?php
try{
require_once($_SERVER['DOCUMENT_ROOT'] . '/src/FundingOpportunity.php');
fileLog('Started processing Funding opportunities from Grants.gov');

$timeStart = gettimeofday();

if(isset($_GET['errors']) && $_GET['errors'] == 'true'){
  error_reporting(E_ALL);
  ini_set('display_errors',1);
}

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

  xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
  xml_parser_set_option ($parser , XML_OPTION_CASE_FOLDING , 0 );
  xml_set_element_handler($parser, "startElements", "endElements");
  xml_set_character_data_handler($parser, "characterData");

  if (!($handle = fopen($xmlExtractedFile, "r"))){
    die("could not open XML input");
  }

  while($data = fread($handle, 4096))
  {
    xml_parse($parser, $data, feof($handle));
  }

  xml_parser_free($parser);

  sendFOStatusEmail();

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

  try{
    $subject = "Exception while processing Funding Opportunity.";
    $body = "Exception Details:\n";
      $body .= $e;
    $details = array('subject' => $subject, 'body' => $body);
    sendStatusEmail($details);
  }catch(Exception $me){
    fileLog("Status Mail Exception:" . $me);
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

function startElements($parser, $name, $attrs)
{
  global $fo, $element, $elementParsed, $elements;

  if(!empty($name))
  {
    if ($name == 'FundingOppModSynopsis' || $name == 'FundingOppSynopsis') {
      $fo = new FundingOpportunity();
    }

    if ($name == 'ObtainFundingOppText' || $name == 'AgencyContact') {
      if(is_array($attrs)){
        foreach($attrs as $key => $value){
          if (is_object($fo) && in_array($element, $elements))
          {
            $fo->setData($key, getRawData($value));
          }
        }
      }
    }
    $element = $name;
    $elementParsed[$element] = 'N';
  }
}

function endElements($parser, $name){
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
  global $dbConnStartTime;

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
    pg_close($db);//postgresql
    //mysql_close($db);//mysql
    $db = null;
    fileLog('Closed DB Connection.');
  }
}

function characterData($parser, $data)
{
  global $fo, $element, $elementParsed, $elements;
  if($elementParsed[$element] == 'N'){
    if (in_array($element, $elements))
    {
      $fo->setData($element, getRawData($data));
    }
  }
}

function getRawData($value){
  if(!$value){
    return $value;
  }

  $value =  htmlentities($value, ENT_NOQUOTES, 'WINDOWS-1252');
  $value = str_replace('&Acirc;', '', $value);
  $value = htmlspecialchars_decode($value);
  return $value;
}

function fileLog($message){
  error_log($message);
}

function sendFOStatusEmail(){
  global $db;

  $totalRecords = null;
  $result = pg_query($db, "SELECT count(Id) cnt FROM fundingopportunity");//postgresql
  $rows = pg_fetch_object($result)->cnt;//postgresql

  //$result = mysql_query("SELECT count(Id) FROM fundingopportunity", $db);//mysql
  //$rows = mysql_fetch_object($result);//mysql

  $subject = 'Mail notification from Funding Opportunity process.';
  $body = ('Total Number of funding opportunities:'. $rows);

  $details = array('subject' => $subject, 'body' => $body);

  sendStatusEmail($details);
}

function sendStatusEmail($details = array()){
  require_once ($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php');



  error_log('initialising email.');
  $sendgrid = new SendGrid('app35717248@heroku.com', 'imkt7foa4635');
  error_log('initialised email.');

  $message = new SendGrid\Email();

  $message->addTo('preddy@reisystems.com')->
    setFrom('preddy@reisystems.com')->
    setSubject($details['subject'])->
    setText($details['body']);

  $mailStatus = '';
  $response = $sendgrid->send($message);
  if(is_object($response)){
   $mailStatus = $response->message;
  }

  if($mailStatus == 'success'){
    error_log('Mail sent successfully.' + $mailStatus);
  }else{
    error_log('Mail could not be sent. ' + $mailStatus);
  }
}