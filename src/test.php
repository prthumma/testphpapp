<?php
error_log('test.php....1');
//require_once ('../vendor/autoload.php');
require_once ($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php');

error_log('test.php....2');
if($_GET['errors'] == 'true'){
  error_reporting(E_ALL);
  ini_set('display_errors',1);
}

try{
  /*$db = pg_connect(getenv('DATABASE_URL'));
  if (!$db) {
      echo "Database connection error.";
      exit;
  }else{
    $query = "INSERT INTO fundingopportunity(
            postdate, modificationnumber, fundinginstrumenttype, fundingactivitycategory,
            othercategoryexplanation, numberofawards, estimatedfunding, awardceiling,
            awardfloor, agencymailingaddress, fundingopptitle, fundingoppnumber,
            applicationsduedate, applicationsduedateexplanation, archivedate,
            location, office, agency, fundingoppdescription, cfdanumber,
            eligibilitycategory, additionaleligibilityinfo, costsharing,
            obtainfundingopptext, fundingoppurl, agencycontact, agencyemailaddress,
            agencyemaildescriptor)
    VALUES ('12/30/2015', 10, 'G;CA', 'AG;AR',
            'othercategoryexplanation-prab', 10, 111111.11, 22222.22,
            3333.33, 'agencymailingaddress', 'New FO 08', 'DHS-15-MT-082-01-02',
            '01/31/2015', 'test', '03/31/2015',
            'location', 'OCO NDGRANTS 02', 'Department of Homeland Security - FEMA', 'test', '97.082',
            '00;01', 'Not Available', 'N',
            'News', 'http://www.cnn.com', 'Salman Arshad&lt;br/&gt;Tester&lt;br/&gt;Phone 123456789', 'sarshad@dminc.com',
            'Contact');";

    $result = pg_query($db, $query);
    print $result;
  }*/


  $sendgrid = new SendGrid('app35717248@heroku.com', 'imkt7foa4635');

  error_log('test.php....4');

  $message = new SendGrid\Email();

  error_log('test.php....5');

  $message->addTo('preddy@reisystems.com')->
    setFrom('preddy@reisystems.com')->
    setSubject('test subject from heroku')->
    setText('test message from heroku')->
    setHtml('<strong>Hello World!</strong>');

  error_log('test.php....6');

  $response = $sendgrid->send($message);
echo "sent email";
  error_log('test.php....7');
}catch (Exception $e){
  error_log('test.php....8');
print 'EX>>>'.$e;
  error_log('Exception >>>>>>>>>>>>' . $e);
  error_log('test.php....9');
}