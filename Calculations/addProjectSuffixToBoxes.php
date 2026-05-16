/*** Powered by DiData
  * You have access to $this->data an array, which contains the entity values by field name, that you can override
  * You have access to $this->getCurrentMode() method to get the current operation mode ('create' or 'update')
  * You have access to $this->getOldData() method to get the old entity data in update mode
  * You can add a method using this->addMethod('methodName', function(){...})
***/


$box_entitytype_id =7 ;
$name_field = "NAME";
$project_link_field = "Project";
$project_code_field  = "Name_";
$barcode_field = "BARCODE";

$this->addMethod("getProjectSuffix",function($project_id) use ($project_code_field){
		$project = eqb()->find($project_id);
        
        if(isset($project[$project_code_field])){
        		return $project[$project_code_field];	
        }
        
		throw new Exception("Project Must have a code ");
});

if($this->getCurrentMode() == "create" && $this->data["entitytype_id"] == $box_entitytype_id && $this->data[$project_link_field] ){
			
            $project_suffix = $this->getProjectSuffix($this->data[$project_link_field]);
			if(isset($this->data[$name_field])){
            		$this->data[$name_field] = $this->data[$name_field]."_".$project_suffix;
                    $this->data[$barcode_field] = $this->data[$name_field];
                    return;
            }
            
            if(isset($this->data[$barcode_field])){
            	$this->data[$barcode_field] = $this->data[$barcode_field]."_".$project_suffix;
                    $this->data[$name_field] = $this->data[$barcode_field];
                    return;
            }

}