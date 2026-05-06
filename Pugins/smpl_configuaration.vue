<svg :width="width" :height="height">
	<g id="frameLayer" class="layer"></g>
	<g id="batchLayer" class="layer"></g>
	<g id="linkLayer" class="layer"></g>
	<g id="lineLayer" class="layer"></g>
	<g id="nodeLayer" class="layer"></g>
</svg>

<div id="developpedBySBP">
</div>


<div id="toolbar" style="
position: fixed; 
top: 56px; 
left: 0; 
z-index: 1; 
width: 100%; 
overflow: hidden;
border-top: 1px solid #999;
padding: 15px 0 15px 0;
">
</div>

<div v-if="formIdToEdit || loadedForms" id="cache" style="
    position: fixed;
    width:100%;
    height:100%;
    top:0; 
    left:0;
	z-index: 5; 
	background-color: #000; 
    opacity: 50%;"></div>

<!-- Form to fill -->
<div v-if="loadedForms" style="position: fixed; 
	top: 100px; 
	left: 15%; 
	z-index: 10; 
	width: 70%; 
	background-color: #ffffff; 
    overflow-y: scroll;
	overflow-x: hidden;
    max-height: 80vh;
	border: 1px solid #C2C2C2;
	padding: 10px;
    -webkit-box-shadow: 0px 0px 10px -2px #000000; 
	box-shadow: 0px 0px 10px -2px #000000;">
	<h3 v-if="forms[0].title" style="margin-bottom: 20px; margin-left: 10px; font-size: 2em;"> {{forms[0].title}} </h3>
	<EntityUpdateForm v-if="forms[0].entityToUpdate" v-for="form in forms" :key="form.id"
		:ref="`formRendering${form.id}`" :form="form.form" :entityToUpdate="form.entityToUpdate"
		:with-submit-btn="false" @form-submitted="formSubmitted(form)"/>
	<EntitiesCreatorForm v-else v-for="form in forms" :key="form.id" :ref="`formRendering${form.id}`" :form="form.form"
		:with-submit-btn="false" :defaultEntity="form.defaultEntity" @form-submitted="formSubmitted(form)"
		use-create-entity @entity-created="(e) => {form.e = e}" />
	<div class="text-right">
		<DidataTextIconBtn text="Close" @click="forms = []; collectionOngoing = false" />
		<DidataTextIconBtn text="Submit" :title="isValidforms ? 'Submit' : 'Validation Error'"
			:color="isValidforms ?'primary':'red'" :icon="isValidforms ? 'add' : 'warning'" @click="submit" />
	</div>
</div>

<!-- Form to edit -->
<div v-if="formIdToEdit" style="position: fixed; 
	top: 100px; 
	left: 15%; 
	z-index: 10; 
	width: 60%; 
	background-color: #ffffff; 
    overflow-y: scroll;
	overflow-x: hidden;
    max-height: 100vh;
	border: 1px solid #C2C2C2;
	padding: 10px;
    -webkit-box-shadow: 0px 0px 10px -2px #000000; 
	box-shadow: 0px 0px 10px -2px #000000;">
	<!-- <h4 v-if="currentForm.title.l1" style="margin-top: 20px; margin-left: 10px;"> {{currentForm.title.l1}} </h4>
    <h3 v-if="currentForm.title.l2" style="margin-bottom: 20px; margin-left: 10px; font-size: 2em;"> {{currentForm.title.l2}} </h3> -->

	<FormEditor v-if="formIdToEdit !== null" :form-id="formIdToEdit" />
	<DidataTextIconBtn text="Close" @click="closeFormToEdit()" />
	<div class="text-right">
	</div>

</div>

<style>

@font-face {
	font-family: 'EuclidFlex';
	src: url('https://klouche.com/SMPL/fonts/EuclidFlex-Bold-WebS.eot');
	src: url('https://klouche.com/SMPL/fonts/EuclidFlex-Bold-WebS.eot?#iefix') format('embedded-opentype'),
		url('https://klouche.com/SMPL/fonts/EuclidFlex-Bold-WebS.woff2') format('woff2'),
		url('https://klouche.com/SMPL/fonts/EuclidFlex-Bold-WebS.woff') format('woff'),
		url('https://klouche.com/SMPL/fonts/EuclidFlex-Bold-WebS.ttf') format('truetype'),
		url('https://klouche.com/SMPL/fonts/EuclidFlex-Bold-WebS.svg#Font') format('svg');
	font-weight: bold;
	font-style: normal;
}
#toolbar {
  background: linear-gradient(180deg, rgba(250, 250, 250, 0.7), rgba(250, 250, 250, 0.3));
  backdrop-filter: blur(7px);
  -webkit-backdrop-filter: blur(7px); 
  z-index: 1000;
  }
  
  .sampleLine, #bonhommeSubject, .nodeName, #bonhommeCollect, .toolbarGroup label {
font-family: 'EuclidFlex'; 
font-weight: bold;
}
.container {font-family: Arial, sans-serif;}

.sampleLine text, #bonhommeSubject, #bonhommeCase {
fill: #565656;}

#developpedBySBP {
    width: 200px;
    position: fixed;
    bottom: 0;
    left: 8px;
    }
    
.smpllogo {    
	height: 40px;
    float: right;
    margin-right: 10px;
    margin-top: -5px;
    }
    
.smpllogo svg {
    shape-rendering: geometricPrecision;
  }

.toolbarGroup {
    float: left;
    display: block;
    color: #FFFFFF;
    padding: 3px 10px;
    text-decoration: none;
    font-size: 18px;
}

.toolbarElement {
	height: 30px;
	display: inline;
	background-color: #FFF;
    border-radius: 20px;
    border: 2px solid #ecf0f2;
    padding: 3px 5px 5px 7px;
    margin-right: 10px;
    }

.toolbarGroup label {
    color: #bbbbbb;
    font-size: 14px;
    font-weight: bold;
    
    margin-left: 6px;
}

.toolbarGroup select {
    -webkit-appearance: none;
    -moz-appearance: none;
    -ms-appearance: none;
    appearance: none;
    color: #592DAD;
    font-weight: bold;
    margin: 0;
    height: 30px;
    border: none;
    outline: 0;
    padding: 0 25px 0 10px;
    border-radius: 4px;
    background: url(data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23000%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E) no-repeat;
    background-position: 95% 50%;
    background-size: 8px;
    font-size: 14px;
}

.toolbarGroup a {
    color: #592DAD;
    font-weight: bold;
    padding: 7px 10px 6px 10px;
    border: 2px solid #ecf0f2;
    margin: -2px 10px 0 0;
    text-align: center;
    font-size: 14px;
    background-repeat: no-repeat;
    background-size: 15px;
    border-radius: 20px;
    height: 32px;
    
    display: inline-block;
    cursor: pointer;
	background-position: center;
	vertical-align:middle;
	background-color: #FFF;
    line-height: 1.2em;
}
.toolbarGroup a.visible {
    background-repeat: no-repeat;
    background-size: 15px;
    background-position: center;
    background-color: #FFF;
}

.toolbarGroup a:hover {
	background-color: #E3D9F4;
}

#scanField {background-color: #FFF;border-radius: 20px;border: 2px solid #ecf0f2; font-size: 14px; padding: 9px 10px 7px 13px; height: 33px;     margin-top: -4px;}


#nodeLayer .node text {
    fill: DimGray;
    text-decoration: underline;
}

#nodeLayer .node.selected text{
    fill: black;
}

#nodeLayer .node .icon {
    fill: white;
}
#nodeLayer .node.selected .icon{
    fill: black;
}

/*#nodeLayer .node.active .nodeName {
    text-decoration: underline;
}*/

#nodeLayer .node text.clickable, .sampleLine text.clickable {
text-decoration: underline;
cursor: pointer !important;
}

.sidebarGroup {
    padding: 15px;
    margin: 10px;
    background: #FFFFFF;
    border-radius: 7px;
    box-shadow: -3px 3px 6px #00000029;
    -webkit-box-shadow: -3px 3px 6px #00000029;
    font-size: 0.8em;
}

.sidebarGroup p {
    margin-bottom: 5px,
}

.sidebarGroup input {
    border-width: 1px;
    border-color: #CCCCCC;
    width: 100%;
    border-style: solid;
    padding: 4px,
}

.sidebarGroup h3:first-child {
    border-width: 0 0 1px 0;
    border-color: #CCCCCC;
    padding-bottom: 10px;
    margin-bottom: 10px;
    border-style: solid,
}

.sidebarGroup span.clickable {
    text-decoration: underline;
    cursor: pointer,
}

#bonhommeCollect {
    fill: black;
}
</style>