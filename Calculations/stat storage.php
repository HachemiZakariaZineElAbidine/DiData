// 
$storage_path_field = 'Storage_Path';
$storage_name_field = 'NAME';

//$pattern = "/\s\[[A-Za-z], \d+\]$/"; // a regular expression to remove the end of the storage path
$pattern = '/\[[^\]]*\]/';
$number_occup_field = '_Occup_'; // Number of Samples inside storage. For non-box; calculate all samples inside
$number_avail_field = '#_Avail__'; // Number of positions (#rows * #columns) - number of occupations. For non boxes, availability per box - #occupations
$occupation_level_field = 'Occup__level'; // Number of occupations / Number of availabilities
$availabilities_per_box = 100;

$box_entitytype = 7;
$sample_entitytype = 3;
$storage_entitytypes = [4, 5, 6, 11, 14, 15];

$occupation_id_empty = 54;
$occupation_id_lessHalf = 55;
$occupation_id_half = 56;
$occupation_id_almostFull = 57;
$occupation_id_full = 58;

$empty_threshold = 0.001;
$lessHalf_threshold = 0.35;
$half_threshold = 0.65;
$almostFull_threshold = 0.99;

$storage_field = 'STORAGE';
$number_rows_field ='NUMBER_ROWS';
$number_columns_field = 'NUMBER_COLUMNS';

$entity = $this->getCurrentMode() == 'create' ? $this->data : array_merge($this->data, $this->getOldData());

$this->addMethod('getOccupLevel', function($occupations, $number_positions) use ($occupation_id_empty, $occupation_id_lessHalf, $occupation_id_half, $occupation_id_almostFull, $occupation_id_full, $empty_threshold, $lessHalf_threshold, $half_threshold, $almostFull_threshold) {
	if ($occupations == 0) {
    	return $occupation_id_empty;
    } elseif ($number_positions == 0) {
    	return $occupation_id_full;
    }
    
	$occup_level = $occupations / $number_positions;
    if ($occup_level < $empty_threshold) {
    	return $occupation_id_empty;
    } elseif ($occup_level < $lessHalf_threshold) {
    	return $occupation_id_lessHalf;
    }  elseif ($occup_level < $half_threshold) {
    	return $occupation_id_half; 
    } elseif ($occup_level < $almostFull_threshold) {
    	return $occupation_id_almostFull;
    } else {
    	return $occupation_id_full;
    }
});

if ($this->getCurrentMode() == 'update' && $entity['entitytype_id'] == $box_entitytype && isset($entity[$number_rows_field]) && isset($entity[$number_columns_field])) {
	
    $occupations = eqb()->where($storage_field, '=', $entity['id'])->count();
    $number_positions = $entity[$number_rows_field] * $entity[$number_columns_field];
    $availabilities = $number_positions - $occupations;
    $this->data[$number_occup_field] = $occupations;
  	$this->data[$number_avail_field] = $availabilities;
    $this->data[$occupation_level_field] = $this->getOccupLevel($occupations, $number_positions);

} elseif ($this->getCurrentMode() == 'update' && in_array($entity['entitytype_id'], $storage_entitytypes)) {

   	if(isset($entity[$storage_path_field])){
    
        $storage_path_updated = preg_replace($pattern, "", $entity[$storage_path_field]);
        $storage_path_updated .= " -> " . $entity[$storage_name_field];
        
    }else{
    	$storage_path_updated = $entity[$storage_name_field];
    }
    
	$storage_path_updated = preg_replace('/\s+/', ' ', $storage_path_updated);
	
    $boxes = eqb()->where($storage_path_field, 'contain', $storage_path_updated)
    			  ->where('entitytype_id', '=', $box_entitytype)
    			  ->count();
    
    $occupations = eqb()->where($storage_path_field, 'contain', $storage_path_updated)
    				    ->where('entitytype_id', '=', $sample_entitytype)
                  	    ->count();
  	$number_positions = $boxes * $availabilities_per_box;
    $availabilities = $number_positions - $occupations > 0 ? $number_positions - $occupations : 0;
    
    $this->data[$number_occup_field] = $occupations;
  	$this->data[$number_avail_field] = $availabilities;
    $this->data[$occupation_level_field] = $this->getOccupLevel($occupations, $number_positions);
}