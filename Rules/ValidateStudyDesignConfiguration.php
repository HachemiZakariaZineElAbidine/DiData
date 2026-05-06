

/*** Powered by DiData
 * You have access to $this->newData which contains data to validate
 * You have access to $this->oldData which contains the old resource data
 * You have access to $this->getCurrentMode method to get the current operation mode ('create' or 'update')
You can add a method using this->addMethod('methodName', function(){...})
 ***/

use App\Common;
use App\DidataPackages\DidataCache;
use App\Models\Context;

$this->addMethod('getData', function ($key) {
    return $this->newData[$key] ?? ($this->oldData[$key] ?? false);
});

/*
    Validate that the entitytype event has PARENT as a field
*/


if (
    $this->getData('context_id') === Context::getId(Common::STUDY_DESIGN_CONTEXT_NAME) &&
    $this->getData('name')!== Common::STUDY_ENTITY_TYPE_NAME
) {

    $parentEntityField = DidataCache::getFieldsByName()[Common::PARENT_ENTITY_FIELD_NAME];

    if (!in_array($parentEntityField['id'], array_column($this->getData('fields'), 'field_id'), true)) {
        $this->message = "The {$parentEntityField['label']} field must be present for event entitytypes";
        $this->error = true;
        return;
    }
}

$this->message = 'Error message';
$this->error = false;
