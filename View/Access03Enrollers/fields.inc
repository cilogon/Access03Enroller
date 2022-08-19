<?php
  // Determine if fields are editable
  $e = false;
  
  if(($this->action == "add" && $permissions['add']) || ($this->action == "edit" && $permissions['edit']))
    $e = true;
    
  // We shouldn't get here if we don't have at least read permission, but check just in case
  
  if(!$e && !$permissions['view'])
    return false;
  
?>
<div class="co-info-topbox">
  <i class="material-icons">info</i>
  <?php print _txt('in.pl.noconfig'); ?>
</div>
