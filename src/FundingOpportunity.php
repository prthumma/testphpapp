<?php

class FundingOpportunity{

  private $data = array();
  private $db = null;

  public function __construct(){
    $this->data = array();
  }

  public function setData($key, $value){

    //$this->data[$key] = $value; //return;

    if(!isset($this->data[$key])){
      $this->data[$key] = $value;
    }else if(is_array($this->data[$key])){
      $this->data[$key][] = $value;
    }else{
      $temp = $this->data[$key];
      $this->data[$key] = array($temp, $value);
    }
  }

  private function getFormattedData($key){
    $data = isset($this->data[$key]) ? $this->data[$key] : null;

    switch($key){
      case "PostDate":
      case "ApplicationsDueDate":
      case "ArchiveDate":
        $tempData = $this->formatDate($data);
        return $tempData ? "'{$tempData}'" : "NULLIF('','')::date";//postgresql
        //return $tempData ? "'{$tempData}'" : "NULL";//mysql
        break;

      case "FundingActivityCategory":
      case "FundingInstrumentType":
      case "EligibilityCategory":
        $tempData = $this->convertCodes($data);
        return "'{$tempData}'";
        break;

      case "NumberOfAwards":
      case "ModificationNumber":
        $tempData = $this->convertNumber($data);
        return $tempData ? $tempData : "NULLIF('','')::integer";//postgresql
        //return $tempData ? "'{$tempData}'" : "NULL";//mysql
        break;

      case "EstimatedFunding":
      case "AwardCeiling":
      case "AwardFloor":
        $tempData = $this->convertNumber($data);
        return $tempData ? $tempData : "cast(NULLIF('','') as double precision)";//postgresql
        //return $tempData ? "'{$tempData}'" : "NULL";//mysql
        break;

      case "FundingOppDescription":
      case "AgencyContact":
        $d = null;
        if(is_array($data)){

          $val = false;
          foreach($data as $key1 => $value){
            if(is_array($value)){
              $val = true;
            }
          }
          if($val){
            /*echo ("<br/>". "key->{$key}");
            print_r($data);
            print_r($this->data);
            echo "**";*/
          }

          $d = implode('', $data);
        }else{
          $d = $data;
        }
        $tempData = ($d ? pg_escape_string($this->db, $d) : null);//postgresql
        return $tempData ? "'{$tempData}'" : "NULLIF('','')::varchar";//postgresql

        //$tempData = ($d ? mysql_real_escape_string ( $d, $this->db) : null);//mysql
        //return $tempData ? "'{$tempData}'" : "NULL";//mysql
        break;
      default:
        /*
          OtherCategoryExplanation
          AgencyMailingAddress
          FundingOppTitle
          FundingOppNumber
          ApplicationsDueDateExplanation
          Location
          Office
          Agency

          CFDANumber
          AdditionalEligibilityInfo
          CostSharing
          ObtainFundingOppText



          FundingOppURL                   varchar(250),
          AgencyEmailAddress              varchar(80),
          AgencyEmailDescriptor           varchar(100)
        */
        $d = null;
        if(is_array($data)){

          $val = false;
          foreach($data as $key1 => $value){
            if(is_array($value)){
              $val = true;
            }
          }
          if($val){
            /*echo ("<br/>". "key->{$key}");
            print_r($data);
            print_r($this->data);
            echo "**";*/
          }

          $d = implode('###', $data);
        }else{
          $d = $data;
        }
        $tempData = ($d ? pg_escape_string($this->db, $d) : null);//postgresql
        return $tempData ? "'{$tempData}'" : "NULLIF('','')::varchar";//postgresql

        //$tempData = ($d ? mysql_real_escape_string ( $d, $this->db) : null);//mysql
        //return $tempData ? "'{$tempData}'" : "NULL";//mysql
        break;
    }
  }

  private function formatDate($value){
    if(is_numeric($value)){
      $date = DateTime::createFromFormat('mdY', $value);
      $err = DateTime::getLastErrors();
      return empty($err['errors']) ? $date->format('Y-m-d') : null;
    }

    return null;
  }

  private function convertCodes($value){
    if(is_array($value)){
      return implode(';', $value);
    }else if(!empty($value)){
      return $value;
    }

    return null;
  }

  private function convertNumber($value){
    if(is_numeric($value)){
      return $value;
    }

    return null;
  }

  function processData($db){
    $this->db = $db;
    $query = null;
    try{
      //echo 'xxxxxxxxxxxxxxxxx';
     // print_r($this->data);
      //echo 'sssssssssssss';
     // return;

      //$fo = new FundingOpportunity($dataRow, $this->db);
      $foNumber = $this->data['FundingOppNumber'];
      //print_r($dataRow);
      //echo "foNumber>>>>>>>>>>>.{$foNumber}:";
      if(empty($foNumber)){
        return;
      }

      $result = pg_query($db, "SELECT id FROM fundingopportunity WHERE fundingoppnumber = '{$foNumber}'");
      $rows = pg_num_rows($result);

      //$result = mysql_query("SELECT Id FROM fundingopportunity WHERE fundingoppnumber = '{$foNumber}'", $this->db); //mysql
      //$rows = mysql_num_rows($result);//mysql

      if($rows == 0 ){
        $query = "INSERT INTO fundingopportunity(
            postdate, modificationnumber, fundinginstrumenttype, fundingactivitycategory,
            othercategoryexplanation, numberofawards, estimatedfunding, awardceiling,
            awardfloor, agencymailingaddress, fundingopptitle, fundingoppnumber,
            applicationsduedate, applicationsduedateexplanation, archivedate,
            location, office, agency, fundingoppdescription, cfdanumber,
            eligibilitycategory, additionaleligibilityinfo, costsharing,
            obtainfundingopptext, fundingoppurl, agencycontact, agencyemailaddress,
            agencyemaildescriptor)
        VALUES ({$this->getFormattedData('PostDate')}, {$this->getFormattedData('ModificationNumber')}, {$this->getFormattedData('FundingInstrumentType')}, {$this->getFormattedData('FundingActivityCategory')},
                {$this->getFormattedData('OtherCategoryExplanation')}, {$this->getFormattedData('NumberOfAwards')}, {$this->getFormattedData('EstimatedFunding')}, {$this->getFormattedData('AwardCeiling')},
                {$this->getFormattedData('AwardFloor')}, {$this->getFormattedData('AgencyMailingAddress')}, {$this->getFormattedData('FundingOppTitle')}, {$this->getFormattedData('FundingOppNumber')},
                {$this->getFormattedData('ApplicationsDueDate')}, {$this->getFormattedData('ApplicationsDueDateExplanation')}, {$this->getFormattedData('ArchiveDate')},
                {$this->getFormattedData('Location')}, {$this->getFormattedData('Office')}, {$this->getFormattedData('Agency')}, {$this->getFormattedData('FundingOppDescription')}, {$this->getFormattedData('CFDANumber')},
                {$this->getFormattedData('EligibilityCategory')}, {$this->getFormattedData('AdditionalEligibilityInfo')}, {$this->getFormattedData('CostSharing')},
                {$this->getFormattedData('ObtainFundingOppText')}, {$this->getFormattedData('FundingOppURL')}, {$this->getFormattedData('AgencyContact')}, {$this->getFormattedData('AgencyEmailAddress')},
                {$this->getFormattedData('AgencyEmailDescriptor')});";
      }else{
        $query = "UPDATE fundingopportunity
            set postdate = {$this->getFormattedData('PostDate')}, modificationnumber = {$this->getFormattedData('ModificationNumber')},
            fundinginstrumenttype = {$this->getFormattedData('FundingInstrumentType')}, fundingactivitycategory = {$this->getFormattedData('FundingActivityCategory')},
            othercategoryexplanation = {$this->getFormattedData('OtherCategoryExplanation')}, numberofawards = {$this->getFormattedData('NumberOfAwards')},
            estimatedfunding = {$this->getFormattedData('EstimatedFunding')}, awardceiling = {$this->getFormattedData('AwardCeiling')},
            awardfloor = {$this->getFormattedData('AwardFloor')}, agencymailingaddress  = {$this->getFormattedData('AgencyMailingAddress')},
            fundingopptitle = {$this->getFormattedData('FundingOppTitle')}, applicationsduedate = {$this->getFormattedData('ApplicationsDueDate')},
            applicationsduedateexplanation = {$this->getFormattedData('ApplicationsDueDateExplanation')}, archivedate = {$this->getFormattedData('ArchiveDate')},
            location = {$this->getFormattedData('Location')}, office = {$this->getFormattedData('Office')}, agency = {$this->getFormattedData('Agency')},
            fundingoppdescription = {$this->getFormattedData('FundingOppDescription')}, cfdanumber = {$this->getFormattedData('CFDANumber')},
            eligibilitycategory = {$this->getFormattedData('EligibilityCategory')}, additionaleligibilityinfo = {$this->getFormattedData('AdditionalEligibilityInfo')},
            costsharing = {$this->getFormattedData('CostSharing')}, obtainfundingopptext = {$this->getFormattedData('ObtainFundingOppText')}, fundingoppurl = {$this->getFormattedData('FundingOppURL')},
            agencycontact = {$this->getFormattedData('AgencyContact')}, agencyemailaddress = {$this->getFormattedData('AgencyEmailAddress')}, agencyemaildescriptor = {$this->getFormattedData('AgencyEmailDescriptor')},
            lastmodifieddate = now()
            WHERE fundingoppnumber = {$this->getFormattedData('FundingOppNumber')}";
      }
      //print $query;

      pg_query($db, $query);
      //mysql_query($query, $this->db);//mysql

    }catch (Exception $e){
      print ('QUERY>>>' . $query);
      print ('Exception>>>' . $e);
    }

  }
}