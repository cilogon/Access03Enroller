<?php

App::uses("SEWController", "Controller");

class Access03EnrollersController extends SEWController {
  // Class name, used by Cake
  public $name="Access03Enrollers";

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array()
  );

  function isAuthorized() {
    $roles = $this->Role->calculateCMRoles();

    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();
    
    // Delete an existing configuration?
    $p['delete'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // Edit an existing configuration?
    $p['edit'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // View the existing configuration?
    $p['index'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    // View the existing confinguration?
    $p['view'] = ($roles['cmadmin'] || $roles['coadmin']);
    
    $this->set('permissions', $p);
    return $p[$this->action];
  }
}
