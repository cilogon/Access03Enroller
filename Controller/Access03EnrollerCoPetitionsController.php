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
  public $uses = array(
    "CoPetition",
    "AttributeEnumeration"
  );

  /**
   * identifierExists
   *
   * This is a private function which checks if a given identifier already
   * exists on the user's CO Person record. If so, return true.
   *
   * @param string $identifier The identifier to check for existence on the
   *        CO Person record
   * @param int Either IdentifierEnum::OIDCSub or Identifier::ePPN
   * @param array $petition A petition array containing the Identifiers for
   *        the given user
   * @return bool True if the passed-in identifier already exists on the
   *         CO Person record. False otherwise.
   */
  private function identifierExists($identifier, $type, $petition) {
    $exists = false;

    if(!empty($identifier)) {
      foreach($petition['EnrolleeCoPerson']['Identifier'] as $i) {
        if(($i['type'] == $type) && ($i['identifier'] == $identifier)) {
          $exists = true;
          break;
        }
      }
    }

    return $exists;
  }

  /**
   * addLinkedIdentifier
   *
   * This is a private function which attempts to add a new Identifier of a
   * given type to the CO Person record.
   *
   * @param string $identifier The identifier to check for existence on the
   *        CO Person record
   * @param int Either IdentifierEnum::OIDCSub or Identifier::ePPN
   * @param int $coId The CO ID for the record (for error log)
   * @param int $coPersonId The CO Person ID for the record (for error log)
   */
  private function addLinkedIdentifier($identifier, $type, $coId, $coPersonId) {
    $this->CoPetition->EnrolleeCoPerson->Identifier->clear();

    $data = array();
    $data['Identifier']['identifier'] = $identifier;
    $data['Identifier']['type'] = $type;
    $data['Identifier']['status'] = SuspendableStatusEnum::Active;
    $data['Identifier']['login'] = false;
    $data['Identifier']['co_person_id'] = $coPersonId;

    if(!$this->CoPetition->EnrolleeCoPerson->Identifier->save($data)) {
      $msg = "ERROR could not create Identifier $identifier: ";
      $msg = $msg . "CO ID $coId and CoPerson ID $coPersonId: ";
      $msg = $msg . "Validation errors: ";
      $msg = $msg . print_r($this->CoPetition->EnrolleeCoPerson->Identifier->validationErrors, true);
      $this->log($msg);
      throw new RuntimeException($msg);
    }
  }

  /**
   * Plugin functionality following finalize step:
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
  protected function execute_plugin_finalize($id, $onFinish) {
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson'][] = 'Identifier';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Access03Enroller Finalize: Petition is " . print_r($petition, true));

    $coId = $petition['CoPetition']['co_id'];
    $coPersonId = $petition['CoPetition']['enrollee_co_person_id'];

    // Find the OIDC sub from the environment.
    // If the sub does not exist add it as an Identifier for
    // the CO Person record.
    $oidcSub = env("REDIRECT_OIDC_CLAIM_sub");
    $oidcSubExists = $this->identifierExists($oidcSub, IdentifierEnum::OIDCsub, $petition);
    if(!empty($oidcSub) && (!$oidcSubexists)) {
      $this->addLinkedIdentifier($oidcSub, IdentifierEnum::OIDCsub, $coId, $coPersonId);
    }

    // CIL-2297 Add linked identity's ePPN as an Identifier
    // Find the OIDC ePPN from the environment
    // If the ePPN does not exist add it as an Identifier for
    // the CO Person record.
    $eppn = env("REDIRECT_OIDC_CLAIM_eppn");
    $eppnExists = $this->identifierExists($eppn, IdentifierEnum::ePPN, $petition);
    if(!empty($eppn) && (!$eppnExists)) {
      $this->addLinkedIdentifier($eppn, IdentifierEnum::ePPN, $coId, $coPersonId);
    }

    // This step is completed so redirect to continue the flow.
    $this->redirect($onFinish);
  }

  /**
   * Plugin functionality following selectOrgIdentity step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */

  protected function execute_plugin_selectOrgIdentity($id, $onFinish) {
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson'] = array('Identifier', 'CoOrgIdentityLink');
    $args['contain']['EnrolleeOrgIdentity'][] = 'Identifier';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Access03Enroller selectOrgIdentity: Petition is " . print_r($petition, true));

    // Check if the CO Person record already has either an oidcSub or eppn
    // Identifier for the currently selected IdP. If so, redirect user to a
    // page explaining that they already linked that IdP.
    $oidcSub = env("REDIRECT_OIDC_CLAIM_sub");
    $oidcSubExists = $this->identifierExists($oidcSub, IdentifierEnum::OIDCsub, $petition);
    $eppn = env("REDIRECT_OIDC_CLAIM_eppn");
    $eppnExists = $this->identifierExists($eppn, IdentifierEnum::ePPN, $petition);

    if ($oidcSubExists || $eppnExists) {
      // Unlink and Delete the newly added Org Id corresponding to the IdP.
      // Finding the Org Id is easy. To find the Link Id, we loop through all
      // CoOrgIdentityLinks until we find one with a matching Org Id.
      $orgId = $petition['CoPetition']['enrollee_org_identity_id'];
      $linkId = 0;
      foreach($petition['EnrolleeCoPerson']['CoOrgIdentityLink'] as $i) {
        if($i['org_identity_id'] == $orgId) {
          $linkId = $i['id'];
          break;
        }
      }

      // If we have both the orgId and the linkId, then unlink and delete
      if(($orgId > 0) && ($linkId > 0)) {
        $this->CoPetition->EnrolleeCoPerson->CoOrgIdentityLink->delete($linkId);
        $this->CoPetition->EnrolleeOrgIdentity->delete($orgId);
      }

      // Set the petition to Denied and delete the token so that it
      // cannot be used to access the form again.
      $this->CoPetition->id = $petition['CoPetition']['id'];
      $this->CoPetition->saveField('status', PetitionStatusEnum::Denied);
      $this->CoPetition->saveField('petitioner_token', null);

      // Unset the global ACCESS cookie for the universal toolbar so that we
      // don't get infinite redirects on operations.access-ci.org .
      setcookie('SESSaccesscisso', '', array(
          'expires' => 1,
          'path' => '/',
          'domain' => '.access-ci.org',
          'secure' => true,
          'httponly' => false,
          'samesite' => 'Lax'
      ));

      // Then, redirect to a page explaining that they already linked that IdP
      $this->redirect(_txt('pl.access03_enroller.redirect.linked_identity_exists'));
    }

    $this->redirect($onFinish);
  }
}
