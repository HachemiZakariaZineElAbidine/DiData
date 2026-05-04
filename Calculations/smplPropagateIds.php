/*** Powered by DiData
  * You have access to $this->data an array, which contains the entity values by field name, that you can override
  * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
  * You have access to $this->getOldData() method to get the old entity data in update mode
  * You can add a method using this->addMethod('methodName', function(){...})
***//*** Powered by DiData 
  * After Persistence Calculation
  * You have access to $this->data an array, which contains the entity values by field name, that you can override
  * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
  * You have access to $this->getOldData() method to get the old entity data in update mode
  * You can add a method using this->addMethod('methodName', function(){...})
***/
$this->error = false;
// Check only for Subjects
if (isset($this->data['smpl_id'])) {
	$this->data['smpl_sample_id'] = $this->data['smpl_id'];
	$this->data['smpl_kit_id'] = $this->data['smpl_id'];
	$this->data['smpl_case_id'] = $this->data['smpl_id'];
	$this->data['smpl_subject_id'] = $this->data['smpl_id'];
}