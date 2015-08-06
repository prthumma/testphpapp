<?php

class FundingOpportunity{

  private $data = array();
  private $db = null;

  public function __construct(){
    $this->data = array();
  }

  public function setData($key, $value){
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
    global $dbType;
    $data = isset($this->data[$key]) ? $this->data[$key] : null;

    switch($key){
      case "PostDate":
      case "ApplicationsDueDate":
      case "ArchiveDate":
        $tempData = $this->formatDate($data);
        if($dbType == 'mysql'){
          return $tempData ? "'{$tempData}'" : "NULL";//mysql
        }else{
          return $tempData ? "'{$tempData}'" : "NULLIF('','')::date";//postgresql
        }
        break;

      case "FundingActivityCategory":
      case "FundingInstrumentType":
      case "EligibilityCategory":
        $tempData = $this->translateCodes(('grants.gov:'.$key), $data);
        return "'{$tempData}'";
        break;

      case "CFDANumber":
        $tempData = $this->convertCodes($data);
        return "'{$tempData}'";
        break;

      case "NumberOfAwards":
      case "ModificationNumber":
        $tempData = $this->convertNumber($data);
        if($dbType == 'mysql'){
          return $tempData ? "'{$tempData}'" : "NULL";//mysql
        }else{
          return $tempData ? $tempData : "NULLIF('','')::integer";//postgresql
        }
        break;

      case "EstimatedFunding":
      case "AwardCeiling":
      case "AwardFloor":
        $tempData = $this->convertNumber($data);
        if($dbType == 'mysql'){
          return $tempData ? "'{$tempData}'" : "NULL";//mysql
        }else{
          return $tempData ? $tempData : "cast(NULLIF('','') as double precision)";//postgresql
        }
        break;
      case "CostSharing":
        $tempData = null;
        if($data == 'Y'){
          $tempData = 'Yes';
        }else{
          $tempData = 'No';
        }
        if($dbType == 'mysql'){
          return $tempData ? "'{$tempData}'" : "NULL";//mysql
        }else{
          return $tempData ? "'{$tempData}'" : "NULLIF('','')::varchar";//postgresql
        }
        break;
      default:
        $d = null;
        if(is_array($data)){
          $d = implode('', $data);
        }else{
          $d = $data;
        }

        if($dbType == 'mysql'){
          $tempData = ($d ? mysql_real_escape_string ( $d, $this->db) : null);//mysql
          return $tempData ? "'{$tempData}'" : "NULL";//mysql
        }else{
          $tempData = ($d ? pg_escape_string($this->db, $d) : null);//postgresql
          return $tempData ? "'{$tempData}'" : "NULLIF('','')::varchar";//postgresql
        }
        break;
    }
  }

  private function getTranslatedFormattedData($key){
    global $dbType;

    $data = isset($this->data[$key]) ? $this->data[$key] : null;
    $d = null;
    switch($key){
      case "Agency":
        $d = $this->mapAgencyId($data);
        break;
      case "Status":
        $d = $this->mapFoStatus();
        break;
      default:
        break;
    }

    if($dbType == 'mysql'){
      $tempData = ($d ? mysql_real_escape_string ( $d, $this->db) : null);//mysql
      return $tempData ? "'{$tempData}'" : "NULL";//mysql
    }else{
      $tempData = ($d ? pg_escape_string($this->db, $d) : null);//postgresql
      return $tempData ? "'{$tempData}'" : "NULLIF('','')::varchar";//postgresql
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

  private function translateCodes($key, $values){
    global $translateConfig;

    $translateKeyConfig = null;
    if(isset($translateConfig[$key])){
      $translateKeyConfig = $translateConfig[$key];
    }

    $translatedValues = array();

    if(isset($translateKeyConfig)){
      if(is_array($values)){
        foreach($values as $value){
          if(isset($translateKeyConfig[$value])){
            $translatedValues[] = $translateKeyConfig[$value];
          }
        }
      }else if(!empty($values)){
        if(isset($translateKeyConfig[$values])){
          $translatedValues[] = $translateKeyConfig[$values];
        }
      }
    }

    if(!empty($translatedValues)){
      return implode(';', $translatedValues);
    }

    return null;
  }

  private function convertNumber($value){
    if(is_numeric($value)){
      return $value;
    }

    return null;
  }

  private function mapAgencyId($value){
    global $accounts;
    $ag = strtolower($value);

    if(isset($accounts[$ag])){
      return $accounts[$ag];
    }

    return null;
  }

  private function mapFoStatus(){

    $foDueDate = isset($this->data['ApplicationsDueDate']) ? strtotime($this->formatDate($this->data['ApplicationsDueDate'])) : null;
    $foArchiveDate = isset($this->data['ArchiveDate']) ? strtotime($this->formatDate($this->data['ArchiveDate'])) : null;

    $today = strtotime(date('Y-m-d'));
    $status = '';

    if(
      (!empty($foArchiveDate) && $foArchiveDate < $today)
      ||
      (!empty($foDueDate) && $foDueDate < $today)
    ){
      $status = 'Closed';
    }else if(empty($foDueDate) || $foDueDate >= $today){
      $status = 'Open';
    }

    return $status;
  }

  function processData($db){
    global $dbType, $totalRecords, $sfns, $dbSchema;

    $this->db = $db;
    $query = null;
    try{

      $foNumber = isset($this->data['FundingOppNumber']) ? $this->data['FundingOppNumber'] : null;

      if($foNumber != 'PA-AFRL-AFOSR-2015-0001'){
        return;
      }

      $cfdaNumber = isset($this->data['CFDANumber']) ? $this->convertCodes($this->data['CFDANumber']) : 'NULL';

      if(!isset($totalRecords)){
        $totalRecords = 0;
        //fileLog("INIT totalRecords-{$totalRecords}-{$foNumber}");
      }

      $totalRecords++;
      //fileLog("INCREASE totalRecords-{$totalRecords}-{$foNumber}");

      $rows = 0;
      if($dbType == 'mysql'){
        $result = mysql_query("SELECT Id FROM fundingopportunity WHERE fundingoppnumber = '{$foNumber}' and ", $this->db);//mysql
        $rows = mysql_num_rows($result);//mysql
      }else{
        if(!empty($cfdaNumber) && strlen($cfdaNumber) == 6){
          fileLog("*************COMPARE IFFFFFF CFDA - SELECT id FROM {$dbSchema}{$sfns}stgfoalead__c WHERE {$sfns}fundingopportunitynumber__c = '{$foNumber}' AND  {$sfns}cfdanumber__c = '{$cfdaNumber}'");
          $result = pg_query($db, "SELECT id FROM {$dbSchema}{$sfns}stgfoalead__c WHERE {$sfns}fundingopportunitynumber__c = '{$foNumber}' AND  {$sfns}cfdanumber__c = '{$cfdaNumber}'");//postgresql
        }else{
          fileLog("*************COMPARE ELSEEEE CFDA - SELECT id FROM {$dbSchema}{$sfns}stgfoalead__c WHERE {$sfns}fundingopportunitynumber__c = '{$foNumber}'");
          $result = pg_query($db, "SELECT id FROM {$dbSchema}{$sfns}stgfoalead__c WHERE {$sfns}fundingopportunitynumber__c = '{$foNumber}'");//postgresql
        }
        $rows = pg_num_rows($result);//postgresql
      }

      fileLog("*************ROWS------------>:{$rows}");

      /*if($rows >= 1 ){//good only for the first time
        fileLog("FoNumber overridden- {$foNumber}--> mod no: {$this->data['ModificationNumber']}");
      }*/

      $foAgency = isset($this->data['Agency']) ? $this->data['Agency'] : null;
      $foPostDate = isset($this->data['PostDate']) ? strtotime($this->formatDate($this->data['PostDate'])) : null;
      $foDueDate = isset($this->data['ApplicationsDueDate']) ? strtotime($this->formatDate($this->data['ApplicationsDueDate'])) : null;
      $foArchiveDate = isset($this->data['ArchiveDate']) ? strtotime($this->formatDate($this->data['ArchiveDate'])) : null;

      $today = strtotime(date('Y-m-d'));

      if(
        empty($foAgency) || $foAgency == 'None' || (!empty($foPostDate) && ($foPostDate > $today))
        ||
        ( $rows == 0 && //never processed before
        (
          ( !empty($foArchiveDate) && ($foArchiveDate < $today) )
          ||
          ( !empty($foDueDate) && ($foDueDate < $today) )
        )
        )
      ){
        if($totalRecords > 0){
          $totalRecords--;
          //fileLog("DECREASE totalRecords-{$totalRecords}-{$foNumber}");
        }
        //fileLog("Skipped FoNumber- {$foNumber}");
        return;
      }

      if($dbType != 'mysql')//Adjust columns for my sql
        if($rows == 0 ){
          fileLog("*************EXECUTING INSERT");
          $query = "INSERT INTO {$dbSchema}{$sfns}stgfoalead__c(
            {$sfns}posteddate__c, {$sfns}modificationnumber__c, {$sfns}fundinginstrumenttype__c, {$sfns}categoryoffundingactivity__c,
            {$sfns}categoryexplanation__c, {$sfns}expectednumberofawards__c, {$sfns}estimatedtotalprogramfunding__c, {$sfns}awardceiling__c,
            {$sfns}awardfloor__c, {$sfns}agencymailingaddress__c, {$sfns}fundingopportunitytitle__c, {$sfns}fundingopportunitynumber__c,
            {$sfns}applicationsduedate__c, {$sfns}applicationsduedateexplanation__c, {$sfns}archivedate__c,
            {$sfns}location__c, {$sfns}office__c, {$sfns}federalagency__c, {$sfns}fundingopportunitydescription__c, {$sfns}cfdanumber__c,
            {$sfns}eligibilitycategory__c, {$sfns}additionaleligibilityinformation__c, {$sfns}costsharing__c,
            {$sfns}additionalinformationurltext__c, {$sfns}fundingoppurl__c, {$sfns}agencycontact__c, {$sfns}agencyemailaddress__c,
            {$sfns}agencyemaildescriptor__c, {$sfns}source__c, {$sfns}sourcestatus__c )
        VALUES ({$this->getFormattedData('PostDate')}, {$this->getFormattedData('ModificationNumber')}, {$this->getFormattedData('FundingInstrumentType')}, {$this->getFormattedData('FundingActivityCategory')},
                {$this->getFormattedData('OtherCategoryExplanation')}, {$this->getFormattedData('NumberOfAwards')}, {$this->getFormattedData('EstimatedFunding')}, {$this->getFormattedData('AwardCeiling')},
                {$this->getFormattedData('AwardFloor')}, {$this->getFormattedData('AgencyMailingAddress')}, {$this->getFormattedData('FundingOppTitle')}, {$this->getFormattedData('FundingOppNumber')},
                {$this->getFormattedData('ApplicationsDueDate')}, {$this->getFormattedData('ApplicationsDueDateExplanation')}, {$this->getFormattedData('ArchiveDate')},
                {$this->getFormattedData('Location')}, {$this->getFormattedData('Office')}, {$this->getTranslatedFormattedData('Agency')}, {$this->getFormattedData('FundingOppDescription')}, {$this->getFormattedData('CFDANumber')},
                {$this->getFormattedData('EligibilityCategory')}, {$this->getFormattedData('AdditionalEligibilityInfo')}, {$this->getFormattedData('CostSharing')},
                {$this->getFormattedData('ObtainFundingOppText')}, {$this->getFormattedData('FundingOppURL')}, {$this->getFormattedData('AgencyContact')}, {$this->getFormattedData('AgencyEmailAddress')},
                {$this->getFormattedData('AgencyEmailDescriptor')}, 'Grants.gov', {$this->getTranslatedFormattedData('Status')});";
        }else{
          fileLog("*************EXECUTING UPDATE");
          $query = "UPDATE {$dbSchema}{$sfns}stgfoalead__c
            set {$sfns}posteddate__c = {$this->getFormattedData('PostDate')}, {$sfns}modificationnumber__c = {$this->getFormattedData('ModificationNumber')},
            {$sfns}fundinginstrumenttype__c = {$this->getFormattedData('FundingInstrumentType')}, {$sfns}categoryoffundingactivity__c = {$this->getFormattedData('FundingActivityCategory')},
            {$sfns}categoryexplanation__c = {$this->getFormattedData('OtherCategoryExplanation')}, {$sfns}expectednumberofawards__c = {$this->getFormattedData('NumberOfAwards')},
            {$sfns}estimatedtotalprogramfunding__c = {$this->getFormattedData('EstimatedFunding')}, {$sfns}awardceiling__c = {$this->getFormattedData('AwardCeiling')},
            {$sfns}awardfloor__c = {$this->getFormattedData('AwardFloor')}, {$sfns}agencymailingaddress__c  = {$this->getFormattedData('AgencyMailingAddress')},
            {$sfns}fundingopportunitytitle__c = {$this->getFormattedData('FundingOppTitle')}, {$sfns}applicationsduedate__c = {$this->getFormattedData('ApplicationsDueDate')},
            {$sfns}applicationsduedateexplanation__c = {$this->getFormattedData('ApplicationsDueDateExplanation')}, {$sfns}archivedate__c = {$this->getFormattedData('ArchiveDate')},
            {$sfns}location__c = {$this->getFormattedData('Location')}, {$sfns}office__c = {$this->getFormattedData('Office')}, {$sfns}federalagency__c =  {$this->getTranslatedFormattedData('Agency')},
            {$sfns}fundingopportunitydescription__c = {$this->getFormattedData('FundingOppDescription')}, {$sfns}cfdanumber__c = {$this->getFormattedData('CFDANumber')},
            {$sfns}eligibilitycategory__c = {$this->getFormattedData('EligibilityCategory')}, {$sfns}additionaleligibilityinformation__c = {$this->getFormattedData('AdditionalEligibilityInfo')},
            {$sfns}costsharing__c = {$this->getFormattedData('CostSharing')}, {$sfns}additionalinformationurltext__c = {$this->getFormattedData('ObtainFundingOppText')}, {$sfns}fundingoppurl__c = {$this->getFormattedData('FundingOppURL')},
            {$sfns}agencycontact__c = {$this->getFormattedData('AgencyContact')}, {$sfns}agencyemailaddress__c = {$this->getFormattedData('AgencyEmailAddress')}, {$sfns}agencyemaildescriptor__c = {$this->getFormattedData('AgencyEmailDescriptor')},
            {$sfns}sourcestatus__c = {$this->getTranslatedFormattedData('Status')}
            WHERE {$sfns}fundingopportunitynumber__c = {$this->getFormattedData('FundingOppNumber')}";
        }

      //fileLog('QUERY>>>' . $query);

      if($dbType == 'mysql'){
        $result = mysql_query($query, $this->db);//mysql
        if(!$result){
          throw new Exception(mysql_error());
        }
      }else{
        $result = pg_query($db, $query);//postgresql
        if(!$result){
          throw new Exception(pg_errormessage());
        }
      }

      /*if($totalRecords >= 10){
        fileLog("*************Breaked PROCESS:::::::::-{$foNumber}={$totalRecords}");
        exit;
      }*/

    }catch (Exception $e){
      fileLog('QUERY>>>' . $query);
      fileLog('Exception>>>' . $e);
    }

  }
}