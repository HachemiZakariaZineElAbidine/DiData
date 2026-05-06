

/*** Powered by DiData
 * You have access to $this->newData which contains data to validate
 * You have access to $this->oldData which contains the old resource data
 * You have access to $this->getCurrentMode method to get the current operation mode ('create' or 'update')
You can add a method using this->addMethod('methodName', function(){...})
 ***/

use App\Common;
use App\DidataPackages\DidataCache;
use Didata\Entities\Repositories\Models\Entity;
use Didata\Entities\Repositories\Models\EntityType;
use App\DidataPackages\Contexts\StudyDesignContext\Study;

/**
 * Check for cyclic dependency
 * @param integer $entityId
 * @param integer $parentId
 * @return bool
 */
$this->addMethod('isCyclicDependency', function ($entityId, $parentId): bool {
    if ($entityId === (int)$parentId)
        return true;

    $predecessor = eqb()->select(['id', Common::PARENT_ENTITY_FIELD_NAME])->find($parentId);

    if (isset($predecessor[Common::PARENT_ENTITY_FIELD_NAME]))
        return $this->isCyclicDependency($entityId, $predecessor[Common::PARENT_ENTITY_FIELD_NAME]);

    return false;
});


/**
 * Check if is study event entity
 * @param integer $entityId
 * @return bool
 */
$this->addMethod('isEntityEvent', function ($entityId): bool {

    $entity = eqb()->select(['id', 'entitytype_id'])->find($entityId);
    return Study::isEvent($entity);
});

/*
    validate cyclic dependency
*/

if (
    $this->getCurrentMode() === 'update'
    && isset($this->newData[Common::PARENT_ENTITY_FIELD_NAME])
    && $this->isEntityEvent($this->oldData['id'])

) {
    if ($this->isCyclicDependency($this->oldData['id'], $this->newData[Common::PARENT_ENTITY_FIELD_NAME])) {
        $this->message = 'Cyclic dependency';
        $this->error = true;
        return;
    }
}




if (isset($this->newData[Common::STUDY_EVENT_FK_FIELD_NAME])) {

    $studyEventFkField = DidataCache::getFieldsByName()[Common::STUDY_EVENT_FK_FIELD_NAME];

    /*
        validate study fk entity must belong to event entitytypes
    */
    if (!$this->isEntityEvent($this->newData[Common::STUDY_EVENT_FK_FIELD_NAME])) {

        $this->message = "{$studyEventFkField['label']} must target an event entity";
        $this->error = true;
        return;
    }


    /*
        validate study fk entity manage the right entitytype
    */


    $managedEntitytype = EntityType::find($this->newData['entitytype_id'] ?? $this->oldData['entitytype_id']);
    $eventEntitytype = Entity::find($this->newData[Common::STUDY_EVENT_FK_FIELD_NAME])->_entitytype;

    $count = $managedEntitytype->_studyDesignEventEntitytype->where('id', $eventEntitytype->id)->count();

    if ($count === 0) {
        $this->message = "The entered {$studyEventFkField['label']} can not manage entities of {$managedEntitytype->label} entitytype.";
        $this->error = true;
        return;
    }
}







$this->message = 'No errors';
$this->error = false;
