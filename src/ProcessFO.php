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
  //Download today's file
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
    throw new Exception("File Extraction failed.");
  }

  setDBConn();

  setGlobalData();

  $xmlExtractedFile = "{$xmlExtractDir}/{$todayFile}.xml";
  parseXMLFile($xmlExtractedFile);

  sendFOStatusEmail();

  closeDBConn();

  $end = gettimeofday();
  $totalTime = ($end['sec'] - $timeStart['sec']);
  fileLog("Total Time taken to process: {$totalTime} sec.");
  fileLog("Completed processing the file.");

}catch (Exception $e){
  fileLog('Exception >>>>>>>>>>>>' . $e);
  closeDBConn();

  $end = gettimeofday();
  $totalTime = ($end['sec'] - $timeStart['sec']);
  fileLog("Exception: Total Time taken to process: {$totalTime} sec");

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

function setGlobalData(){
  global $db, $acceptedElements, $accounts, $translateConfig, $dbType;

  fileLog('Setting global data');

  $acceptedElements = array('PostDate','ModificationNumber','FundingInstrumentType','FundingActivityCategory',
    'OtherCategoryExplanation','NumberOfAwards','EstimatedFunding','AwardCeiling',
    'AwardFloor','AgencyMailingAddress','FundingOppTitle','FundingOppNumber',
    'ApplicationsDueDate','ApplicationsDueDateExplanation','ArchiveDate',
    'Location','Office','Agency','FundingOppDescription','CFDANumber',
    'EligibilityCategory','AdditionalEligibilityInfo','CostSharing',
    'ObtainFundingOppText','FundingOppURL','AgencyContact','AgencyEmailAddress',
    'AgencyEmailDescriptor');

  if($dbType == 'mysql'){
    $result = mysql_query('SELECT name AS agency, sfid FROM account', $db);//mysql
    if(!$result){
      throw new Exception(pg_errormessage());
    }

    $accounts = array();
    while ($row = mysql_fetch_object($result)) {
      $agency = strtolower($row->agency);
      $accounts[$agency] = $row->sfid;
    }
  }else{
    $result = pg_query($db, 'SELECT name AS agency, sfid FROM salesforcemaster.account');//postgresql
    if(!$result){
      throw new Exception(pg_errormessage());
    }

    $accounts = array();
    while ($row = pg_fetch_object($result)) {
      $agency = strtolower($row->agency);
      $accounts[$agency] = $row->sfid;
    }
  }
  $result = null;
  fileLog('Set accounts data. Total Rows: ' . count($accounts));

  if($dbType == 'mysql'){
    $result = mysql_query('SELECT groupkey, inputvalue, outputvalue FROM datatranslateconfig', $db);//mysql
    if(!$result){
      throw new Exception(pg_errormessage());
    }

    $translateConfig = array();
    while ($row = mysql_fetch_object($result)) {
      $groupkey = $row->groupkey;
      if(!isset($translateConfig[$groupkey])){
        $translateConfig[$groupkey] = array();
      }
      $translateConfig[$groupkey][$row->inputvalue] = $row->outputvalue;
    }
  }else{
    $result = pg_query($db, 'SELECT groupkey, inputvalue, outputvalue FROM datatranslateconfig');//postgresql
    if(!$result){
      throw new Exception(pg_errormessage());
    }

    $translateConfig = array();
    while ($row = pg_fetch_object($result)) {
      $groupkey = $row->groupkey;
      if(!isset($translateConfig[$groupkey])){
        $translateConfig[$groupkey] = array();
      }
      $translateConfig[$groupkey][$row->inputvalue] = $row->outputvalue;
    }
  }
  $result = null;

  fileLog('Set translate config data. Total Rows: ' . count($translateConfig));
  fileLog('Set translate config data[FundingActivityCategory]. Total Rows: ' . count($translateConfig['grants.gov:FundingActivityCategory']));
  fileLog('Set translate config data[FundingInstrumentType]. Total Rows: ' . count($translateConfig['grants.gov:FundingInstrumentType']));
  fileLog('Set translate config data[EligibilityCategory]. Total Rows: ' . count($translateConfig['grants.gov:EligibilityCategory']));
}

function parseXMLFile($xmlExtractedFile){

  if (!file_exists($xmlExtractedFile)) {
    fileLog("File {$xmlExtractedFile} does not exist.");
    throw new Exception("File {$xmlExtractedFile} does not exist.");
  }else {
    fileLog("Extracted Downloaded file {$xmlExtractedFile}");
  }

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

function startElements($parser, $name, $attrs){
  global $fo, $element, $elementParsed, $acceptedElements;

  if(!empty($name))
  {
    if ($name == 'FundingOppModSynopsis' || $name == 'FundingOppSynopsis') {
      $fo = new FundingOpportunity();
    }

    if ($name == 'ObtainFundingOppText' || $name == 'AgencyContact') {
      if(is_array($attrs)){
        foreach($attrs as $key => $value){
          if (is_object($fo) && in_array($element, $acceptedElements))
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
  if($totalTime > 120){
    fileLog("Closing current database connection after {$totalTime} seconds");
    closeDBConn();
    fileLog("Getting new database connection");
    setDBConn();
  }
}

function setDBConn(){
  global $db, $dbConnStartTime, $dbType, $sfns, $dbSchema;

  $dbType = 'pg';
  if(isset($_GET['dbtype']) && !empty($_GET['dbtype'])){
    $dbType = $_GET['dbtype'];
  }

  $dbConnStartTime = gettimeofday();

  if($dbType == 'mysql'){
    $sfns = '';
    $dbSchema = '';
    $db = mysql_connect('localhost:3306', 'root', '') or die("Unable to connect to MySQL");//mysql
    $selected = mysql_select_db("performance_1204",$db) or die("Could not select examples");//mysql
  }else{
    $sfns = 'ggsmaster__';
    $dbSchema = 'salesforcemaster.';
    $db = pg_connect(getenv('DATABASE_URL'));//postgresql
  }

  if (!$db) {
    fileLog("Database connection error.");
    throw new Exception("Database connection error.");
  }

  fileLog("Got the database connection");
}

function closeDBConn(){
  global $db, $dbType;

  if($db){
    if($dbType == 'mysql'){
      mysql_close($db);//mysql
    }else{
      pg_close($db);//postgresql
    }

    $db = null;
    fileLog('Closed DB Connection.');
  }
}

function characterData($parser, $data){
  global $fo, $element, $elementParsed, $acceptedElements;
  if($elementParsed[$element] == 'N'){
    if (in_array($element, $acceptedElements))
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
  global $db, $dbType, $sfns, $dbSchema;

  $totalRecords = null;

  if($dbType == 'mysql'){
    $result = mysql_query("SELECT count(Id) As cnt FROM fundingopportunity", $db);//mysql
    $rows = mysql_fetch_object($result)->cnt;//mysql
  }else{
    $result = pg_query($db, "SELECT count(Id) As cnt FROM {$dbSchema}{$sfns}stgfoalead__c");//postgresql
    $rows = pg_fetch_object($result)->cnt;//postgresql
  }

  $subject = 'Mail notification from Funding Opportunity process.';
  $body = ('Total Number of funding opportunities:'. $rows);

  fileLog($body);
  $details = array('subject' => $subject, 'body' => $body);

  sendStatusEmail($details);
}

function sendStatusEmail($details = array()){
  global $dbType;

  if($dbType == 'mysql'){
    return;
  }

  require_once ($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php');

  fileLog('initialising email.');
  $sendgrid = new SendGrid('app35717248@heroku.com', 'imkt7foa4635');
  fileLog('initialised email.');

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
    fileLog('Mail sent successfully.' + $mailStatus);
  }else{
    fileLog('Mail could not be sent. ' + $mailStatus);
  }
}