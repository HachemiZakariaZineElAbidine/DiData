/*** Powered by DiData
  * You have access to $this->data an array, which contains the entity values by field name, that you can override
  * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
  * You have access to $this->getOldData() method to get the old entity data in update mode
  * You can add a method using this->addMethod('methodName', function(){...})
***/
$patient_entitytype = 1;
$visit_entitytype = 2;
$sample_entitytype = 3; 
$freezer_entitytype = 4; 
$shelf_entitytype = 5; 
$rack_entitytype = 6; 
$box_entitytype = 7; 
$barcode_field = 'BARCODE';
$name_field = 'NAME';
$entitytype_id_field = 'entitytype_id';
// Only assign if not exist
// If is storage; Storage_name = Barcode name if not given
$this->addMethod('assignBarcode', function($entitytype_id, $prefix, $is_storage, $length = '5') use(&$barcode_field, $name_field, $entitytype_id_field) {
	if ($this->data[$entitytype_id_field] == $entitytype_id) {
      $entity_id = $this->data['id'];
      $this->data[$barcode_field] = sprintf($prefix . '%0' . $length . 'd', $entity_id);
      if ($is_storage && !isset($this->data[$name_field])) {
      	$this->data[$name_field] = $this->data[$barcode_field];
      }
    }
});
if ($this->getCurrentMode() == 'create' && !isset($this->data[$barcode_field])) {
    $this->assignBarcode($sample_entitytype, 'S', FALSE);
    $this->assignBarcode($patient_entitytype, 'P', FALSE);
    $this->assignBarcode($visit_entitytype, 'V', FALSE);
    // STORAGES
    $this->assignBarcode($freezer_entitytype, 'F', TRUE, '4');
    $this->assignBarcode($shelf_entitytype, 'SH', TRUE);
    $this->assignBarcode($rack_entitytype, 'R', TRUE);
    $this->assignBarcode($box_entitytype, 'B', TRUE);           
}