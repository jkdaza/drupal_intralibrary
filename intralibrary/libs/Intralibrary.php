<?php

class Intralibrary {
	
  // the prefix that will be used to identify a kaltura embed
	const KALTURA_VIDEO_PREFIX = 'kaltura-video';
	const POST_UPLOAD_STANDARD = 0; // return a URL to the newly created IntraLibrary resource
	const POST_UPLOAD_KALTURA = 1; // create a new Kaltura resource, referencing the newly created IntraLibrary resource
	
	const FILE_INPUT = 'file';
	
	// the default workspace (group?)
	const DEFAULT_WORKSPACE = 'Moodle contributors';
	// the default collection to upload to
	const DEFAULT_COLLECTION = 'BCU';
	const NOT_DISCOVERABLE_COLLECTION = 'Not discoverable by students';
	const FACULTY_COLLECTION_PREFIX = 'Pending Clearing ';

	/**
	 * All of the file extensions that will dictate whether a file
	 * gets processed via Kaltura or IntraLibrary directly
	 *
	 * @var array
	 */
	protected static $KALTURA_EXTENSIONS = array(
			'flv', 'asf', 'qt', 'mov', 'mpg', 'avi',
			'wmv', 'mp4', '3gp', 'f4v', 'm4v');
	/**
	 * @var IntraLibraryTaxonomyData
	 */
	private static $_TPROVIDER;
	
	private static $CATEGORY_SOURCE = 'BCU';
	
	private $requiredParams = array(
						'title'            => 'Title',
						'description'      => 'Description',
						'category_value'   => 'Category Value',
						'category_name'    => 'Category Name',
	          'subcategory_value'=> 'Subcategory Value',
						'subcategory_name' => 'Subcategory Name',
	          'approval_reason' => 'Approval Reason' 
	);
	private $optionalParams = array(
							'keywords'            => '',
							'auto_keywords'      => '',
	);
	
	private $kaltura_settings = array(
								'serviceUrl'   => 'http://kaltura.bcu.ac.uk',
								'partnerId'    => '110',
								'admin_secret' =>'266616e97dfee918d629396ccc39e58a',
	                            'flavorParamsId' => 13 //The Kaltura Flavor to be used by default 
	
	);
	
	protected static $USER;
	private $filelocation;
	private $options = array(
	                    'rid'	=> 0,
											'deposit' => true,
	                    'ims' => true,
	                    'delete' => false,
	                    'upload_approval' => false,
											'upload_non_descoverable' => false,
	                    
	);
	
	/**
	* Governs what to do after a successful IntraLibrary SWORD deposit
	*
	* @param string
	*/
	private $postUpload;
	
	private $fileExt;
	
	/**
	* Constructor
	*
	* @param obj $user
	* @param int $repositoryid
	*/
	public function __construct($user,$config) {
		self::$USER = $user;
	  // Initialize IntraLibrary
    require_once(dirname(__FILE__) .'/IntraLibrary-PHP/IntraLibraryLoader.php');
    IntraLibraryLoader::registerAutoloader();
	  IntraLibraryConfiguration::set($config);
	  IntraLibraryCache::setThrowExceptionOnMissingCallback(FALSE);
	  self::$_TPROVIDER = new IntraLibraryTaxonomyData();
	}
	
	
	public function getOption($name) {
	  
	  $result = false;
	  if (!empty($this->options[$name])) {
	    $result = $this->options[$name];
	  }
	  return $result;
	}

	/**
	 * Generate a Kaltura Video URI for a learning object id
	 *
	 * @param integer $learningObjectId
	 */
	public static function generateKalturaURI($learningObjectId, $title = 'KalturaVideo') {
		return self::KALTURA_VIDEO_PREFIX . ':' . ((int) $learningObjectId) . ':' . htmlentities($title);
	}

	/**
	 * Get the configured category source
	 */
	protected static function _get_category_source() {
		static $source;
		if (!isset($source)) {
		  $source = self::$CATEGORY_SOURCE;
		}
		return $source;
	}


	/**
	 * Get the intralibrary username for the current user
	 * Creates an intralibrary user if they don't exist,
	 * based on the BCU token.
	 *
	 * @return string
	 */
	public static function get_intralibrary_username() {
	    $bcuUser = self::_get_sso_user();
	    $username = $bcuUser['LoginId'];
	    
		if (!isset(self::$USER->intralibrary_username) //Fix old usernames
		|| (isset(self::$USER->intralibrary_username) && self::$USER->intralibrary_username != $username)) {
			
			// createBcuUser has nearly identical
			// data signature as $bcuUser
			$req = new IntraLibraryRESTRequest();
			$resp = $req->adminGet('User/createBcuUser', array(
					'username' => $username,
					'Password' => self::get_intralibrary_password(),
					'FirstName' => $bcuUser['FirstName'],
					'LastName' => $bcuUser['LastName'],
					'Email' => $bcuUser['Email'],
					'Faculty' => $bcuUser['Faculty'],
					'PersonType' => $bcuUser['PersonType']
			));

			if ($resp->getError()) {
				// No recovering if there was an error..
				$data = $resp->getData();
				self::_log("Failed to create an IntraLibrary with User/createBcuUser");
				self::_log($data['exception']['stackTrace']);
				throw new Exception("Unable to retrieve IntraLibrary username");
			}
			self::$USER->intralibrary_username = $username;
			self::SaveUser('intralibrary_username');
		}
		
		return self::$USER->intralibrary_username;
	}
	public static function get_intralibrary_fullName() {
	
	  $name = false;
	  $bcuUser = self::_get_sso_user();
	  if ($bcuUser['FirstName'] && $bcuUser['LastName']) {
	    $name = $bcuUser['FirstName'].' '.$bcuUser['LastName'];
	  }
	  return $name;
	}

	/**
	 * @return string the current user's internal intralibrary id
	 */
	public static function get_intralibrary_internal_id() {

		if (!isset(self::$USER->intralibrary_internal_id)) {
			$username 	= self::get_intralibrary_username();
			$req				= new IntraLibraryRESTRequest();
			$data 			= $req->adminGet("User/show/$username")->getData();
      //TO DO: we shall store this somewhere...
			self::$USER->intralibrary_internal_id = $data['user']['id'];
			self::SaveUser('intralibrary_internal_id');
		}

		return self::$USER->intralibrary_internal_id;
	}

	/**
	 * Get the intralibrary password for the current user
	 */
	public static function get_intralibrary_password() {
		$bcuUser = self::_get_sso_user();
		return $bcuUser['Id']; // intraLibrary uses the 'long' ID as the password
	}

	/**
	 * Get user data from the BCU SSO
	 *
	 * @throws Exception
	 * @return array
	 */
	protected static function _get_sso_user() {

		static $ssodata;

		if (empty(self::$USER->ssodata)) {
			// XXX: uncomment the following if BCU sso plugin is not available
			/*
			self::_log("SSO data is missing - using 'intrallect' user");
			return array(
					'Id' => 'intrallect',
					'Faculty' => '4',
					'FirstName' => 'Display',
					'LastName' => 'Name',
					'DisplayName' => 'Display Name',
					'PersonType' => 'staff',
					'Email' => 'devel@intrallect.com'
			);
			

			throw new Exception('BCU SSO data is missing');
			*/
		}


		if (!isset($ssodata)) {
			$ssodata = json_decode(base64_decode(self::$USER->ssodata),true);
			// some of the values come un-trimmed.. fix that:
			$trimvalue_fct = function(&$value) {
				if (is_string($value)) {
					$value = trim($value);
				}
			};
			array_walk_recursive($ssodata, $trimvalue_fct);
		}

		return $ssodata;
	}


	

	/**
	 * Get a list of categories from intralibrary
	 *
	 * @return array taxon refId/name pairs
	 */
	public static function get_categories() {
		static $categories = null;

		if (!isset($categories)) {
			$taxonomy = self::$_TPROVIDER->retrieveBySource(self::_get_category_source());
			if (!$taxonomy) {
				self::_log("Error: BCU Taxonomy Source isn't configured properly.");
				return array();
			}

			// the taxonomy will have one child (being the root taxon)
			$taxonIds = $taxonomy->getChildIds();
			$categories = self::_get_category_children($taxonIds[0]);
		}

		return $categories;
	}

	/**
	 * Get subcategory data
	 *
	 * @return array
	 */
	public static function get_sub_categories() {
		static $categories = null;

		if (!isset($categories)) {
			$categories = array();

			// loop through all categories, and get subcategories for each one
			foreach (self::get_categories() as $category) {
				$id 				= $category['id'];
				$refId				= $category['refId'];
				$categories[$refId] = self::_get_category_children($id);
			}
		}

		return $categories;
	}

	/**
	 * Get all direct children of a taxonomy (category)
	 *
	 * @param string $objectId
	 * @return array
	 */
	private static function _get_category_children($objectId) {
		$categories = array();

		$categoryTaxon = self::$_TPROVIDER->retrieveById($objectId);
		foreach ($categoryTaxon->getChildIds() as $childId) {
			$taxon = self::$_TPROVIDER->retrieveById($childId);
			$categories[] = array(
					'id' => $taxon->getId(),
					'refId' => $taxon->getRefId(),
					'name' => $taxon->getName()
			);
		}

		return $categories;
	}

	/**
	 * 'get_string()' wrapper
	 * using 'repository_intralibrary' as the component
	 *
	 * @param string $identifier
	 * @param object $a
	 */
	public static function get_string($identifier, $a = NULL) {
		return get_string($identifier, get_called_class(), $a);
	}
	
	
	/**
	* Debug Logger helper function
	*/
	protected static function _log($message) {
	  error_log('Intralibary.php says '.$message);
	}

	

	/**
	 * Get response headers for a given URL request
	 *
	 * @param string $url
	 * @return string
	 */
	protected function _get_response_headers($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, TRUE);
		curl_setopt($ch, CURLOPT_NOBODY, TRUE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$headers = curl_exec($ch);
		curl_close($ch);

		return $headers;
	}

	/**
	 * Resolve a redirected URL
	 *
	 * @param string $url
	 * @return string
	 */
	protected function _get_redirected_url($url) {
		$headers = $this->_get_response_headers($url);

		if (preg_match('#Location: (.*)#', $headers, $match)) {
			return trim($match[1]);
		}

		return $url;
	}

	/**
	 * Get the filename for a url
	 *
	 * @param string $url
	 * @return string
	 */
	protected function _get_repository_filename($url) {
		$headers = $this->_get_response_headers($url);

		// this is meant to be a "file download" request
		if (preg_match('#Content\-Disposition\: attachment; filename=(.*)#', $headers, $match)) {
			return trim($match[1]);
		}

		// if there was a redirect, use that
		if (preg_match('#Location: (.*)#', $headers, $match)) {
			$url = trim($match[1]);
		}

		return basename(parse_url($url, PHP_URL_PATH));
	}

	/**
	 * Get the environment in which this repository is being invoked
	 */
	/*
	protected function _get_env() {
		return optional_param('env', isset($this->env) ? $this->env : '', PARAM_RAW);
	}
    */
	/**
	 *
	 */
	/*
	protected function _get_accepted_types() {
		return optional_param_array('accepted_types', '*', PARAM_RAW);
	}
	*/
	/**
	 * Redirect the browser to the download location
	 *
	 * (non-PHPdoc)
	 * @see repository::send_file()
	 */
	public function send_file($storedfile, $lifetime=86400 , $filter=0, $forcedownload=false, array $options = null) {
		$array = @unserialize($storedfile->get_source());
		if (!empty($array['send_url'])) {
			header('Location: ' . $array['send_url']);
			exit;
		}
		else if (!empty($array['url'])) {
			header('Location: ' . $this->_get_redirected_url($array['url']));
			exit;
		}
		else {
			throw new Exception('Unable to send this file -- missing url data');
		}
	}

	/**
	 * Don't sync files as they will be retrieved every time anyways
	 */
	public function sync_individual_file(stored_file $storedfile) {
		return false;
	}
	
	/**
	 * 
	 * Added for MyCAT
	 */
	
	public function setParamArray($params,$required=true) {
	  if (is_array($params)) {
	    
	    if ($required) {
	      foreach ($params as $key=>$value) {
	        $this->requiredParams[$key] = $value;
	      }
	      
	    } else {
	      foreach ($params as $key=>$value) {
	        $this->optionalParams[$key] = $value;
	      }
	      
	    }
	  }
	}
	
	public function upload($file) {
	  $this->filelocation = $file;
	  // create a manifest based on post data
	  $manifest			= $this->_create_manifest();
	  $package			= new IntraLibraryIMSPackage($manifest);
	  $this->_attach_file($package);
	  // create a package
	  $packagePath 		= $package->create();
	
	  // deposit the package
	  return $this->deposit_package($packagePath, $manifest);
	}
	
	private function _create_manifest() {
	  // create an imsmanifest
	  $imsmanifest = new IntraLibraryIMSManifest();
	  $imsmanifest->setCopyright('Uploaded from MyCAT');
	
	  // set title & description
	  $imsmanifest->setTitle($this->requiredParams['title']);
	  $imsmanifest->addDescription($this->requiredParams['description']);
	
	  // set the date
	  $imsmanifest->setDateTime(date('c'));
	
	  // set classification data
	  $categoryRefId 	= $this->requiredParams['category_value'];
	  $categoryName	= $this->requiredParams['category_name'];
	  $subcategories = $this->requiredParams['subcategory_value'];
	  if (!empty($subcategories)) {
	    // add each sub category
        $subcategories 		= explode(',', $subcategories);
	    $subcategoryNames 	= explode(',', $this->requiredParams['subcategory_name']);
	    foreach ($subcategories as $i => $refId) {
	      $imsmanifest->addClassification(self::_get_category_source(), array(
	      array('refId' => $categoryRefId, 'name' => $categoryName),
	      array('refId' => $refId, 'name' => $subcategoryNames[$i])
	      ));
	    }
	  } else {
	    // or just add the parent category on its own
	    $imsmanifest->addClassification(self::_get_category_source(), array(
	    array('refId' => $categoryRefId, 'name' => $categoryName)
	    ));
	  }
	
	  // set keywords
	  $keywords = $this->optionalParams['keywords'];
	  $autoKeywords = $this->optionalParams['auto_keywords'];
	  if (!empty($keywords)) {
	    $keywords = "$keywords, $autoKeywords";
	  } else {
	    $keywords = $autoKeywords;
	  }
	  $keywords = explode(',', $keywords);
	  if ($keywords && $keywords[0] != '') {
	    $imsmanifest->setKeywords($keywords);
	  }
	
	  // set approval reason (if requires approval)
	  if ($this->_upload_require_approval()) {
	    $imsmanifest->addDescription($this->requiredParams['approval_reason']);
	  }
	
	  $bcuUser = self::_get_sso_user();
	  $imsmanifest->setFullName($bcuUser['DisplayName']);
	  $imsmanifest->setEmail($bcuUser['Email']);
	  $imsmanifest->setOrganisation($this->_get_intralibrary_faculty());
	
	  return $imsmanifest;
	}
	/**
	 * 
	 * We don't need this for MyCAT at the moment
	 * @param IntraLibraryIMSPackage $package
	 * @throws Exception
	 */
	/*
	private function _attach_url(IntraLibraryIMSPackage $package) {
	  $url = optional_param(self::FILE_INPUT, NULL, PARAM_RAW);
	  if (!filter_var($url, FILTER_VALIDATE_URL)) {
	    throw new Exception("Invalid URL");
	  }
	
	  // check URL & get content type
	  $ch = curl_init($url);
	  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	  curl_exec($ch);
	  $httpStatus 	= curl_getinfo($ch, CURLINFO_HTTP_CODE);
	  $contentType 	= curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	  curl_close($ch);
	
	  if ($httpStatus < 200 || $httpStatus > 300) {
	    throw new Exception("Invalid URL (status code $httpStatus)");
	  }
	
	  $contentType = explode(';', $contentType);
	
	  $manifest = $package->getManifest();
	  $manifest->setTechnicalFormat($contentType[0]);
	  $manifest->setTechnicalLocation($url);
	
	  $this->postUpload = self::POST_UPLOAD_STANDARD;
	}
	*/
  private function _attach_file(IntraLibraryIMSPackage $package) {
	  $fileData 	= $this->_validate_uploaded_file();

	  $manifest 	= $package->getManifest();
	  $manifest->setTechnicalFormat($fileData['filemime']);
	  $manifest->setTechnicalSize($fileData['filesize']);
	
	  $fileName 	= $fileData['filename'];
	  $ext		= pathinfo($fileName, PATHINFO_EXTENSION);
	  $this->fileExt = strtolower($ext);
	  if (in_array($this->fileExt, self::$KALTURA_EXTENSIONS)) {
	    // files with kaltura file extensions will be treated separately
	    $this->postUpload = self::POST_UPLOAD_KALTURA;
	  } else {
	    // attach the uploaded file to the package
	    $manifest->setFileName($fileName);
	    $package->setFile($fileData['tmp_name']);
	    $this->postUpload = self::POST_UPLOAD_STANDARD;
	  }
	}
	
	protected function deposit_package($packagePath, $manifest = null, $cleanup = TRUE) {
	  // get the appropriate deposit url
	  $sword 				= new IntraLibrarySWORD(self::get_intralibrary_username(), self::get_intralibrary_password());
	  $depositDetails 	    = $sword->get_deposit_details();
	  $depositUrl			= $this->_get_deposit_url($depositDetails);
	
	  $response 			= $sword->deposit($depositUrl, $packagePath);
	
	  // cleanup
	  if ($cleanup) {
	    unlink($packagePath);
	  }
	
	  // return failure if there's no content source
	  if (empty($response->sac_content_src)) {
	    throw new Exception("Failed to Upload File - Please Try Again ($response->sac_summary)");
	  }
	
	  // process the file
	  if ($this->postUpload == self::POST_UPLOAD_KALTURA) {
	    $media_assets = $this->_kaltura_upload($manifest, $response);
	  }
	
	  
	  if ($this->postUpload == self::POST_UPLOAD_KALTURA) {
	    // the we need to prepare the URL for the filter
	    if (preg_match('/^.*:(\d*)$/', (string) $response->sac_id, $matches)) {
	      $url = self::generateKalturaURI($matches[1], (string) $response->sac_title);
	    } else {
	      throw new Exception("Unable to determine IntraLibrary ID from SWORD response");
	    }
	  } else {
	    // standard behaviour is to return a URL to the package
	    $url = urldecode((string) $response->sac_content_src);
	    $url = $this->_get_redirected_url($url);
	  }
	  
	 /*
	  $env = $this->_get_env();
	  if ($env == 'editor' && $this->postUpload == self::POST_UPLOAD_KALTURA) {
	    // we need to set the we need to prepare the URL for the text filter
	    if (preg_match('/^.*:(\d*)$/', (string) $response->sac_id, $matches)) {
	      $url = self::generateKalturaURI($matches[1], (string) $response->sac_title);
	    } else {
	      throw new Exception("Unable to determine IntraLibrary ID from SWORD response");
	    }
	  } else {
	    // standard behaviour is to return a URL to the package
	    $url = urldecode((string) $response->sac_content_src);
	    $url = $this->_get_redirected_url($url);
	  }
	
	  // Moodle only - if the upload request is coming from the file manager,
	  // create a file from reference to use in the current session
	  
	  if ($env == 'filemanager') {
	    require_once __DIR__ . '/../intralibrary/helpers/intralibrary_list_item.php';
	    $reference 	= intralibrary_list_item::create_source((string) $response->sac_id, (string) $response->sac_title, $url);
	
	    $fileData 	= $this->_validate_uploaded_file();
	    $name 		= $fileData['name'];
	    if ($this->postUpload == self::POST_UPLOAD_KALTURA) {
	      // strip the file extension so that moodle doesn't try to embed it
	      $name 	= pathinfo($name, PATHINFO_FILENAME);
	    }
	
	    $record		= $this->_create_upload_record($name);
	    $record->source = repository::build_source_field($reference);
	    $record->referencelifetime = $this->get_reference_file_lifetime($reference);
	
	    $fs 		= get_file_storage();
	    $fs->create_file_from_reference($record, $this->id, $reference);
	  }
		*/
	  return array(
	  	'rid'=>$this->options['rid'], 
	  	'url' => $url, 
	  	'kaltura' => $this->postUpload,
	  	'response' => $response
	  );
	}
	
	private function _kaltura_upload(IntraLibraryIMSManifest $manifest, SWORDAPPEntry $response) {
	  require_once(dirname(__FILE__) .'/Kaltura.php');
	
	  // create a session with the Kaltura server
	  $kHelper 	= new intralibrary_kaltura_helper($this->kaltura_settings['serviceUrl'], $this->kaltura_settings['partnerId']);
	  $client 	= $kHelper->getClient();
	  $client->setKs($kHelper->startSession($this->kaltura_settings['admin_secret']));
	
	  // validate the uploaded file, and upload it to Kaltura
	  $fileData 	= $this->_validate_uploaded_file();
	  $token 		= $client->upload->upload($fileData['tmp_name']);
	
	  // add a media entry from the uploaded file
	  $entry		= $kHelper->createMediaEntry($manifest, (string) $response->sac_id);
	  $client->media->addFromUploadedFile($entry, $token);
	  //$results = $client->flavorAsset->getByEntryId($entryId);
	}
	
	protected function _required_param_value($name, $type = PARAM_RAW) {
	  $value = required_param($name, $type);
	  if (!$value)
	  {
	    throw new Exception('Missing parameter: ' . ucwords(str_replace('_', ' ', $name)));
	  }
	
	  return $value;
	}
	
	/**
	* Does the current upload request require approval?
	*/
	protected function _upload_require_approval() {
	  return $this->getOption('upload_approval');
	}
	
	protected function _upload_non_discoverable() {
	  return $this->getOption('upload_non_descoverable');
	}
	
	/**
	 * Get the deposit URL based on the request
	 *
	 * @param array $depositDetails
	 */
	private function _get_deposit_url($depositDetails) {
	  if ($this->_upload_require_approval()) {
	    // uploads tagged as requiring approval
	    // are submitted to the faculty's pending collection
	    $ws = $this->_get_intralibrary_faculty();
	    $co = self::FACULTY_COLLECTION_PREFIX . $ws;
	  }
	  else if ($this->_upload_non_discoverable()) {
	    $ws = self::DEFAULT_WORKSPACE;
	    $co = self::NOT_DISCOVERABLE_COLLECTION;
	  }
	  else {
	    // otherwise, uploads are submitted to the default
	    // collection
	    $ws = self::DEFAULT_WORKSPACE;
	    $co = self::DEFAULT_COLLECTION;
	  }
	  
	  if (!isset($depositDetails[$ws][$co])) {
	    throw new Exception("Unable to get upload URL (ensure IntraLibrary is configured with a workspace: '$ws' that has access to collection: '$co', and is accessible by the uploading user) ");
	  }
	
	  return $depositDetails[$ws][$co];
	}
	
	/**
	 * Get the faculty for the USER
	 */
	protected function _get_intralibrary_faculty() {
	  $user 		= self::_get_sso_user();
	  $faculty 	= strtolower($user['PersonType'] . '_' . $user['Faculty']);
	  switch ($faculty) {
	    // BIAD
	    case 'staff_2':
	    case 'student_a':
	      return 'BIAD';
	
	      // Birmingham City Business School
	    case 'staff_3':
	    case 'student_b':
	      return 'Business School';
	
	      // Education, Law & Social Sciences
	    case 'staff_4':
	    case 'student_e':
	      return 'ELSS';
	
	      // Faculty Of Health
	    case 'staff_6':
	    case 'student_h':
	      return 'Health';
	
	      // Performance, Media & English
	    case 'staff_9':
	    case 'student_p':
	      return 'PME';
	
	      // Technology, Engineering and Environment
	    case 'staff_t':
	    case 'student_t':
	      return 'TEE';
	
	      // Everyone else doesn't have one
	    default:
	      return NULL;
	  }
	}
	
	/**
	 *
	 * Validate File
	 */
	private function _validate_uploaded_file() {
	  
	  /*if (empty($this->filelocation)) {
	    $name = self::FILE_INPUT;
	    if (!isset($_FILES[$name])) {
	      $this->_log('No file was sent.');
	    }
	    
	    if (!empty($_FILES[$name]['error'])) {
	      $this->_log('There was a file error.');
	    }
	    $this->filelocation = $_FILES[$name];
	  }*/
	  return $this->filelocation;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see repository::supported_returntypes()
	 */
	public function supported_returntypes() {
	  return FILE_EXTERNAL | FILE_REFERENCE;
	}
	
	public function getCategories() {
	  return self::get_categories();
	}
	
	/**
	 * Save user credential for Next time
	 */
	protected static function SaveUser($data) {
	  $user = self::$USER;
	  $value = $user->{$data};
	  if($user->has_account == true) {
	    //$stmt = MyActiveRecord::Prepare("UPDATE mycat_intralibrary_user SET {$data} = '%s' WHERE mycat_user_id = '%s'",$value,$user->id);
	  } else {
	    //$stmt = MyActiveRecord::Prepare("INSERT INTO mycat_intralibrary_user (mycat_user_id, {$data}) VALUES(%d, '%s')",$user->id,$value);
	  }
	  $result = MyActiveRecord::Query($stmt);
	  self::$USER->has_account = true;
	}
	
	/**
	 * return kaltura media entry id and thumbnail
	 *
	 * @param array $matches - Intralobrry Entry Id for kaltura
	 */
	public function getKalturaData($file_id) {
	    $object_id = explode(':',$file_id);
	    $kaltura_data = array('entry_id'=>null,'thumb'=>null, 'error'=>null, 'flavor_id'=>null);
		if (isset($object_id[1]) && is_numeric($object_id[1])) {
			$req 	= new IntraLibraryRESTRequest();
			$resp	= $req->get('LearningObject/show/'.$object_id[1], array());
            
			$data 	= $resp->getData();
			$err	= $resp->getError();
			if ($err) {
				$kaltura_data['error'] = $err;
			}
			else if (empty($data['learningObject'])) {
				$kaltura_data['error'] = "Unable to display Kaltura video {$object_id[1]}";
			} else {
				$entry_id = trim($data['learningObject']['kalturaEntryId']);
				$thumb = trim($data['learningObject']['kalturaThumbnail']);
				
				if (stristr($thumb, $this->kaltura_settings['serviceUrl'])) {
					// only if the thumbnail contains the Kaltura hostname
					$kaltura_data['entry_id'] = $entry_id;
					$kaltura_data['thumb'] = $thumb;
					$kaltura_data['error'] = "Kaltura Flavor not available.";
					
					//Grab Flavour ID
					require_once(dirname(__FILE__) .'/Kaltura.php');
					
					// create a session with the Kaltura server
					$kHelper 	= new intralibrary_kaltura_helper($this->kaltura_settings['serviceUrl'], $this->kaltura_settings['partnerId']);
					$client 	= $kHelper->getClient();
					$client->setKs($kHelper->startSession($this->kaltura_settings['admin_secret']));
					$client->startMultiRequest();
					$client->flavorAsset->getbyentryid($entry_id);
					$multiRequestResults = $client->doMultiRequest();
					if (is_array($multiRequestResults) && isset($multiRequestResults[0])) {
    					foreach ($multiRequestResults[0] as $obj) {
    					  if(isset($obj->flavorParamsId) && $obj->flavorParamsId == $this->kaltura_settings['flavorParamsId'] 
    					    && isset($obj->id)) {
        					    $kaltura_data['flavor_id'] = $obj->id;
        					    unset($kaltura_data['error']);
    					  }
    					}
					}
					
				} else {
				  $name = isset($object_id[2]) ? "'{$object_id[2]}'" : 'This video';
				  $kaltura_data['error'] = "$name is being processed by Kaltura.. please try again in a few minutes";
				}
                
				
			}
			
		} else {
		  $kaltura_data['error'] = "The file request was wrong";
		}
		
		
		return $kaltura_data;
	}
	
	
	/**
	* return file infos
	*
	* @param string/num $id - Intralobrry Entry Id
	*/
	public function getFileInfo($fileId) {
	  $file_data = array('title'=>'No title','description'=>'No description', 'error'=>null);
	  if (isset($fileId)) {
	    $req 	= new IntraLibraryRESTRequest();
	    $resp	= $req->get('LearningObject/metadata/'.$fileId, array());
	
	    $data 	= $resp->getData();
	    $err	= $resp->getError();
	    if ($err) {
	      $file_data['error'] = $err;
	    }
	    else {
	      if (array_key_exists("imsmd:lom", $data) && array_key_exists("imsmd:general", $data["imsmd:lom"])) {
    	       if (array_key_exists("imsmd:title", $data["imsmd:lom"]["imsmd:general"])
    	      && array_key_exists("imsmd:langstring", $data["imsmd:lom"]["imsmd:general"]["imsmd:title"])
    	        && array_key_exists("content", $data["imsmd:lom"]["imsmd:general"]["imsmd:title"]["imsmd:langstring"])) {
    	        
    	        $file_data['title'] = trim($data["imsmd:lom"]["imsmd:general"]["imsmd:title"]["imsmd:langstring"]["content"]);
    	        
    	      }
	      
    	    if (array_key_exists("imsmd:description", $data["imsmd:lom"]["imsmd:general"])
    	      && array_key_exists("imsmd:langstring", $data["imsmd:lom"]["imsmd:general"]["imsmd:description"])
    	        && array_key_exists("content", $data["imsmd:lom"]["imsmd:general"]["imsmd:description"]["imsmd:langstring"])) {
    	        
    	        $file_data['description'] = trim($data["imsmd:lom"]["imsmd:general"]["imsmd:description"]["imsmd:langstring"]["content"]);
    	        
    	      }
	      }
	    }
	    	
	  } else {
	    $file_data['error'] = "The file request was wrong";
	  }

	  return $file_data;
	}
	
	public function saveFileEntry($fileId,$deposit) {
	    //Get data we need from the deposit
	    $sac_id = $deposit['response']->sac_id[0];
	    $sac_url = $deposit['url'];
	    $rep_id = $this->options['rid'];
	    $kaltura = $deposit['kaltura'];
	    //$stmt = MyActiveRecord::Prepare("INSERT INTO repository_file (fid, rid, resource_id, remote_path,kaltura_entry) VALUES(%d, %d, '%s', '%s', '%s')",$fileId,$rep_id,$sac_id,$sac_url,$kaltura);
	    //$result = MyActiveRecord::Query($stmt);
	    return $result;
	}
	
	/**
	 * Get records based on a fix set of parameters
	 * options includes:
	 * - 'searchterm': (string) the search term
	 * - 'myresource': (boolean) [optional] if true, only search in the current user's resources
	 * - 'collection': (string) [optional] the collection name
	 * - 'filetype': (string) [optional] the filetype: 'word', 'pdf' or 'image'
	 * - 'starrating': (string) [optional] the average star rating
	 * - 'category': (string) [optional] the category
	 *
	 * @param strign $searchterm
	 * @param array  $options
	 * @param string $username
	 * @throws Exception
	 * @return array
	 */
	public function get_records($searchterm, $options, $username)
	{
		if (empty($searchterm))
		{
			throw new Exception('Missing Search Term');
		}
		
		

		$collection = $myresources = $filetype = $starrating = $category = $accepted_types = $env = '';
		extract($options);
		// we can't retrieve all, so lets set a high limit
		$xSearchParams = array('limit' => 9999, 'username' => $username);

		// XSearch query begins with a search term
		$query = $searchterm;

		// and is followed by constraints
		$query .= $this->_build_collection_constraint($collection);
		$query .= $this->_build_star_rating_constraint($starrating);
		$query .= $this->_build_filetype_constraint($filetype);
		$query .= $this->_build_category_constraint($category);
		$query .= $this->_build_accepted_types_constraint($accepted_types);
		$query .= $this->_build_env_constraint($env);
  
		if ($myresources)
		{
		    if (!$username) {
		      $username 	= self::get_intralibrary_username();
		    }
		    $fn = self::get_intralibrary_fullName();
		    
			$query .= $this->_build_username_constraint($username,$fn);
			$xSearchParams['showUnpublished'] = TRUE;
		}

		$xsResp = new IntraLibrarySRWResponse('lom');
		$xsReq 	= new IntraLibraryXSearchRequest($xsResp);
		//Allow to make searches without keywords
		
		if ($searchterm == "*") {
		  /*if ($fn) {
    		    $qtest = substr($query,2);
    		    if (strlen($qtest)>0) {
    		      $query = $qtest;
    		    }
    		    $query = "dc.contributor=\"$fn\" ".$query;
		  } else {
    		  $qtest = substr($query,5);
    		  if (strlen($qtest)>0) {
    		    $query = $qtest;
    		  }
		  }*/
		  $qtest = substr($query,5);
		  if (strlen($qtest)>0) {
		    $query = $qtest;
		  }
		}
		$xSearchParams['query'] = $query;
		$xsReq->query($xSearchParams);
		return $xsResp->getRecords();
	}

	/**
	 * Add a 'my resources' constraint
	 *
	 * @param boolean $username
	 */
	private function _build_username_constraint($username,$fn)
	{
		//$cond = " AND rec.username=\"$username\"";
		if ($fn) {
		  //$cond = " AND (rec.username=$username OR dc.contributor=$fn)";
		}
		return $cond;
	}

	/**
	 * Add a star rating constraint
	 *
	 * @param string $starrating
	 * @return string
	 */
	private function _build_star_rating_constraint($starrating)
	{
		$ratings = array();
		switch ($starrating)
		{
			case 'star1':
				$ratings[] = 1;
			case 'star2':
				$ratings[] = 2;
			case 'star3':
				$ratings[] = 3;
			case 'star4':
				$ratings[] = 4;
				break;
			default:
				return '';
		}

		$query = $this->_match_any('intrallect.annotationextension_averagerating', $ratings);
		return " AND ($query)";
	}

	/**
	 * Add a filetype constraint
	 *
	 * @param string $filetype
	 * @return string
	 */
	private function _build_filetype_constraint($filetype)
	{
		switch ($filetype)
		{
		case 'image':
			$mime_types = array(
				'application/vnd.oasis.opendocument.image',
				'image/gif',
				'image/jpeg',
				'image/png',
				'image/svg+xml',
				'image/tif',
				'image/vnd.djvu',
				'image/x-bmp'
			);
			break;
		case 'pdf':
			$mime_types = array('application/pdf');
			break;
		case 'word':
			$mime_types = array('application/msword');
			break;
		case 'video':
		    $mime_types = array('video/mp4');
		default:
			return '';
		}

		$query = $this->_match_any('lom.technical_format', $mime_types);
		return " AND ($query)";
	}

	/**
	 * Add a collection constraint
	 *
	 * @param string $collection
	 * @return string
	 */
	private function _build_collection_constraint($collection) {
	  $cid = null;
	  $colid = '';
	  switch ($collection) {
		case 'bcu':
		  $cid = '6a6176612e7574696c2e52616e646f6d403436383130643430';
		  break;
		case 'getty':
		  $cid = '6a6176612e7574696c2e52616e646f6d403436346138313934';
		  break;
		case 'all':
		  $colid = ' AND (rec.collectionIdentifier="6a6176612e7574696c2e52616e646f6d403436383130643430" OR ';
		  $colid .= 'rec.collectionIdentifier="6a6176612e7574696c2e52616e646f6d403436346138313934" OR ';
		  $colid .= 'rec.collectionIdentifier="6a6176612e7574696c2e52616e646f6d403562393538383434")';
		  break;
	  }
	  if ($cid) {
	    $colid = ' AND rec.collectionIdentifier="' . $cid . '"';
	  }

		return $colid;
	}

	/**
	 * Add a category constraint
	 *
	 * @param string $categoryRefId
	 * @return string
	 */
	private function _build_category_constraint($categoryRefId)
	{
		if ($categoryRefId)
		{
			return " AND lom.classification_taxonpath_taxon_id=$categoryRefId";
		}

		return '';
	}

	/**
	 * Build a constraint based on Mooddle's accepted_types parameter
	 */
	private function _build_accepted_types_constraint($accepted_types)
	{
		$accepted_types = (array) $accepted_types;
		$types			= array();
		$mimetypes 		= array(
				'jpg'  =>  array('type'=>'image/jpeg'),
				'jpeg'  =>  array('type'=>'image/jpeg'),
				'gif'  =>  array('type'=>'image/gif'),
				'png'  =>  array('type'=>'image/png'),
				'mp3'	=> array('type'=>'audio/mpeg'),
				'mp4'	=> array('type'=>'video/mp4'),
		);
		foreach ($accepted_types as $type)
		{
			$type = substr($type, 1);
			if (isset($mimetypes[$type]['type']))
			{
				$types[] = $mimetypes[$type]['type'];
			}
		}

		// add missing audio types
		if (in_array('.mp3', $accepted_types) || in_array('.m4a', $accepted_types)) {
			if (!in_array('audio/mpeg', $types)) $types[] = 'audio/mpeg';
			if (!in_array('audio/x-mpeg', $types)) $types[] = 'audio/x-mpeg';
		}
		

		if (empty($types))
		{
			return '';
		}

		$query = $this->_match_any('lom.technical_format', $types);
		return " AND ($query)";
	}

	/**
	 * Build constraints based on Moodle's environment paraameter
	 */
	private function _build_env_constraint($env)
	{
		switch ($env) {
			case 'url':
				return ' AND intralibrary.type = "web"';
			case 'filepicker':
				$query = $this->_match_any('intralibrary.type', array('scorm1.2', 'scorm2004', 'imscp'));
				return " AND ($query)";
			default:
				return '';
		}
	}

	/**
	 * Generate a 'match any' clause
	 *
	 * @param string $parameter the parameter to match on
	 * @param array  $values    an array of acceptable values
	 */
	private function _match_any($parameter, $values)
	{
	  $conditions = array();
	  foreach(array_unique($values) as $v) {
	    $conditions[] = "$parameter=\"$v\"";
	  }
	  return implode(' OR ', $conditions);
	}
}
