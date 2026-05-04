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

use Didata\Entities\Repositories\Models\EntityType;
use Didata\Entities\Repositories\Models\Entity;

$event = $this->getCurrentMode() == 'create' ? $this->data : array_merge($this->getOldData(), $this->data);
$type = EntityType::find($event['entitytype_id'])?->name;
    
    $firstField = null;
	$lastField = null;

if ($type == 'SMPL_RECEPTION'){
		$firstField = 'smpl_first_reception';
		$lastField = 'smpl_last_reception';
    } 
    else if ($type == 'SMPL_TRANSPORTATION'){
		$firstField = 'smpl_first_transportation';
		$lastField = 'smpl_last_transportation';
    } 
    else if ($type == 'SMPL_STORAGE'){
		$firstField = 'smpl_first_storage';
		$lastField = 'smpl_last_storage';
    } 
    else if ($type == 'SMPL_CENTRIFUGATION'){
		$firstField = 'smpl_first_centrifugation';
		$lastField = 'smpl_last_centrifugation';
    } 
    else if ($type == 'SMPL_ANALYSIS'){
		$firstField = 'smpl_first_analysis';
		$lastField = 'smpl_last_analysis';
    } 
    else if ($type == 'SMPL_PROCESSING'){
		$firstField = 'smpl_first_processing';
		$lastField = 'smpl_last_processing';
    } 

if ($firstField != null){
	$samples = $event['smpl_samples_fk'];
  foreach ($samples as $sample) {
      $events = eqb()->entityType($type)->where('smpl_samples_fk','contain',$sample)->orderby('smpl_event_start_time','asc')->get();
      $firstEvent = $events[0];
      $lastEvent = $events[count($events)-1];

      dac()->update('entity', Entity::find($sample), array($firstField => isset($firstEvent)?$firstEvent['id']:null, $lastField => isset($lastEvent)?$lastEvent['id']:null));
  }
}