<?php

class FundingOpportunity{

  private $data = null;

  public function FundingOpportunity($data){
    $this->$data = $data;
  }

  public function getFormattedData($field){
    switch($field){
      case "PostDate":
      case "ApplicationsDueDate":
      case "ArchiveDate":
        return $this->data[$field];
        break;

      case "FundingActivityCategory":
      case "FundingInstrumentType":
      case "EligibilityCategory":
        return $this->data[$field];
        break;

      case "NumberOfAwards":
      case "ModificationNumber":
        return $this->data[$field];
        break;

      case "EstimatedFunding":
      case "AwardCeiling":
      case "AwardFloor":
        return $this->data[$field];
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
        return $this->data[$field];
        break;
    }
  }

}