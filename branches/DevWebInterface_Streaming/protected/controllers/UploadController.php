<?php

class UploadController extends Controller
{
	const thumbSuffix = '_thumb';

 	public function filters()
    {
        return array(
            'accessControl',
        );
    }
    
	public function accessRules()
    {
        return array(
        	array('deny',
                'actions'=>array('delete', 'search', 'upload', 
                				 'get','getUploadListXML'),
        		'users'=>array('?'),
            )
        );
    }
   
	public function actionDelete()
	{
		$result = "id field missing";
		if (isset($_REQUEST['id'])) {
			$uploadId = (int)$_REQUEST['id'];
			//TODO: refactor not to fetch every item in user table, below line fetches everything
			$upload = Upload::model()->with("user")-> findBypk($uploadId);
			$result = "No upload with specific id";
			if ($upload != null)
			{
				$result = "not authorized to delete";
				if ($upload->user->Id == Yii::app()->user->id )
				{
					$result = "An error occured 1";
					if ($upload->delete()) {
						$result = 1;
						if (file_exists($this->getFileName($uploadId, $upload->fileType))) {
							$result = "An error occured 2";
							if (unlink($this->getFileName($uploadId, $upload->fileType))) {
								$result = 1;
								if (file_exists($this->getFileName($uploadId, $upload->fileType))) {
									$result = "An error occured 3";
									if (unlink($this->getFileName($uploadId, $upload->fileType))) {
										$result = 1;
									}
								}
							}
						}
							
					}
				}
			}
		}
		echo CJSON::encode(array("result"=> $result));
		Yii::app()->end();
	}

	public function actionGetList()
	{
		if (isset($_GET['fileType']) && $_GET['fileType'] != NULL)
		{
			$fileType = $_GET['fileType'];
							
			if(Yii::app()->user->id != null)
			{
				$friendList = AuxiliaryFriendsOperator::getFriendIdList();
				
				$sqlCount = 'SELECT count(*)
							 FROM '. Upload::model()->tableName() . ' u 
							 WHERE (fileType = '.$fileType.') AND (userId in ('. $friendList .') 
							 		OR userId = '. Yii::app()->user->id .' OR 
							 		publicData = 1)';
		
				$count=Yii::app()->db->createCommand($sqlCount)->queryScalar();
		
				$sql = 'SELECT u.Id as id, u.description, u.fileType, s.realname, s.Id as userId
							 FROM '. Upload::model()->tableName() . ' u 
							 LEFT JOIN  '. Users::model()->tableName() . ' s ON s.Id = u.userId
							 WHERE (fileType = '.$fileType.') AND (userId in ('. $friendList .') OR 
							 	   userId = '. Yii::app()->user->id .' OR 
							 	   publicData = 1)
							  ORDER BY u.Id DESC';
	
		
				$dataProvider = new CSqlDataProvider($sql, array(
				    											'totalItemCount'=>$count,
															    'sort'=>array(
								        							'attributes'=>array(
								             									'id',
																				),
																),
															    'pagination'=>array(
															        'pageSize'=>Yii::app()->params->uploadCountInOnePage,
																),
														));		
			}
			else
			{
				$dataProvider = null;
			}			
		}
		else
		{
			$dataProvider = null;
		}

		Yii::app()->clientScript->scriptMap['jquery.js'] = false;
		//TODO: added below line because gridview.js is loaded before.
		Yii::app()->clientScript->scriptMap['jquery.yiigridview.js'] = false;
		$this->renderPartial('uploadsInfo',array('dataProvider'=>$dataProvider,'model'=>new SearchForm(),'uploadList'=>true, 'fileType'=>$fileType), false, true);
	}

	public function actionSearch() {
		$model = new SearchForm();

		$dataProvider = null;
		if(isset($_REQUEST['SearchForm']))
		{
			$model->attributes = $_REQUEST['SearchForm'];
			
			if (isset($_GET['fileType']) && $_GET['fileType'] != NULL)
			{
				$fileType = $_GET['fileType'];
				
				if ($model->validate()) 
				{
					$friendList = AuxiliaryFriendsOperator::getFriendIdList();
					
					$sqlCount = 'SELECT count(*)
						 FROM '. Upload::model()->tableName() . ' u
						 LEFT JOIN  '. Users::model()->tableName() . ' s ON s.Id = u.userId 
						 WHERE (fileType = '.$fileType.') AND (u.userId in ('. $friendList .') 
						 			OR 
							 		u.userId = '. Yii::app()->user->id .'
							 		OR
							 		u.publicData = 1 )
						 	   AND	
						 	   (s.realname like "%'. $model->keyword .'%"
							   		OR
							   		u.description like "%'. $model->keyword.'%")';
	
					$count=Yii::app()->db->createCommand($sqlCount)->queryScalar();
	
					$sql ='SELECT u.Id as id, u.description, u.fileType, s.realname, s.Id as userId
						 FROM '. Upload::model()->tableName() . ' u
						 LEFT JOIN  '. Users::model()->tableName() . ' s ON s.Id = u.userId 
						 WHERE (fileType = '.$fileType.') AND (u.userId in ('. $friendList .') 
						 			OR 
							 		u.userId = '. Yii::app()->user->id .' 
							 		OR
							 		u.publicData = 1)
						 	   AND
						 	   (s.realname like "%'. $model->keyword .'%"
						 			OR
						 	   		u.description like "%'. $model->keyword.'%")';

					$dataProvider = new CSqlDataProvider($sql, array(
			    											'totalItemCount'=>$count,
														    'sort'=>array(
							        							'attributes'=>array(
							             									'id', 'realname',
																),
															),
														    'pagination'=>array(
														        'pageSize'=>Yii::app()->params->uploadCountInOnePage,
																'params'=>array(CHtml::encode('SearchForm[keyword]')=>$model->attributes['keyword']),
															),
									));									
				}				
			}
			else
			{
				$dataProvider = null;
			}			
		}
		Yii::app()->clientScript->scriptMap['jquery.js'] = false;
		//TODO: added below line because gridview.js is loaded before.
		Yii::app()->clientScript->scriptMap['jquery.yiigridview.js'] = false;
		$this->renderPartial('searchUploadResults', array('model'=>$model, 'dataProvider'=>$dataProvider, 'fileType'=>$fileType), false, true);
	}

	
	public function actionUploadStarted()
	{
		if (!isset($_REQUEST['id'])) {
			echo "No id";
			Yii::app()->end();
		}

		$uniqueId = $_REQUEST['id'];
		
		if (Yii::app()->user->id != null)
		{
			//Insert a row with uniqueId and live fields, give other mandatory fields default values, they will be updated when upload is finished
			
			$sql = sprintf('INSERT INTO '
			. Upload::model()->tableName() .'
											(uniqueId, fileType, userId, latitude, longitude, altitude, uploadtime, live)
											VALUES(%d, %d, %d, %s, %s, %s, NOW(), %d)', 
											$uniqueId, 1/*Video*/, Yii::app()->user->id, 0, 0, 0, 1); //The unspecified fields have already default values in the table
			
			Yii::app()->db->createCommand($sql)->execute();
		}		
	}
	
	public function actionLiveGet()
	{
		# get seek position
		if (isset($_GET['start'])) {
			$seekPos = intval($_GET['start']);
		} else {
			$seekPos = 0;
		}

		//$sql = 'SELECT u.uniqueId as uniqueId FROM'.Upload::model()->tableName().' u WHERE (userId = '.Yii::app()->user->id.') AND (live = 1)';
		
		$liveUploadRow = Upload::model()->find('userId=:userId AND live=:live', array(':userId'=>Yii::app()->user->id, ':live'=>1));
		
		if($liveUploadRow != null) //Check whether there is a live record
		{
			$uploadInfo = apc_fetch('upload_'.($liveUploadRow->uniqueId));
			$fileName = $uploadInfo['temp_filename'];
			
			$fd = fopen($fileName, "rb");
			$fileSize = filesize($fileName) - (($seekPos > 0) ? $seekPos + 1 : 0);
				
			header('Content-Type: video/x-flv');
			header("Content-Disposition: attachment; filename=\"" . $fileName . "\"");
// 			header('Content-disposition: inline');
// 			header("Content-Transfer-Encoding: binary");
			header("Content-Length: ".$fileSize);
				
			# FLV file format header
			if($seekPos != 0)
			{
				print('FLV');
				print(pack('C', 1));
				print(pack('C', 1));
				print(pack('N', 9));
				print(pack('N', 9));
			}
			
			# seek to requested file position
			fseek($fd, $seekPos);

			//Continue streaming until the upload is finished and liveness ends 
			while(Upload::model()->find('userId=:userId AND live=:live', array(':userId'=>Yii::app()->user->id, ':live'=>1)) != null)
			{
				while(!feof($fd))
				{
					echo fread($fd, 1024 * 5);
				}
				sleep(1); #Wait 1 sec to get uploaded	
			}
						
			fclose ($fd);						
		}		
	}	
	
	public function actionGet()
	{
		if (!isset($_REQUEST['id'])) {
			echo "No id";
			Yii::app()->end();
		}
		if (!isset($_REQUEST['fileType'])) {
			echo "No file Type";
			Yii::app()->end();
		}		
		$uploadId = (int) $_REQUEST['id'];
		$fileType = (int) $_REQUEST['fileType'];

		$fileName = $this->getFileName($uploadId, $fileType);
		if (file_exists($fileName) === true){
			$thumb = false;
			if (isset($_REQUEST['thumb'])) {
				$thumb = true;
			}
			if ($thumb == true) {
				if($fileType == 0) //Image
				{
					$fileName = $this->getFileName($uploadId, $fileType, true);
					if (file_exists($fileName) === false) {
						$this->createThumb($uploadId, $fileType);
					}
					//echo 'AAAAAAAAAAAAAAAAAAAAAAAA';
				}

			}
			else
			{
				//echo 'BBBBBBBBBBBBBBBBBBBBBBB';				
			}

			//$fileName = 'D:/eclipse/workspace/traceper/branches/DevWebInterface_Streaming/upload/19_thumb';

		}
		else {
			$fileName = Yii::app()->basePath . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . (($fileType == 0)?'images/image_missing.png':'images/video.png');
			
			//echo 'CCCCCCCCCCCCCCCCCCCC';
		}
		
		if($fileType == 0) //Image
		{
			header('Content-Type: image/jpeg;');
			echo file_get_contents($fileName);		
		}
		else //Video
		{	
			if (isset($_REQUEST['thumb']))
			{
				header('Content-Type: image/jpeg;');
				echo file_get_contents($fileName);								
			}
			else
			{								
				if(isset($_REQUEST['uniqueId'])) //Live video get
				{
					$uniqueId = (int) $_REQUEST['uniqueId'];
					$filename = Yii::app()->params->uploadPath .'/'.$uniqueId.'.flv';
					
					$seekPos = filesize($fileName) - 100*1024; //Get 100K before if enough data has been streamed
					if($seekPos < 0)
					{
						$seekPos = 0;
					}
					
					header('Content-Type: video/x-flv');
					header("Content-Disposition: attachment; filename=\"" . $fileName . "\"");
					header('Content-disposition: inline');
					header("Content-Transfer-Encoding: binary");
					header("Content-Length: ".$fileSize);
						
					# FLV file format header
					if($seekPos != 0) {
						print('FLV');
						print(pack('C', 1));
						print(pack('C', 1));
						print(pack('N', 9));
						print(pack('N', 9));
					}
						
					//readfile($fileName);
					
					$fd = fopen($fileName, "rb");
					
					# seek to requested file position
					fseek($fd, $seekPos);
					
					while(!feof($fd))
					{
						echo fread($fd, 1024 * 5);
						//flush_buffers();
					}
						
					fclose ($fd);					
				}
				else
				{
					if (is_file($fileName))
					{
						# get seek position
						if (isset($_GET['start'])) 
						{
							$seekPos = intval($_GET['start']);
						} 
						else 
						{
							$seekPos = 0;
						}
					
						$fileSize = filesize($fileName) - (($seekPos > 0) ? $seekPos + 1 : 0);
							
						header('Content-Type: video/x-flv');
						header("Content-Disposition: attachment; filename=\"" . $fileName . "\"");
							
						// 					header('Content-type: video/mp4');
						// 					header('Content-type: video/mpeg');
						header('Content-disposition: inline');
						header("Content-Transfer-Encoding: binary");
						header("Content-Length: ".$fileSize);
							
						# FLV file format header
						if($seekPos != 0) {
							print('FLV');
							print(pack('C', 1));
							print(pack('C', 1));
							print(pack('N', 9));
							print(pack('N', 9));
						}
							
						//readfile($fileName);
				
						$fd = fopen($fileName, "rb");
				
						# seek to requested file position
						fseek($fd, $seekPos);
				
						while(!feof($fd))
						{
							echo fread($fd, 1024 * 5);
							//flush_buffers();
						}
					
						fclose ($fd);
						// 					//exit();
							
						// 					header('Content-type: video/mpeg');
						// 					header('Content-Length: '.filesize($fileName)); // provide file size
						// 					header("Expires: -1");
						// 					header("Cache-Control: no-store, no-cache, must-revalidate");
						// 					header("Cache-Control: post-check=0, pre-check=0", false);
						// 					readfile($fileName);
					}					
				}				
			}					
		}

		Yii::app()->end();
	}
	
	private function flush_buffers()
	{
		ob_end_flush();
		//ob_flush();
		flush();
		ob_start();
	}	

	/**
	 * this action is used by mobile clients
	 */
	public function actionUpload()
	{		
		$result = "Missing parameter";		
		if (isset($_FILES["upload"])
			&& isset($_REQUEST['latitude']) && $_REQUEST['latitude'] != NULL
			&& isset($_REQUEST['longitude']) && $_REQUEST['longitude'] != NULL
			&& isset($_REQUEST['altitude']) && $_REQUEST['altitude'] != NULL
			&& isset($_REQUEST['description']) && $_REQUEST['description'] != NULL
			&& isset($_REQUEST['fileType']) && $_REQUEST['fileType'] != NULL)
			&& isset($_REQUEST['uniqueId']) && $_REQUEST['uniqueId'] != NULL)
			&& isset($_REQUEST['isLive']) && $_REQUEST['isLive'] != NULL)
			&& isset($_REQUEST['isLastPacket']) && $_REQUEST['isLastPacket'] != NULL)
		{
			$result = "Upload Error";
			if ($_FILES["upload"]["error"] == UPLOAD_ERR_OK)
			{
				$latitude = (float) $_REQUEST['latitude'];
				$longitude = (float) $_REQUEST['longitude'];
				$altitude = (float) $_REQUEST['altitude'];
				$description = htmlspecialchars($_REQUEST['description']);
				$fileType = (int) $_REQUEST['fileType'];
				$uniqueId = (int) $_REQUEST['uniqueId'];
				$isLive = (int) $_REQUEST['isLive'];
				$isLastPacket = (int) $_REQUEST['isLastPacket'];

				$publicData = 0;
				if (isset($_REQUEST['publicData']) && $_REQUEST['publicData'] != NULL) {
					$tmp = (int) $_REQUEST['publicData'];
					if ($tmp == 1) {
						$publicData = 1;
					}
				}
				
				if (Yii::app()->user->id != null) 
				{					
					//$liveUploadRow = Upload::model()->find('userId=:userId AND live=:live', array(':userId'=>Yii::app()->user->id, ':live'=>1));
					
					//If there is no live record, then this upload record will be inserted for the first time
					//if($liveUploadRow == null)
					if($isLive != 1)
					{						
						$sql = sprintf('INSERT INTO '
						. Upload::model()->tableName() .'
															(uniqueId, fileType, userId, latitude, longitude, altitude, uploadtime, publicData, live, description)
															VALUES(%d, %d, %s, %s, %s, NOW(), %d, "%s")', 
						$uniqueId, $fileType, Yii::app()->user->id, $latitude, $longitude, $altitude, $publicData, $isLive, $description);
						$result = "Unknown Error";
						$effectedRows = Yii::app()->db->createCommand($sql)->execute();
						if ($effectedRows == 1)
						{
							$result = "Error in moving uploading file";
							if (move_uploaded_file($_FILES["upload"]["tmp_name"], Yii::app()->params->uploadPath .'/'. Yii::app()->db->lastInsertID . (($fileType == 0)?'.jpg':'.flv')))
							{
								$result = "1";
							}
						}												
					}
					else //If there is a live record, then this upload record will update the live record (Assumption: During live streaming no other media can be uploaded)
					{
						$filename = Yii::app()->params->uploadPath .'/'.$uniqueId.'.flv';
						if (file_exists($filename)) //If continue to form a live video which has been already started
						{
							// open the file for appending the new coming packets
							$fp = fopen($filename, "a+");
							
							// append new packet to the file
							fwrite($fp, $_FILES["upload"]["tmp_name"]);
							
							// close the file
							fclose($fp);
						}	
						else //If the live upload just begins
						{
							$sql = sprintf('INSERT INTO '
									. Upload::model()->tableName() .'
									(uniqueId, fileType, userId, latitude, longitude, altitude, uploadtime, publicData, live, description)
									VALUES(%d, %d, %s, %s, %s, NOW(), %d, "%s")',
									$uniqueId, $fileType, Yii::app()->user->id, $latitude, $longitude, $altitude, $publicData, $isLive, $description);
							$result = "Unknown Error";
							$effectedRows = Yii::app()->db->createCommand($sql)->execute();
							if ($effectedRows == 1)
							{
								$result = "Error in moving uploading file";
								if (move_uploaded_file($_FILES["upload"]["tmp_name"], $filename))
								{
									$result = "1";
								}
							}							
						}
						
						if($isLastPacket == 1) //If live upload ends, set the live field to false
						{
							$uploadRow = Upload::model()->find(array('condition'=>'uniqueId=:uniqueId', 'params'=>array(':uniqueId'=>$uniqueId)));
							$uploadRow->live = 0;
							$uploadRow->save();
						}
					}
				}		
			}
		}
		echo CJSON::encode(array("result"=> $result));
		Yii::app()->end();

	}

	private function getFileName($uploadId, $fileType, $thumb = false)
	{
		$fileExtension = (($fileType == 0)?'.jpg':'.flv');
		
		$fileName = Yii::app()->params->uploadPath. '/' . $uploadId . $fileExtension;
		if ($thumb == true) {
			$fileName = Yii::app()->params->uploadPath . '/' . $uploadId . self::thumbSuffix .$fileExtension;
		}
		return $fileName;
	}

	private function createThumb($uploadId, $fileType) {

		// Set maximum height and width
		$width  = 36;
		$height = 36;
		$filename =  $this->getFileName($uploadId, $fileType);
		if (file_exists($filename) === true) {
			if($fileType == 0) //Image
			{
				// Get new dimensions
				list($width_orig, $height_orig) = getimagesize($filename);
	
				$width = ($height / $height_orig) * $width_orig;
	
	
				// Resample
				$image_p = imagecreatetruecolor($width, $height);
				$image   = imagecreatefromjpeg($filename);
				imagecopyresampled($image_p, $image, 0, 0, 0, 0, $width, $height, $width_orig, $height_orig);
				$filenameThumb =  $this->getFileName($uploadId, $fileType, true);
				touch($filenameThumb);
				// Output
				imagejpeg($image_p, $filenameThumb);
				imagedestroy($image);			
			}
			else //Video
			{
			
			}			
		}
	}

	public function actionGetUploadListXML()
	{
		if (Yii::app()->user->isGuest) {
			return;
		}
		$pageNo = 1;
		if (isset($_REQUEST['pageNo']) && $_REQUEST['pageNo'] > 0) {
			$pageNo = (int) $_REQUEST['pageNo'];
		}
		
		if(isset($_REQUEST['fileType']) && $_REQUEST['fileType'] != NULL)
		{
			$fileType = (int)$_REQUEST['fileType'];
		}	
		
		$offset = ($pageNo - 1) * Yii::app()->params->itemCountInDataListPage;
		$out = '';
		$dataFetchedTimeKey = "uploadController.dataFetchedTime";
		if (isset($_REQUEST['list'])) {
			if ($_REQUEST['list'] == "onlyUpdated")
			{
				$time = Yii::app()->session[$dataFetchedTimeKey];
				if ($time !== false && $time != "")
				{
					$friendList = AuxiliaryFriendsOperator::getFriendIdList();
					$sqlCount = 'SELECT ceil(count(*)/'. Yii::app()->params->itemCountInDataListPage .')
								 FROM '. Upload::model()->tableName() . ' u 
								 WHERE (fileType = '.$fileType.') AND (userId in ('. $friendList .') 
								        OR userId = '. Yii::app()->user->id .'
								        OR publicData = 1)
								        AND unix_timestamp(u.uploadTime) >= '. $time;
						
					$pageCount=Yii::app()->db->createCommand($sqlCount)->queryScalar();
						
					$sql = 'SELECT u.Id as id, u.description, u.fileType, s.realname, s.Id as userId, date_format(u.uploadTime,"%d %b %Y %T") as uploadTime, u.altitude, u.latitude, u.longitude
								 FROM '. Upload::model()->tableName() . ' u 
								 LEFT JOIN  '. Users::model()->tableName() . ' s ON s.Id = u.userId
								 WHERE (fileType = '.$fileType.') AND (userId in ('. $friendList .') 
								 		OR userId = '. Yii::app()->user->id .'
								 		OR publicData = 1)
								 		AND unix_timestamp(u.uploadTime) >= '. $time . '
								 ORDER BY u.Id DESC
								 LIMIT '. $offset . ' , ' . Yii::app()->params->itemCountInDataListPage ;
						
					$out = $this->prepareXML($sql, $pageNo, $pageCount, "userList");
				}
			}
		}
		else {

			$friendList = AuxiliaryFriendsOperator::getFriendIdList();
			$sqlCount = 'SELECT ceil(count(*)/'. Yii::app()->params->itemCountInDataListPage .')
					 FROM '. Upload::model()->tableName() . ' u 
					 WHERE (fileType = '.$fileType.') AND (userId in ('. $friendList .') OR 
					 	   userId = '. Yii::app()->user->id .' OR
					 	   publicData = 1)';

			$pageCount=Yii::app()->db->createCommand($sqlCount)->queryScalar();

			$sql = 'SELECT u.Id as id, u.description, u.fileType, s.realname, s.Id as userId, date_format(u.uploadTime,"%d %b %Y %T") as uploadTime, u.altitude, u.latitude, u.longitude
					 FROM '. Upload::model()->tableName() . ' u 
					 LEFT JOIN  '. Users::model()->tableName() . ' s ON s.Id = u.userId
					 WHERE (fileType = '.$fileType.') AND (userId in ('. $friendList .') OR 
					 	   userId = '. Yii::app()->user->id .' OR
					 	   publicData = 1)
					 ORDER BY u.Id DESC
					 LIMIT '. $offset . ' , ' . Yii::app()->params->itemCountInDataListPage ;

			$out = $this->prepareXML($sql, $pageNo, $pageCount, "uploadList");
		}
		echo $out;
		Yii::app()->session[$dataFetchedTimeKey] = time();
		Yii::app()->end();
	}

	private function prepareXML($sql, $pageNo, $pageCount, $type="userList")
	{
		$dataReader = NULL;
		// if page count equal to 0 then there is no need to run query
		//		echo $sql;
		if ($pageCount >= $pageNo && $pageCount != 0) {
			$dataReader = Yii::app()->db->createCommand($sql)->query();
		}

		$str = NULL;
		$userId = NULL;
		if ($dataReader != NULL )
		{
			while ( $row = $dataReader->read() )
			{
				$str .= $this->getUploadXMLItem($row);
			}
		}
		$extra = "thumbSuffix=\"thumb=ok\"";
		$pageNo = $pageCount == 0 ? 0 : $pageNo;
		return $this->addXMLEnvelope($pageNo, $pageCount, $str, $extra);
	}

	private function addXMLEnvelope($pageNo, $pageCount, $str, $extra = ""){
			
		$pageStr = 'pageNo="'.$pageNo.'" pageCount="' . $pageCount .'"' ;

		header("Content-type: application/xml; charset=utf-8");
		$out = '<?xml version="1.0" encoding="UTF-8"?>'
		.'<page '. $pageStr . '  '. $extra .' >'
		. $str
		.'</page>';

		return $out;
	}

	private function getUploadXMLItem($row)
	{
		$row['latitude'] = isset($row['latitude']) ? $row['latitude'] : null;
		$row['longitude'] = isset($row['longitude']) ? $row['longitude'] : null;
		$row['altitude'] = isset($row['altitude']) ? $row['altitude'] : null;
		$row['uploadTime'] = isset($row['uploadTime']) ? $row['uploadTime'] : null;
		$row['id'] = isset($row['id']) ? $row['id'] : null;
		$row['fileType'] = isset($row['fileType']) ? $row['fileType'] : null;
		$row['userId'] = isset($row['userId']) ? $row['userId'] : null;
		$row['realname'] = isset($row['realname']) ? $row['realname'] : null;
		$row['rating'] = isset($row['rating']) ? $row['rating'] : null;
		$row['description'] = isset($row['description']) ? $row['description'] : null;

		$str = '<upload description="'.$row['description'].'" url="'. Yii::app()->homeUrl .urlencode('?r=upload/get&id='. $row['id'].'&fileType='.$row['fileType']) .'" id="'. $row['id'] .'" fileType="'. $row['fileType'].'" byUserId="'. $row['userId'] .'" byRealName="'. $row['realname'] .'" altitude="'.$row['altitude'].'" latitude="'. $row['latitude'].'" longitude="'. $row['longitude'] .'" rating="'. $row['rating'] .'" time="'.$row['uploadTime'].'" />';

		return $str;
	}

	// Uncomment the following methods and override them if needed
	/*
	public function filters()
	{
	// return the filter configuration for this controller, e.g.:
	return array(
	'inlineFilterName',
	array(
	'class'=>'path.to.FilterClass',
	'propertyName'=>'propertyValue',
	),
	);
	}

	public function actions()
	{
	// return external action classes, e.g.:
	return array(
	'action1'=>'path.to.ActionClass',
	'action2'=>array(
	'class'=>'path.to.AnotherActionClass',
	'propertyName'=>'propertyValue',
	),
	);
	}
	*/
}