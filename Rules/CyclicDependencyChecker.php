
/*** Powered by DiData
 * You have access to $this->newData which contains data to validate
 * You have access to $this->oldData which contains the old resource data
 * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
 * You can add a method using this->addMethod('methodName', function(){...})
 * You can emit warning using emitWarning(['warning messages here'])
 ***/

/**
 * This class performs validation checks on document entities to prevent cyclic dependencies, detect data changes, and verify
 * if an entity belongs to  document context. These checks are particularly relevant during data updates, ensuring
 * consistency and preventing errors such as cyclic dependencies in relationships.
 */

use App\DidataPackages\DidataCache;
use App\Common;

// Recursively checks if adding a given entity would result in a cyclic dependency.
$this->addMethod('isCyclicDependency', function (int $entityToAdd, int $predecessorId, string $fieldToCheck): bool {
    if ($entityToAdd === $predecessorId)
        return true;

    $predecessor = eqb()->where('id', '=',$predecessorId)->first($fieldToCheck);
    if (isset($predecessor[$fieldToCheck]))
        return $this->isCyclicDependency($entityToAdd, $predecessor[$fieldToCheck], $fieldToCheck);

    return false;
});

// Determines if a specified field has changed between the old and new data.
$this->addMethod('isChanged', function (array $newData, array $oldData, string $fieldToCheck): bool {
    if (isset($newData[$fieldToCheck]) && isset($oldData[$fieldToCheck])) {
        return !($newData[$fieldToCheck] === $oldData[$fieldToCheck]);
    }
    return isset($newData[$fieldToCheck]); // new
});

// Checks if an entity belongs to a specific context based on its type.
$this->addMethod('doesEntityBelongToContext', function (int $entityId, string $contextName): bool {
    $entity = Didata\Entities\Repositories\Models\Entity::find($entityId);

    if ($entity === null) {
        return false;
    }

    $entityTypesWithStorageContext = DidataCache::getContextByName($contextName)['_entity_types'];
    $entityTypes = array_filter($entityTypesWithStorageContext, fn($entityType) => $entityType['id'] === $entity['entitytype_id']);

    if ($entityTypes === []) {
        return false;
    }

    return true;
});

// end Functions

// Define an array with context names and field names
$contexts = [
    [
        'contextName' => Common::DOCUMENTS_CONTEXT_NAME,
        'fieldName' => Common::FOLDER_FK_FIELD_NAME,
        'errorMessage' => 'Folder cyclic dependency error'
    ]
];

if ('create' === $this->getCurrentMode()) {
    $this->message = 'No errors';
    $this->error = false;
    return;
}

foreach ($contexts as $context) {
    if (
        $this->doesEntityBelongToContext($this->oldData['id'], $context['contextName'])
        && $this->isChanged($this->newData, $this->oldData, $context['fieldName'])
    ) {
        if ($this->isCyclicDependency($this->oldData['id'], $this->newData[$context['fieldName']], $context['fieldName'])) {
            $this->message = $context['errorMessage'];
            $this->error = true;
            return;
        }
    }
}

$this->message = 'No errors';
$this->error = false;
return;
