<?php
class ImageUtil {

  /**
   * 一時画像保存
   *
   * @return void
   */
  public static function saveTempImage($storeId, $fileInfo, $limiter = true) {
  	// 初期化
  	$ret = array();
    // アップロードエラー
    if ($fileInfo['error'] != UPLOAD_ERR_OK ) {
      $ret["error"] = StrConvUtil::genMessage(Message::ERR_IMAGE_UPLOAD_FAILED, array());
      return $ret;
    }
    // 画像ファイルチェック
    if (!self::isImage($fileInfo['tmp_name'])) {
    	$ret["error"] = StrConvUtil::genMessage(Message::ERR_IMAGE_FORMAT, array());
    	return $ret;
    }
    // 画像サイズチェック
    if ($fileInfo['size'] > AppConf::IMAGE_UPLOAD_MAX_SIZE) {
    	$ret["error"] = StrConvUtil::genMessage(Message::ERR_IMAGE_SIZEOVER, array(AppConf::IMAGE_UPLOAD_MAX_SIZE_LABEL));
    	return $ret;
    }
		// パス生成
    $filePath = FilePathUtil::getTempFilePath($storeId);
    // サムネイルパス生成
    $thumbPath = FilePathUtil::getTempThumbFilePath($storeId, $filePath);
    // ファイル移動
    if (!move_uploaded_file($fileInfo['tmp_name'], $filePath)) {
      // TODO　ログ
      return;
    }
    // 画像変換
    $ext = self::convertImage($filePath, $limiter);
    // サムネイル生成
    self::genThumbnail($filePath.$ext, $thumbPath.$ext);
    // 結果セット
    $ret["url"] = FilePathUtil::toUrl($thumbPath.$ext, true);
    $ret["file"] = FilePathUtil::getFileName($thumbPath.$ext);
    $ret["error"] = false;
    return $ret;
  }

  public static function moveRegistDirByPictureDir($storeId, $fileName, $pictureDir) {

		$sendDir = FilePathUtil::getRegistImageDirByPictureDir($pictureDir);
		$sourceDir = FilePathUtil::getTempImageDir($storeId);

		if (!is_readable($sourceDir."/".$fileName)) return false;

		rename($sourceDir."/".$fileName, $sendDir."/".$fileName);
		rename($sourceDir."_thumb/".$fileName, $sendDir."_thumb/".$fileName);

		$arr = explode("/", $sendDir);
		$subDir = $arr[count($arr)-1];

		return $subDir;
  }

  public static function moveRegistDir($storeId, $fileName) {

		$sendDir = FilePathUtil::getRegistImageDir();
		$sourceDir = FilePathUtil::getTempImageDir($storeId);

		if (!is_readable($sourceDir."/".$fileName)) return false;

		rename($sourceDir."/".$fileName, $sendDir."/".$fileName);
		rename($sourceDir."_thumb/".$fileName, $sendDir."_thumb/".$fileName);

		$arr = explode("/", $sendDir);
		$subDir = $arr[count($arr)-1];

		return $subDir;
  }

  public static function moveTempDir($storeId, $subDir, $fileName) {

		$restoredDir = AppConf::PICTURE_DIR.$subDir;
		$tempDir = FilePathUtil::getTempImageDir($storeId);

		copy($restoredDir."/".$fileName, $tempDir."/".$fileName);
		copy($restoredDir."_thumb/".$fileName, $tempDir."_thumb/".$fileName);

  }


	public static function convertImage($filepath, $limiter) {
		$ret = self::getResource($filepath);

		if ($ret["type"] == IMAGETYPE_GIF) {
			$ret["ext"] = ".jpg";
			$ret["type"] == IMAGETYPE_JPEG;
		}

		// サイズ規定ない指定あり : 範囲内に縮小
		if ($limiter) {
			$ret["resource"] = self::limiter($ret["resource"]);
		}

		// 保存
		self::saveResource($ret, $filepath.$ret["ext"]);

		unlink($filepath);

		return $ret["ext"];
	}

	private static function saveResource($ret, $savePath) {

		$resource = $ret["resource"];
		switch ($ret["type"]) {
			case IMAGETYPE_PNG:
				imagepng($resource, $savePath);
				break;
			case IMAGETYPE_JPEG:
			case IMAGETYPE_GIF:
				imagejpeg($resource, $savePath);
				break;
		}
		imagedestroy($resource);
	}

	public static function getResource($filepath) {
		$imageType = exif_imagetype($filepath);
		$ret = array();
		$resource = null;
		switch ($imageType) {
			case IMAGETYPE_PNG:
				$resource = imagecreatefrompng($filepath);
				// ブレンドモードを無効にする
				imagealphablending($resource, false);
				// 完全なアルファチャネル情報を保存するフラグをonにする
				imagesavealpha($resource, true);
				$ret["type"] = IMAGETYPE_PNG;
				$ret["ext"] = ".png";
				break;
			case IMAGETYPE_JPEG:
				$resource = imagecreatefromjpeg($filepath);
				$ret["type"] = IMAGETYPE_JPEG;
				$ret["ext"] = ".jpg";
				break;
			case IMAGETYPE_GIF:
				$resource = imagecreatefromgif($filepath);
				$ret["type"] = IMAGETYPE_GIF;
				$ret["ext"] = ".gif";
				break;
		}
		if (empty($resource)) return null;
		$ret["resource"] = $resource;
		return $ret;
	}

	public static function isImage($filepath) {
		$imageType = exif_imagetype($filepath);
		switch ($imageType) {
			case IMAGETYPE_PNG:
			case IMAGETYPE_JPEG:
			case IMAGETYPE_GIF:
				return true;
			default:
				return false;
		}
	}

	public static function genThumbnailSizeOption($filePath, $top, $left, $width, $height) {



		$ret = self::getResource($filePath);

		$pathArr = explode("/", $filePath);
		$pathArr[count($pathArr)-2] = $pathArr[count($pathArr)-2]."_thumb";
		$tempPath = implode("/", $pathArr);

		$image = $ret["resource"];

		var_dump($tempPath);


		$thumbnail = imagecreatetruecolor(400, 400);
		// ブレンドモードを無効にする
		imagealphablending($thumbnail, false);
		// 完全なアルファチャネル情報を保存するフラグをonにする
		imagesavealpha($thumbnail, true);

		imagecopyresampled($thumbnail, $image, 0, 0, $left, $top, 400, 400, $width, $height);

		$ret["resource"] = $thumbnail;

		self::saveResource($ret, $tempPath);
	}

	/**
	 * 正方形サムネイルを生成(トリミング)
	 */
	public static function genThumbnail($filePath, $thumbPath, $size = 400) {

		$ret = self::getResource($filePath);
		$image = $ret["resource"];
		$width  = imagesx($image);
		$height = imagesy($image);

		if ( $width >= $height ) {
		  //横長の画像の時
		  $side = $height;
		  $x = floor( ( $width - $height ) / 2 );
		  $y = 0;
		  $width = $side;
		} else {
		  //縦長の画像の時
		  $side = $width;
		  $y = floor( ( $height - $width ) / 2 );
		  $x = 0;
		  $height = $side;
		}

		$thumbnail_width  = $size;
		$thumbnail_height = $size;
		$thumbnail = imagecreatetruecolor( $thumbnail_width, $thumbnail_height );
		// ブレンドモードを無効にする
		imagealphablending($thumbnail, false);
		// 完全なアルファチャネル情報を保存するフラグをonにする
		imagesavealpha($thumbnail, true);

		imagecopyresampled( $thumbnail, $image, 0, 0, $x, $y, $thumbnail_width, $thumbnail_height, $width, $height );

		$ret["resource"] = $thumbnail;

		self::saveResource($ret, $thumbPath);

	}

	/**
	 * アスペクト比を維持して規定サイズ内に縮小
	 */
	public static function limiter($resource) {
		$widthMax = AppConf::IMAGE_MAX_WIDTH;
		$heightMax = AppConf::IMAGE_MAX_HEIGHT;

		$xx = imagesx($resource);
		$yy = imagesy($resource);

		if ($xx <= $widthMax && $yy <= $heightMax) {
			return $resource;
		}

		$x1 = $xx / $widthMax ; // 投画横　÷　規定横
		$y1 = $yy / $heightMax ; // 投画縦　÷　規定縦

		if ($x1 >= $y1) {
			$x2 = $widthMax;
			$y2 = intval(($widthMax / $xx) * $yy) ;
		} else {
			$x2 = intval(($heightMax / $yy) * $xx) ;
			$y2 = $heightMax;
		}
		$resultResource = imagecreatetruecolor($x2, $y2);
		imagecopyresampled($resultResource, $resource, 0, 0, 0, 0, $x2, $y2, $xx, $yy);
		return $resultResource;
	}

	public static function saveAvatarImg($file, $memberId) {
		if ($file["error"] != UPLOAD_ERR_OK) return;

		// copy($file["tmp_name"], AppConf::AVATAR_DIR."temp");

		// ファイルリソース取得
		$res = self::getResource($file["tmp_name"]);

		$ori = 1;
		if ($res["type"] == IMAGETYPE_JPEG) {
			// 画像情報取得
			$info = exif_read_data($file["tmp_name"]);

			// 画像向き情報取得
			if (isset($info["Orientation"])) {
				$ori = $info["Orientation"];
			}

		}

		// 保存パス生成
		$savePath = AppConf::AVATAR_DIR.$memberId.".jpg";

		if (file_exists($savePath)) {
			unlink($savePath);
		}

		// JPEG変換
		imagejpeg($res["resource"], $savePath);
		// サムネイル生成(正方形トリミング100px)
		self::genThumbnail($savePath, $savePath, 200);
		// 向き補正値判定
		switch ($ori) {
			case 3:
				$angle = 180;
				break;
			case 6:
				$angle = 270;
				break;
			case 8:
				$angle = 90;
				break;
			default:
				$angle = 0;
		}
		// 補正処理判定
		if ($angle != 0) {
			// 回転補正保存
			$res = self::getResource($savePath);
			$res["resource"] = ImageRotate($res["resource"], $angle, 0);
			self::saveResource($res, $savePath);
		}
	}

	/**
	 * temp画像をuserに移動
	 */
  public static function moveRegistUserDir($memberId, $fileName) {
		// user下にサブディレクトリを作成
		$sendDir = FilePathUtil::getRegImageUserDir(AppConf::USER_DIR);
		// tempディレクトリを取得
		$sourceDir = FilePathUtil::getTempImageDir($memberId);
		// ディレクトリ移動
		rename($sourceDir."/".$fileName, $sendDir."/".$fileName);
		// 文字列を分割する
		$arr = explode("/", $sendDir);
		// 最後の文字列を取得
		$subDir = $arr[count($arr)-1];
		// サブディレクトリを返却
		return $subDir;
  }

	/**
	 * APPアバター画像変換保存処理
	 *
	 * @return array
	 */
	public function setImageAvatar($filePath) {
/*		$res = self::getResource($filePath);*/
		$res = array();
		$res["ext"] = ".jpg";
		$res["type"] = IMAGETYPE_JPEG;
		$ori = 0;
		// 画像情報取得
		$info = exif_read_data($filePath);
		// 画像向き情報取得
		if (isset($info["Orientation"])) {
			$ori = $info["Orientation"];
		}
		// サムネイル生成(正方形トリミング100px)
		self::genThumbnail($filePath, $filePath, 200);
		// 向き補正値判定
		switch ($ori) {
			case 3:
				$angle = 180;
				break;
			case 6:
				$angle = 270;
				break;
			case 8:
				$angle = 90;
				break;
			default:
				$angle = 0;
		}
		// 補正処理判定
		if ($angle) {
			// 回転補正保存
			$res = self::getResource($filepath);
			$res["resource"] = imagerotate($res["resource"], $angle, 0);
			self::saveResource($res, $filepath);
		}
	}

	/**
	 * temp画像をstampに移動
	 */
  public static function moveRegistStampDir($memberId, $fileName) {
		// user下にサブディレクトリを作成
		$sendDir = FilePathUtil::getRegImageUserDir(AppConf::STAMP_DIR);
		// tempディレクトリを取得
		$sourceDir = FilePathUtil::getTempImageDir($memberId);
		// ディレクトリ移動
		rename($sourceDir."/".$fileName, $sendDir."/".$fileName);
		// 文字列を分割する
		$arr = explode("/", $sendDir);
		// 最後の文字列を取得
		$subDir = $arr[count($arr)-1];
		// サブディレクトリを返却
		return $subDir;
  }

	public static function saveTempImageDesign(
	  $storeId
	  , $fileInfo
	  , $horizontalSize
	  , $verticalSize
	  , $heightPoint
	  , $widthPoint
	  , $limiter = true) {
		// 初期化
		$ret = array();
		// アップロードエラー
	  	if ($fileInfo['error'] != UPLOAD_ERR_OK ) {
			$ret["error"] = StrConvUtil::genMessage(Message::ERR_IMAGE_UPLOAD_FAILED, array());
			return $ret;
	  	}
	  	// 画像ファイルチェック
	  	if (!self::isImage($fileInfo['tmp_name'])) {
			$ret["error"] = StrConvUtil::genMessage(Message::ERR_IMAGE_FORMAT, array());
			return $ret;
	  	}
	  	// 画像サイズチェック
	  	if ($fileInfo['size'] > AppConf::IMAGE_UPLOAD_MAX_SIZE) {
			$ret["error"] = StrConvUtil::genMessage(Message::ERR_IMAGE_SIZEOVER, array(AppConf::IMAGE_UPLOAD_MAX_SIZE_LABEL));
		  	return $ret;
	  	}
		// パス生成
		$filePath = FilePathUtil::getTempFilePath($storeId);
		// サムネイルパス生成
		$thumbPath = FilePathUtil::getTempThumbFilePath($storeId, $filePath);
		// ファイル移動
		if (!move_uploaded_file($fileInfo['tmp_name'], $filePath)) {
		// TODO　ログ
			return;
	  	}
	  	// 画像変換
	  	$ext = self::convertImage($filePath, $limiter);
	  	// サムネイル生成
		self::genThumbnailSpecifySize(
		   $filePath.$ext
		   , $thumbPath.$ext
		   , $horizontalSize
		   , $verticalSize
		   , $heightPoint
		   , $widthPoint);
		// 結果セット
	  	$ret["url"] = FilePathUtil::toUrl($thumbPath.$ext, true);
	  	$ret["file"] = FilePathUtil::getFileName($thumbPath.$ext);
	  	$ret["error"] = false;
		  
		return $ret;
	}

	/**
	 * サイズ指定サムネイルを生成(トリミング)
	 */
	public static function genThumbnailSpecifySize(
		$filePath
		, $thumbPath
		, $thumbWidth
		, $thumbHeight
		, $heightPoint
		, $widthPoint) {

		$ret = self::getResource($filePath);
		$image = $ret["resource"];

		// 画像サイズ取得
		$imageWidth  = imagesx($image);
		$imageHeight = imagesy($image);

		// 出力画像インスタンス作成
		$thumbnail = imagecreatetruecolor( $thumbWidth, $thumbHeight );
		// ブレンドモードを無効にする
		imagealphablending($thumbnail, false);
		// 完全なアルファチャネル情報を保存するフラグをonにする
		imagesavealpha($thumbnail, true);

		// holizontal vertical gap
		$wideGap =  $imageWidth / $thumbWidth;
		$heightGap = $imageHeight / $thumbHeight; 

		// 横比率 < 縦比率 縦カット
		if ($wideGap < $heightGap) {
			$y;
			// y軸カットポイントセット
			switch($heightPoint) {
				case "top":
					$y = 0;
					break;
				case "center":
					$y = ceil((($heightGap - $wideGap) * $thumbHeight) / 2);
					break;
				case "bottom":
					$y = ceil(($heightGap - $wideGap) * $thumbHeight);
					break;
			}

			$cut = ceil(($heightGap - $wideGap) * $thumbHeight);
			imagecopyresampled($thumbnail, $image, 0, 0, 0, $y, $thumbWidth, $thumbHeight, $imageWidth, $imageHeight - $cut);
		}
		else if ($heightGap < $wideGap) {
			// 縦比率 <　横比率　横カット
			$x;
			// x軸カットポイントセット
			switch($widthPoint) {
				case "left":
					$x = 0;
					break;
				case "center":
					$x = ceil((($wideGap - $heightGap) * $thumbWidth) / 2);
					break;
				case "right":
					$x = ceil(($wideGap - $heightGap) * $thumbWidth);
					break;
			}

			$cut = ceil(($wideGap - $heightGap) * $thumbWidth);
			imagecopyresampled($thumbnail, $image, 0, 0, $x, 0, $thumbWidth, $thumbHeight, $imageWidth - $cut, $imageHeight);
		} else {
			// スクエア
			imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $imageWidth, $imageHeight);
		}

		$ret["resource"] = $thumbnail;

		self::saveResource($ret, $thumbPath);
	}

}
