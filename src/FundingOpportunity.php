<?php

class FundingOpportunity{

  private $data = array();
  private $db = null;

  public function __construct($data, $db){
    $this->data = $data;
    $this->db = $db;
  }

  public function getFormattedData($key){
    switch($key){
      case "PostDate":
      case "ApplicationsDueDate":
      case "ArchiveDate":
        $tempData = $this->formatDate($this->data[$key]);
        return $tempData ? "'{$tempData}'" : "NULLIF('','')::date";
        break;

      case "FundingActivityCategory":
      case "FundingInstrumentType":
      case "EligibilityCategory":
        $tempData = $this->convertCodes($this->data[$key]);
        return "'{$tempData}'";
        break;

      case "NumberOfAwards":
      case "ModificationNumber":
        $tempData = $this->convertNumber($this->data[$key]);
        return $tempData ? $tempData : "NULLIF('','')::integer";
        break;

      case "EstimatedFunding":
      case "AwardCeiling":
      case "AwardFloor":
        $tempData = $this->convertNumber($this->data[$key]);
        return $tempData ? $tempData : "cast(NULLIF('','') as double precision)";
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
          FundingOppDescription
          CFDANumber
          AdditionalEligibilityInfo
          CostSharing
          ObtainFundingOppText
          AgencyContact


          FundingOppURL                   varchar(250),
          AgencyEmailAddress              varchar(80),
          AgencyEmailDescriptor           varchar(100)
        */
        $tempData = ($this->data[$key] ? pg_escape_string($this->db, $this->data[$key]) : null);
        return $tempData ? "'{$tempData}'" : "NULLIF('','')::varchar";
        break;
    }
  }

  private function formatDate($value){
    if(is_numeric($value)){
      return date("m/d/Y", strtotime($value));
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

}