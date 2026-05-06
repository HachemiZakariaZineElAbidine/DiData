/*** Powered by DiData
 * You have access to $this->newData which contains data to validate
 * You have access to $this->oldData which contains the old resource data
 * You have access to $this->getCurrentMode method to get the current operation mode ('create' or 'update')
You can add a method using this->addMethod('methodName', function(){...})
 ***/
use App\Common;
use App\DidataPackages\DidataCache;
use App\Services\Configurations\GetStorageConfigurationService;

// methods
$this->addMethod('get', function (string $key) {
    return array_key_exists($key, $this->newData) ? $this->newData[$key] : ($this->oldData[$key] ?? null);
});

$this->addMethod('validateEntityFkStorageField', function (array $storage, array $storageEntitytypes) {
    return is_null($storage) || ! in_array($storage['entitytype_id'], $storageEntitytypes, true);
});

$this->addMethod('isSupportedByStorageChildren', function (int $entitytypeId, ?array $entitytypeChildren) {
    if (is_null($entitytypeChildren)) {
        return true;
    }

    return in_array($entitytypeId, $entitytypeChildren, true);
});

$this->addMethod('getEntitytypeChildren', function (int $entitytypeId) : ?array {
    $configuration = dac()->getQueryFromCurrentProject('configuration')
        ->whereStorageSettings()->first();

    if ( ! $configuration) {
        return null;
    }

    $entitytypesConfig = array_column(consumeService(app()->make(
        GetStorageConfigurationService::class,
        compact('configuration')
    ))['applied_config'], null, 'id');
    
    return $entitytypesConfig[$entitytypeId]['entitytype_storage_children'] ?? null;
});


// main

$this->error = false;

$entitytypeId = (int) $this->get('entitytype_id');
$storageFk = $this->get(Common::ENTITY_FK_STORAGE_FIELD_NAME);
$storageEntitytypes = array_column(
    DidataCache::getContextByName(Common::STORAGE_CONTEXT_NAME)['_entity_types'],
   'id'
);

if (! (
    isSet($entitytypeId) &&
    isSet($storageFk)
)) return;

$storage = dac()->getQueryFromCurrentProject('entity')->find($storageFk);

if ($this->validateEntityFkStorageField($storage, $storageEntitytypes)) return;

$entitytypeChildren = $this->getEntitytypeChildren($storage['entitytype_id']);

if ($this->isSupportedByStorageChildren($entitytypeId, $entitytypeChildren)) return;

$this->error = true;

$entitytypeNames = implode(', ', array_keys(array_filter(
    DidataCache::getEntityTypes(),
    function($enetitytypeId) use ($entitytypeChildren) {
        return in_array($enetitytypeId, $entitytypeChildren, true);
    }
)));

if ('update' === $this->getCurrentMode())
    $entityStorageId = 'id = ' . $this->get('id');
$this->message = sprintf(
    "Can't assign storage to storage id = $storageFk, storage id = $storageFk %s",
    empty($entitytypeNames) ? 'doesn\'t support children' :
        "only supports storages of ($entitytypeNames) entitytypes"
);