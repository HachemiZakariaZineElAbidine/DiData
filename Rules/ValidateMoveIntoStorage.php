
/*** Powered by DiData
 * You have access to $this->newData which contains data to validate
 * You have access to $this->oldData which contains the old resource data
 * You have access to $this->getCurrentMode method to get the current operation mode ('create' or 'update')
***/
//assign entity to 2d storage rule: when assign entity  to 2d storage check that position row and position column fields values exists
// functions
/**
 * @param int $storageId
 * @return bool
 */
$this->addMethod('isStorage2dStorage', function (int $storageId) : bool {
    $entityStorage = Didata\Entities\Repositories\Models\Entity::find($storageId);
    if ($entityStorage === null)
        return false;
    try {
        new App\DidataPackages\Contexts\StorageContext\_2DStorage($entityStorage);
        return true;
    } catch(\Exception $e){
        return false;
    }
});
// end Functions

if (
    isset($this->newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME])
    && $this->isStorage2dStorage($this->newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME])
) {
    $entity2dStoragePositionsFieldsNames = [
        App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME,
        App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME
    ];
    $entityFieldValues = $this->newData;
    if (isset($this->oldData))
        $entityFieldValues = $entityFieldValues + $this->oldData;
    $missingEntityStorageFieldsLabels = [];
    foreach ($entity2dStoragePositionsFieldsNames as $entityStorageFieldName)
        if (!isset($entityFieldValues[$entityStorageFieldName]))
            $missingEntityStorageFieldsLabels[] = App\DidataPackages\DidataCache::getFieldsByName()[$entityStorageFieldName]['label'];
    if (count($missingEntityStorageFieldsLabels) > 0 ) {
        $this->error = true;
        $missingEntityStorageFieldsLabels = implode(', ', $missingEntityStorageFieldsLabels);
        $this->message = "Can not assign entity to 2d storage, fields ($missingEntityStorageFieldsLabels) are required";
        return;
    }
}

$this->message = 'No errors';
$this->error = false;


/*** Powered by DiData
 * You have access to $this->newData which contains data to validate
 * You have access to $this->oldData which contains the old resource data
 * You have access to $this->getCurrentMode method to get the current operation mode ('create' or 'update')
***/

//Cyclic dependency Rule: Storage assignment can not be recursive (detelct infinit cycle)
/** Functions  **/
/**
 * @param mixed $storageToAddId
 * @param mixed $predecessorId
 * @return bool
 */
$this->addMethod('isCyclicDependency', function ($storageToAddId, $predecessorId): bool {
    if ($storageToAddId === (int)$predecessorId)
        return true;

    $predecessor = eqb()->find($predecessorId);
    if (isset($predecessor[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]))
        return $this->isCyclicDependency($storageToAddId, $predecessor[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]);

    return false;
});

/**
 * @param mixed $entityId
 * @return bool
 */
$this->addMethod('isEntityStorage', function ($entityId): bool {
    $entity = Didata\Entities\Repositories\Models\Entity::find($entityId);
    if ($entity === null)
        return false;

    try {
        new App\DidataPackages\Contexts\StorageContext\Storage($entity);
        return true;
    } catch (App\DidataPackages\Contexts\StorageContext\Exceptions\StorageNotFoundException $e) {
        return false;
    }
});

/**
 * @param array $newData
 * @param array $oldData
 * @return bool
 */
$this->addMethod('isStorageChanges', function (array $newData, array $oldData): bool {
    if (isset($newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]) && isset($oldData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME])) {
        return !($newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME] === $oldData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]);
    }
    return isset($newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]); // new
});
// end Functions

if (
    $this->getCurrentMode() === 'update'
    && $this->isEntityStorage($this->oldData['id'])
    && $this->isStorageChanges($this->newData, $this->oldData)
  ) {
    if ($this->isCyclicDependency($this->oldData['id'], $this->newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME])) {
        $this->message = 'Storage cyclic dependecy error';
        $this->error = true;
        return;
    }
}

$this->message = 'No errors';
$this->error = false;


/*** Powered by DiData
 * You have access to $this->newData which contains data to validate
 * You have access to $this->oldData which contains the old resource data
 * You have access to $this->getCurrentMode method to get the current operation mode ('create' or 'update')
***/

 //Position occupied and Out Of boundaries rules : Selected row and column is within the storage boundaries
 //and position is not occupied

/** Functions  **/

/**
 * @param mixed $storage
 * @param mixed $row
 * @param mixed $col
 *
 * @return string|bool
 */
$this->addMethod('inStorageBoundaries', function ($storage, $row, $col) {
    $error = false;
    $notInStorageBoundariesMessage = 'Out of storage boundaries error';

    if (isset($row) && isset($storage[App\Common::STORAGE_2D_NB_ROWS_FIELD_NAME])) {
        if($row <= 0 || $storage[App\Common::STORAGE_2D_NB_ROWS_FIELD_NAME] < $row) {
            $notInStorageBoundariesMessage = $notInStorageBoundariesMessage.', position row '.$row.' not in [1,'.$storage[App\Common::STORAGE_2D_NB_ROWS_FIELD_NAME].']';
            $error = true;
        }
    }
    else if (isset($row) && !isset($storage[App\Common::STORAGE_2D_NB_ROWS_FIELD_NAME])) {
        $notInStorageBoundariesMessage=$notInStorageBoundariesMessage.', the storage doesn\'t have a row boundary';
        $error = true;
    }

    if ($col !== null && isset($storage[App\Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME])) {
        if ($col <= 0 || $storage[App\Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME] < $col) {
            $notInStorageBoundariesMessage=$notInStorageBoundariesMessage.', position column '.$col.' not in [1,'.$storage[App\Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME].']';
            $error = true;
        }
    }
    else if ($col !== null && !isset($storage[App\Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME])) {
        $notInStorageBoundariesMessage=$notInStorageBoundariesMessage.', the storage doesn\'t have a column boundary';
        $error = true;
    }

    return $error ? $notInStorageBoundariesMessage : true;
});

/**
 * @param mixed $storage
 * @param mixed $row
 * @param mixed $col
 *
 * @return bool
 */
$this->addMethod('findOccupiedPosition', function ($storage, $row, $col): null|array {
    if (! isset($row) || !isset($col))
        return null;

    return eqb()->where(App\Common::ENTITY_FK_STORAGE_FIELD_NAME,'=',$storage['id'])
        ->where(App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME,'=',$row)
        ->where(App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME,'=',$col)
        ->first('id');
});

/**
 * @param array $oldData
 * @param array $newData
 *
 * @return array|null
 */
$this->addMethod('ignoreOrUpdate', function (array $oldData, array $newData) {
    $oldData = $this->getRequiredFields($oldData);
    $newData = $this->getRequiredFields($newData);

    $dataToUpdate = array_merge($oldData, $newData);

    if (!$this->isChanges($oldData, $newData))
        return null;
    if (count($dataToUpdate) >= 2 && isset($dataToUpdate[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]))
        return $dataToUpdate;

    return null;
});

/**
 * @param array $data
 * @return array
 */
$this->addMethod('getRequiredFields', function (array $data): array {
  $array=[];

    if (isset($data[App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME]))
        $array += [App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME => $data[App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME]];

    if (isset($data[App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME]))
        $array += [App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME => $data[App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME]];

    if (isset($data[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]))
        $array += [App\Common::ENTITY_FK_STORAGE_FIELD_NAME => $data[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]];

    return $array;
});

/**
 * @param array $oldData
 * @param array $newData
 *
 * @return bool
 */
$this->addMethod('isChanges', function (array $oldData, array $newData): bool {
    if (count($newData) === 0)
        return false;

    foreach ($newData as $key => $value) {
        if (!isset($oldData[$key]) || $oldData[$key] != $value) {
            return true;
        }
    }

    return false;
});
// end Functions

/** Errors Messages  **/
$positionOccupiedMessage = 'Position occupied';
$col = null;
$row = null;
$storageId = null;
/*** end **/

if ($this->getCurrentMode() === 'create'){
    if (
        isset($this->newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME]) &&
        (isset($this->newData[App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME]) ||
        isset($this->newData[App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME]))
    ) {
        $col = isset($this->newData[App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME]) ?
            $this->newData[App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME] : null;
        $row = isset($this->newData[App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME]) ?
            $this->newData[App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME] : null;
        $storageId = $this->newData[App\Common::ENTITY_FK_STORAGE_FIELD_NAME];
    }
    else {
        $this->error = false;
        return;
    }
}

if ($this->getCurrentMode() === 'update'){
   if (($dataToUpdate = $this->ignoreOrUpdate($this->oldData,$this->newData)) !== null) {
        $col = isset($dataToUpdate[App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME]) ?
            $dataToUpdate[App\Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME] : null;
        $row = isset($dataToUpdate[App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME]) ?
            $dataToUpdate[App\Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME] : null;
        $storageId = $dataToUpdate[App\Common::ENTITY_FK_STORAGE_FIELD_NAME];
   }
   else {
       $this->error = false;
       return;
   }
}

$storage = eqb()->find($storageId);

if (($message = $this->inStorageBoundaries($storage,$row,$col)) !== true) {
    $this->message = $message;
    $this->error = true;
    return;
}

if ($entity = $this->findOccupiedPosition($storage, $row, $col)) {
    $this->message = $positionOccupiedMessage;

    if (! dac()->isInProject('entity', \Didata\Entities\Repositories\Models\Entity::find($entity['id']), dac()->getContext()->getProject()?->id) ) {
        $this->message .= ' from other projects';
    }

    $this->error = true;
    return;
}

$this->message = 'No errors';
$this->error = false;
return;
