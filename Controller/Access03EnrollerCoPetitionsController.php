<?php

// This COmanage Registry enrollment plugin is intended to be used
// with an identity linking flow for ACCESS.
//
// The following enrollment steps are implemented:
//
// checkEligibility:
//   - Used to prevent linking using the ACCESS CI 
//     IdP since an Organizational Identity with the
//     ACCESS CI ePPN marked as a login identifier is
//     already set up for each CO Person.
//
// finalize:
//   - Used to copy the OIDC sub value to the CO Person
//     record if it does not already exist.
//
// TODO Add other email addresses?

App::uses('CoPetitionsController', 'Controller');
 
class Access03EnrollerCoPetitionsController extends CoPetitionsController {
  // Class name, used by Cake
  public $name = "Access03EnrollerCoPetitions";
  public $uses = array("CoPetition");

  /**
   * Plugin functionality following finalize step:
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
  protected function execute_plugin_finalize($id, $onFinish) {
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson'] = array('Identifier');

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Access03Enroller Finalize: Petition is " . print_r($petition, true));

    $coId = $petition['CoPetition']['co_id'];
    $coPersonId = $petition['CoPetition']['enrollee_co_person_id'];

    // Find the OIDC sub from the environment.
    $oidcSub = env("OIDC_CLAIM_sub");

    if(empty($oidcSub)) {
      $this->redirect($onFinish);
    }

    // See if the OIDC sub is already set as an Identifier on the
    // CO Person record.
    $subExists = false;
    foreach($petition['EnrolleeCoPerson']['Identifier'] as $i) {
      if($i['type'] == IdentifierEnum::OIDCsub) {
        $existingOidcSub = $i['identifier'];
        if($existingOidcSub == $oidcSub) {
          $subExists = true;
          break;
        }
      }
    }

    // If the sub does not exist add it as an Identifier for
    // the CO Person record.
    if(!$subExists) {
        $this->CoPetition->EnrolleeCoPerson->Identifier->clear();

        $data = array();
        $data['Identifier']['identifier'] = $oidcSub;
        $data['Identifier']['type'] = IdentifierEnum::OIDCsub;
        $data['Identifier']['status'] = SuspendableStatusEnum::Active;
        $data['Identifier']['login'] = false;
        $data['Identifier']['co_person_id'] = $coPersonId;

        if(!$this->CoPetition->EnrolleeCoPerson->Identifier->save($data)) {
          $msg = "ERROR could not create Identifier: ";
          $msg = $msg . "ACCESS ID $accessId and CoPerson ID $coPersonId: ";
          $msg = $msg . "Validation errors: ";
          $msg = $msg . print_r($this->CoPetition->EnrolleeCoPerson->Identifier->validationErrors, true);
          $this->log($msg);
          throw new RuntimeException($msg);
        }
    }

    // This step is completed so redirect to continue the flow.
    $this->redirect($onFinish);
  }

  /**
   * Plugin functionality following checkEligibility step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
   
  protected function execute_plugin_checkEligibility($id, $onFinish) {
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson'][] = 'Identifier';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Access03Enroller checkEligibility: Petition is " . print_r($petition, true));

    $coId = $petition['CoPetition']['co_id'];
    $coPersonId = $petition['CoPetition']['enrollee_co_person_id'];

    // If the user just came back from authenticating using the ACCESS
    // IdP then stop this flow.
    $loginServerName = env("OIDC_CLAIM_idp_name");

    if($loginServerName == "ACCESS") {
      $this->redirect("https://identity.access-ci.org/duplicate-enrollment");
    }

    $this->redirect($onFinish);
  }
}
