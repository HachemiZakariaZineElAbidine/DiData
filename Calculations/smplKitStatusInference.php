/*** Powered by DiData
  * You have access to $this->data an array, which contains the entity values by field name, that you can override
  * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
  * You have access to $this->getOldData() method to get the old entity data in update mode
  * You can add a method using this->addMethod('methodName', function(){...})
***//*** Powered by DiData 
  * Before Persistence Calculation
  * You have access to $this->data an array, which contains the entity values by field name, that you can override
  * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
  * You have access to $this->getOldData() method to get the old entity data in update mode
  * You can add a method using this->addMethod('methodName', function(){...})
***/

$sample = $this->getCurrentMode() === 'create' ? $this->data : $this->data + $this->getOldData();

if (isset($this->data['smpl_sample_status_fk']) && isset($sample['smpl_kit_fk'])) {
	$kit = eqb()->entityType('SMPL_KIT')->where('id','=',$sample['smpl_kit_fk'])->get()[0];
	$samples = eqb()->entityType('SMPL_SAMPLE')->where('smpl_kit_fk','=',$sample['smpl_kit_fk'])->get();
    $statuses = eqb()->entityType('SMPL_STATUS')->get();
    $formattedStatuses = array_column($statuses, 'smpl_label', 'smpl_seq_num');
    $statusIdToSeqNumber = array_column($statuses, 'smpl_seq_num', 'id');
    $kitStatuses = eqb()->entityType('SMPL_KIT_STATUS')->get();
    $kitStatusSeqNumberToId = array_column($kitStatuses, 'id', 'smpl_seq_num');
	$mappedSamplesIndexed = [];
	foreach ($samples as $sample) {
   	 	$statusId = $sample['smpl_sample_status_fk'];
    	if (isset($statusIdToSeqNumber[$statusId])) {
        	$mappedSamplesIndexed[] = $statusIdToSeqNumber[$statusId];
    	} else {
        	$mappedSamplesIndexed[] = null;
    	}
	}

if (empty($mappedSamplesIndexed)) {
        // You can choose to throw an exception or return a default value
        return null;
    }
    
    // Get unique values
    $uniqueValues = array_unique($mappedSamplesIndexed);
    sort($uniqueValues); // Sort for easier comparison
    
    // Get minimum and maximum values
    $min = min($mappedSamplesIndexed);
    $max = max($mappedSamplesIndexed);
    
    // Condition 1: All values are 3
    if (count($uniqueValues) === 1 && $uniqueValues[0] === 3) {
        $this->data['smpl_id_nb'] = 5;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[5];
    }
    
    // Condition 2: All values <=3 and at least one value is 3
    else if ($max <= 3 && in_array(3, $mappedSamplesIndexed, true)) {
        $this->data['smpl_id_nb'] = 4;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[4];
    }
    
    // Condition 3: All values are 2
    else if (count($uniqueValues) === 1 && $uniqueValues[0] === 2) {
        $this->data['smpl_id_nb'] = 3;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[3];
    }
    
    // Condition 4: All values <=2, at least one value is 2, and not all are 2
    else if ($max <= 2 && in_array(2, $mappedSamplesIndexed, true)) {
        $this->data['smpl_id_nb'] = 2;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[2];
    }
    
    // Condition 5: All values <=1 and at least one value is 1
    else if ($max <= 1 && in_array(1, $mappedSamplesIndexed, true)) {
        $this->data['smpl_id_nb'] = 1;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[1];
    }
    
    // Condition 6: All values <=0 and at least one value is 0
    else if ($max <= 0 && in_array(0, $mappedSamplesIndexed, true)) {
        $this->data['smpl_id_nb'] = 0;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[0];
    }
    
    // Condition 7: All values are -1
    else if (count($uniqueValues) === 1 && $uniqueValues[0] === -1) {
        $this->data['smpl_id_nb'] = -1;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[-1];
    }
    // Condition 8: All values are negative (but not all -1)
    else if ($max < 0) {
        $this->data['smpl_id_nb'] = -2;
        $kit['smpl_kit_status'] = $kitStatusSeqNumberToId[-2];
    }  
};