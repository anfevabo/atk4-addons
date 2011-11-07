<?php
class Model_Filestore_File extends Model_Table {
	public $entity_code='filestore_file';

	public $entity_filestore_type='Filestore_Type';
	public $entity_filestore_volume='Filestore_Volume';

	public $import_source=null;

	function init(){
		parent::init();
		$this->newField('filestore_type_id')
			->datatype('reference')
			->refModel('Model_'.$this->entity_filestore_type)
			->caption('File Type')
			->mandatory(true)
			;
		$this->newField('filestore_volume_id')
			->datatype('reference')
			->refModel('Model_'.$this->entity_filestore_volume)
			->caption('Volume')
			;
		/*
		$this->addField('filenum')
			->datatype('int')
			;
			*/
		$this->newField('original_filename')
			->datatype('text')
			->caption('Original Name')
			;
		$this->newField('filename')
			->datatype('string')
			->caption('Internal Name')
			;
		$this->newField('filesize')
			->datatype('int')
			;
		$this->newField('deleted')
			->datatype('boolean')
			;

		$this->newField('name_size')
			->calculated(true)
			;
	}
	function calculate_name_size(){
		return 'concat("[",filestore_file.id,"] ",coalesce(original_filename,"??")," (",coalesce(round(filesize/1024),"?"),"k)")';
	}
	function toStringSQL($source_field, $dest_fieldname){
		return $source_field.' '.$dest_fieldname;
	}
	public function getListFields(){
		return array('id'=>'id','name_size'=>'name');
	}
	function beforeInsert(&$data){
		parent::beforeInsert($data);

		$this->set($data);
		/*
		if($data['filestore_volume_id'])$this->set('filestore_volume_id',
				$data['filestore_volume_id']);
				*/

	}

	function beforeModify(&$data){
		parent::beforeModify($data);
        if(!$this->isInstanceLoaded() || !$this->get('filestore_volume_id')){
            $this->set('filestore_volume_id',$this->getAvailableVolumeID());
        }

        if(!$this->isInstanceLoaded() || !$this->get('filename')){
            // allocate filename
            $this->set('filename',$this->generateFilename());
        }
		if($this->import_source){
			$this->performImport();
		}
	}
	function getAvailableVolumeID(){
		// Determine best suited volume and returns it's ID
		$c=$this->add('Controller_'.$this->entity_filestore_volume)
			->addCondition('enabled',true)
			->addCondition('stored_files_cnt<',4096*256*256)
			;
		$id=$c->dsql('select')
			->order('rand()')
			->limit(1)
			->field('id')
			->do_getOne();
		$c->loadData($id);

		if(disk_free_space($c->get('dirname')<$filesize)){
			throw new Exception_Filestore_Physical('Out of disks space on volume '.$c);
		}

		return $id;
	}
	function getFiletypeID($mime_type, $add = false){
        $c=$this->add('Controller_'.$this->entity_filestore_type);
        $data = $c->getBy('mime_type',$mime_type);
        if(!$data['id']){
            if ($add){
                $c->update(array("mime_type" => $mime_type, "name" => $mime_type));
                $data = $c->get();
            } else { 
                throw new Exception_Filestore_Type('This file type ('.$mime_type.') is not allowed for upload');
            }
		}
		return $data['id'];
	}
	function generateFilename(){
		$v=$this->getRef('filestore_volume_id'); //won't work because of MVCFieldDefinition, line 304, isInstanceLoaded check
        $v=$this->add("Model_Filestore_Volume")->loadData($this->get("filestore_volume_id"));
        //v is not loaded for some reason
		$dirname=$v->get('dirname');
		$seq=$v->getFileNumber();

		// Initially we store 4000 files per node until we reach 256 nodes. After that we will
		// determine node to use by modding filecounter. This method ensures we don't create too
		// many directories initially and will grow files in directories indefenetely

		$limit=4000*256;

		if($seq<$limit){
			$node=floor($seq / 4000);
		}else{
			$node=$seq % 256;
		}

		$d=$dirname.'/'.dechex($node);
		if(!is_dir($d))mkdir($d);

		// Generate temporary file
		$file=basename(tempnam($d,'fs'));

		// Verify that file was created
		if(!file_exists($d.'/'.$file)){
			throw new Exception_Filestore_Physical('Could not create file in '.$d);
		}

		return dechex($node).'/'.$file;
	}

	function import($source,$mode='upload'){
		/*
		   Import file from different location. 

		   $mode can be
		    - upload - for moving uploaded files. (additional validations apply)
			- move - original file will be removed
			- copy - original file will be kept
			- string - data is passed inside $source and is not an existant file
		   */
		$this->import_source=$source;
		$this->import_mode=$mode;

        if($this->isInstanceLoaded() && $this->get('id')){// -- if we have this, then we cannot really 
            // import anything..

			// If file is already in database - put it into store
			$this->performImport();
			$this->update();
        }
        return $this;
	}

	function getPath(){
        if ($this->isInstanceLoaded()){
	        $volume = $this->getRef('filestore_volume_id');
        } else {
            $volume = $this->add("Model_Filestore_Volume")->loadData($this->get("filestore_volume_id"));
        }
		return $volume->get('dirname').'/'.$this->get('filename');
	}
    function getMimeType(){
        return $this->getRef('filestore_type_id')
            ->get('mime_type');
    }
	function performImport(){
		/*
		   After our filename is determined - performs the operation
		   */
		$destination=$this->getPath();
		switch($this->import_mode){
        case'upload':
				move_uploaded_file($this->import_source,$destination);
				break;
			case'move':
				rename($this->import_source,$destination);
				break;
			case'copy':
				copy($this->import_source,$destination);
				break;
			case'string':
				$fd=fopen($destination,'w');
				fwrite($fd,$this->import_source);
				fclose($fd);
				break;
            case'none': // file is already in place
                break;
			default:
				throw new Exception_Filestore('Incorrect import mode specified: '.$this->import_mode);
		}
        chmod($destination, $this->api->getConfig('filestore/chmod',0660));
		clearstatcache();
		$this->set('filesize',filesize($destination));
		$this->set('deleted',false);
		$this->import_source=null;
        return $this;
	}
	/*
	function beforeDelete(&$data){
		// Truncate but do not delete file completely
		parent::beforeDelete($data);
		$fd=fopen($this->getPath(),'w');
		fclose($fd);
	}
	*/
}
