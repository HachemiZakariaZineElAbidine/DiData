//Notice Modifications have been applied to this code to suite JH needs don't copy this code

use App\Common;
use App\DidataPackages\DidataCache;

$storage_path_field = 'Storage_Path';
$storage_context_name = 'Storage';
$storage_field = Common::ENTITY_FK_STORAGE_FIELD_NAME;
$position_row_field = Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME;
$position_column_field = Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME;
$storage_name_field = Common::STORAGE_NAME_FIELD_NAME;

//JH fields/infos

$container_entitytype_id = 15;
$room_entitytype_id = 14;
$liquide_nitogen_entitytype_id = 11;
$box_entitytype_id = 7;
$rack_entitytype_id = 6;
$shelf_entitytype_id = 5;
$freezer_entitytype_id = 4;

$field_name_key = "field_name";
$container_name_key = "container_name";

$freezer_field = "Freezer";
$rack_field = "Rack";
$shelf_field = "Shelf";
$box_field = "Box";

//end JH fields/infos;
$contexts = DidataCache::getContextsById();
$storage_entitytypes = [];
foreach ($contexts as $collection) {
    if ($collection['name'] === $storage_context_name) {
        $storage_entitytypes = array_column($collection['_entity_types'], 'id');
        break;
    }
}

$storagesPaths = [];

$storageTypeMap = [
 	$freezer_entitytype_id => [$field_name_key => $freezer_field, $container_name_key => null],
    $shelf_entitytype_id => [$field_name_key => $shelf_field, $container_name_key => null],
    $liquide_nitogen_entitytype_id => [$field_name_key => $freezer_field, $container_name_key => null],
    $box_entitytype_id => [$field_name_key => $box_field, $container_name_key => null],
    $rack_entitytype_id => [$field_name_key => $rack_field, $container_name_key => null]
];
$storage_id = $this->data[$storage_field] ?? null;
$storage_name = $this->data[$storage_name_field] ?? null;

$this->addMethod('resolvePath', function(?int $predecessorId) use (&$storagesPaths, $storage_field,&$storageTypeMap,$container_name_key) {
    if (is_null($predecessorId)) {
        return;
    }

    $predecessor = dac()->getQueryFromCurrentProject('entity')
        ->select([$storage_field, Common::STORAGE_NAME_FIELD_NAME,'entitytype_id'])
        ->where('id', '=', $predecessorId)
        ->getIfOneEntityOrThrow();
        //added for JH
	if (isset($storageTypeMap[$predecessor['entitytype_id']])) {
        $storageTypeMap[$predecessor['entitytype_id']][$container_name_key] = $predecessor[Common::STORAGE_NAME_FIELD_NAME];
    }
    //end added for JH
    $storagesPaths[] = $predecessor;

    $this->resolvePath($predecessor[$storage_field] ?? null);
});


$this->addMethod('formatPath', function($storageEntities): string {
    return array_reduce(array_reverse($storageEntities), function($path, $storage) {
        $storage_name = Common::STORAGE_NAME_FIELD_NAME;

        $pathSeparator = '' === $path ? '' : ' -> ';

        $path .= $pathSeparator . ($storage[$storage_name] ?? "{$storage['id']}@ id");

        return $path;
    }, '');
});

$this->addMethod('updateChildrenPath', function($data) use ($storage_entitytypes, $storage_path_field, $storage_field, $storage_name_field, $position_row_field, $position_column_field){
	
    $pattern = "/\s*\[.*?\]/";
    if(!isset($data['id'])) return;
    $children = eqb()->select(['id', 'entitytype_id', $position_row_field, $position_column_field, $storage_field, $storage_path_field, $storage_name_field])
                       ->where($storage_field, '=', $data['id'])
                       ->get();
    
    foreach($children as $child){
        $path = isset($data[$storage_path_field]) ? $data[$storage_path_field] : '';
        $path = preg_replace($pattern, "", $path);
        $pathSeparator = '' === $path ? '' : ' -> ';
        $path .= $pathSeparator . ($data[$storage_name_field] ?? "{$data['id']} id");
           
        $row = isset($child[$position_row_field]) ? str_pad($child[$position_row_field], 2, '0', STR_PAD_LEFT) : '-';
        $column = isset($child[$position_column_field]) ? str_pad($child[$position_column_field], 2, '0', STR_PAD_LEFT) : '-';
        $path .= ' [' . $row . ', ' . $column . ']';
        
        $start_time = microtime(true);
        // OPTION TO UPDATE WITHOUT CALCULATIONS
        $repo = dac()->DBrepo('entity');
        $repo->setOptions([
            'with_calculation' => false,
            'with_validation' => false
        ]);

        $repo->update(Didata\Entities\Repositories\Models\Entity::find($child['id']), [$storage_path_field => $path]);
        $child[$storage_path_field] = $path;
        if (in_array($child['entitytype_id'], $storage_entitytypes)) {
          $this->updateChildrenPath($child);
        }
    }
});



$entity = $this->getCurrentMode() == 'create' ? $this->data : array_merge($this->getOldData(), $this->data);

if ($this->getCurrentMode() == 'update' && 
    in_array($this->data['entitytype_id'], $storage_entitytypes) &&
    isset($storage_name) &&
    !isset($storage_id)
){
	$this->updateChildrenPath(array_merge($entity, $this->data));
}


if (!isset($storage_id) || $storage_id == null) {
	// Update also if position row / column is updated
	if (isset($this->getOldData()[$storage_field]) && (isset($this->data[$position_row_field]) || isset($this->data[$position_column_field]))) {
    	$storage_id = $this->getOldData()[$storage_field];
    } elseif (array_key_exists($storage_field, $this->data)) { // Storage is null (user empty the value)
    	$this->data[$storage_path_field] = null;
        return;
    } else {
    	return;
    }
}

$storageField = DidataCache::getFieldsByName()[$storage_field];

$this->resolvePath($storage_id);
$row = isset($entity[$position_row_field]) ? $entity[$position_row_field] : '-';
$column = isset($entity[$position_column_field]) ? $entity[$position_column_field] : '-';
//Added for JH
foreach ($storageTypeMap as $key => $storage) {
    if (isset($storage[$container_name_key])) {
        $this->data[$storage[$field_name_key]] = $storage[$container_name_key];
    }
}
//end added for JH
$this->data[$storage_path_field] = $this->formatPath($storagesPaths) . ' [' . $row . ', ' . $column . ']';
$this->updateChildrenPath(array_merge($entity, $this->data));/*** Powered by DiData
  * You have access to $this->data an array, which contains the entity values by field name, that you can override
  * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
  * You have access to $this->getOldData() method to get the old entity data in update mode
  * You can add a method using this->addMethod('methodName', function(){...})
***/