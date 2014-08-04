<?php

class WebService {

    /**
     * Parsed configuration file
     * @var str[]
     */
    var $config;

    /**
     * Database connection
     * @var resource
     */
    var $db;

    /**
     * The HTTP request method used.
     * @var str
     */
    var $method = 'GET';

    /**
     * The HTTP request data sent (if any).
     * @var str
     */
    var $requestData = NULL;

	/**
	 * The URL extension stripped off of the request URL
	 * @var str
	 */
	var $extension = NULL;

    /**
     * The (on '/') exploded request URL without GET variables and extension
     * @var str[]
     */
    var $urlParts = array();

    /**
     * Array of strings to convert into the HTTP response.
     * @var str[]
     */
    var $output = array();

    /**
     * Constructor. Parses the configuration file, grabs any request data sent, records the HTTP
     * request method used and stores the exploded request URL for determining the requested data and parameters.
     * @param str iniFile Configuration file to use (Default: 'config.ini')
     */
    function WebService($iniFile = 'config.ini') {
		// parse given configuration file with grouped contents
        $this->config = parse_ini_file($iniFile, TRUE);
		// ignore non-webservice calls
        if (isset($_SERVER['REQUEST_URI']) && isset($_SERVER['REQUEST_METHOD'])) {
            if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
                $this->requestData = '';
				// open a stream to the request data
                $httpContent = fopen('php://input', 'r');
				// grab the request data sent
                while ($data = fread($httpContent, 1024)) {
                    $this->requestData .= $data;
                }
				// close request data stream
                fclose($httpContent);
            }

			// ignored the configured, preceeding baseURL from the request URL
            $urlString = substr($_SERVER['REQUEST_URI'], strlen($this->config['settings']['baseURL']));
			// explode request URL on '/'
			$urlParts = explode('/', $urlString);

			$lastPart = array_pop($urlParts);

			// check if there is a questionmark indicating GET variables
			$questionmarkPosition = strpos($lastPart, '?');
			if ($questionmarkPosition !== FALSE) {
				// cut off already parsed GET variables
				$lastPart = substr($lastPart, 0, $questionmarkPosition);
			}
			// check if there is a dot indication a specific extension
			$dotPosition = strpos($lastPart, '.');
			if ($dotPosition !== FALSE) {
				// remember given extension
				$this->extension = substr($lastPart, $dotPosition + 1);
				// cut off the extension for further URL handling
				$lastPart = substr($lastPart, 0, $dotPosition);
			}
			// reinsert last part without GET variables and extension
			array_push($urlParts, $lastPart);

			// remember non-empty request URL parts
			foreach ($urlParts as $singlePart) {
				if ($singlePart != '') {
					$this->urlParts[] = $singlePart;
				}
			}

			// remember request method
            $this->method = $_SERVER['REQUEST_METHOD'];
        }
    }

    /**
     * Execute with the given parameters and send the requested data or a corresponding error code.
	 * Accepted request URLs are ".../bottles/<ID>", ".../bottles/<ID>/messages" and "...bottles/<ID>/messages/<ID>".
     */
    function exec() {
		// validate request URL
		$partCount = count($this->urlParts);
		if ($partCount < 2 || $partCount > 4 || $this->urlParts[0] != 'bottles' || (count($this->urlParts) > 2 && $this->urlParts[2] != 'messages')) {
			// request URL does not fit the defined accepted patterns
			$this->notFound();
			exit;
		}
		// connect to MySQL database as defined in the configuration file
        $this->db = new mysqli($this->config['database']['server'],
							   $this->config['database']['username'],
							   $this->config['database']['password'],
							   $this->config['database']['schema']);
		if (mysqli_connect_error()) {
			// error while trying to connect to database
			$this->internalServerError();
			if ($this->config['settings']['debug']) {
				echo mysqli_connect_error();
			}
			exit;
		}

		// determine the specific request type and make sure it was used in combination with a valid request method
        switch ($partCount) {
            case 2:
				// .../bottles/<ID>: get info of bottle with given ID
				if ($this->method == 'GET') {
					$this->getBottleInfoByID($this->urlParts[1]);
				} else {
					$this->methodNotAllowed('GET');
				}
                break;
            case 3:
				// .../bottles/<ID>/messages: get or send a list of messages for bottle with given ID
				switch ($this->method) {
					case 'GET':
						$this->getMessageList($this->urlParts[1]);
						break;
					case 'POST':
						// check if the request body is valid json and fullfills are requirements
						if (!($jsonArray = json_decode($this->requestData, TRUE)) || !is_array($jsonArray)) {
							// could not parse JSON, answer with HTTP status Bad Request
							$this->badRequest();
							if ($this->config['settings']['debug']) {
								echo 'Invalid JSON array';
							}
						} elseif (!isset($_GET['del'])) {
							$this->insertMessages($this->urlParts[1], $jsonArray);
						} elseif (is_array($jsonArray)) {
							$this->markMessagesAsDeleted($this->urlParts[1], $jsonArray, $_GET['del']);
						} else {
							$this->badRequest();
							if ($this->config['settings']['debug']) {
								echo 'no ID array received';
							}
						}
						break;
					default:
						$this->methodNotAllowed('GET, POST');
				}
                break;
			case 4:
				// .../bottles/<ID>/messages/<ID>: get or send a single message
				switch ($this->method) {
					case 'GET':
						if ($this->extension != NULL && strtolower($this->extension) == 'png' ) {
							// return the image for the message with the given ID combination
							$this->outputPngImage($this->urlParts[1], $this->urlParts[3]);
						} else {
							// get the message with the given ID combination
							$this->getMessageByID($this->urlParts[1], $this->urlParts[3]);
						}
						break;
					case 'POST':
						// save the message for the given ID combination
						$this->insertMessage($this->urlParts[1], $this->urlParts[3]);
						break;
					default:
						$this->methodNotAllowed('GET, POST');
				}
				break;
        }
		// close database connection
        $this->db->close();
    }

	/**
	 * Get the stored informations for a single bottle.
	 * @var str bottleid ID of the requested bottle
	 */
	function getBottleInfoByID($bottleid) {
		// prepare statement
		if ($stmt = $this->db->prepare('Select name, count(message_id), protocolversionmajor, protocolversionminor From bottles Inner Join messages On id = bottle_id Where id = ?')) {
			// insert bottle ID in prepared statement and execute it
			if (!$stmt->bind_param('i', $bottleid) || !$stmt->execute()) {
				// inserting or statement execution failed due to an invalid bottle ID
				$this->badRequest();
				if ($this->config['settings']['debug']) {
					echo 'inserting or statement execution failed due to an invalid bottle ID';
				}
			} else {
				// bind variables for database result columns
				$stmt->bind_result($name, $numberOfMessages, $major, $minor);

				if (!$stmt->fetch() || $name == NULL) {
					// no bottle with the given ID was found
					$this->noContent();
				} else {
					// there is a bottle with the given ID, transfer informations in output array
					$this->output['id'] = intval($bottleid);
					$this->output['name'] = $name;
					$this->output['nom'] = $numberOfMessages;
					$this->output['protocolVersionMajor'] = $major;
					$this->output['protocolVersionMinor'] = $minor;
					// parse and send successfully collected bottle information
					$this->generateResponseData();
				}
			}
			// close statement
			$stmt->close();
		} else {
			// statement preparation failed
			$this->internalServerError();
			if ($this->config['settings']['debug']) {
				echo $this->db->error;
			}
		}
	}

	/**
	 * Get a list of the latest messages stored for a single bottle.
	 * The GET variable 'limit' is mandatory to hold the maximum number of requested messages.
	 * The GET variable 'offset' is optional for skipping a number of messages (Default: 0)
	 * @var str bottleid ID of the bottle for which the requested messages are stored for
	 */
	function getMessageList($bottleid) {
		if (!isset($_GET['limit']) && !isset($_GET['del'])) {
			// no limit or delete date set
			$this->badRequest();
			if ($this->config['settings']['debug']) {
				echo "GET variable 'limit' and 'del' not set (at least one is mandatory)";
			}
			return;
		}
		// else: prepare statement
		$sql = 'Select message_id, title, text, has_picture, author, timestamp, to_be_deleted, deleted, longitude, latitude, crc From messages Where bottle_id = ? ';
		if (isset($_GET['del'])) {
			$sql .= 'And (to_be_deleted = 1 Or deleted >= ?) ';
		}
		$sql .= 'Order By message_id Desc';
		if (!isset($_GET['del'])) {
			$sql .= ' Limit ?, ?';
		}
		if ($stmt = $this->db->prepare($sql)) {
			// get given offset or the default value 0
			$offset = isset($_GET['offset']) ? $_GET['offset'] : 0;
			$limit = $_GET['limit'];
			if ((isset($_GET['del']) ?
					// insert bottle ID and delete date
					!$stmt->bind_param('is', $bottleid, $_GET['del']) :
					// insert bottle ID, offset and limit
					!$stmt->bind_param('iii', $bottleid, $offset, $limit)) ||
					// try to execute statement
					!$stmt->execute()) {
				// inserting parameters of statement execution failed due to an invalid bottle ID or limit/offset
				$this->badRequest();
				if ($this->config['settings']['debug']) {
					echo 'inserting parameters of statement execution failed due to an invalid bottle ID or limit/offset';
				}
			} else {
				// bind variables for database result columns
				$stmt->bind_result($messageid, $title, $text, $hasPicture, $author, $timestamp, $toBeDeleted, $deleteDate, $longitude,$latitude, $crc);
			
				// iterate over result rows
				while($stmt->fetch()) {
					// create result array representing a single message
					$result = array();
					$result['btlID'] = intval($bottleid);
					$result['msgID'] = intval($messageid);
					if ($toBeDeleted == 1 || $deleteDate != NULL) {
						$result['txt'] = '';
					} else {
						if ($title != NULL) {
							$result['title'] = $title;
						}
						$result['txt'] = $text;
						if ($hasPicture == 1) {
							$result['img'] = $this->getImageURL($bottleid, $messageid);
						}
					}
					$result['author'] = $author;
					$result['time'] = $timestamp;
					if (isset($_GET['del'])) {
						$result['toBeDeleted'] = $toBeDeleted == 1;
						if ($deleteDate != NULL) {
							$result['deleted'] = $deleteDate;
						}
					}
					// the location is optional in the database and ignored in the result when undefined
					if ($longitude != NULL && $latitude != NULL) {
						$result['location'] = array();
						$result['location']['longitude'] = $longitude;
						$result['location']['latitude'] = $latitude;
					}
					$result['crc'] = $crc;
					// append result array (message) to output array
					$this->output[] = $result;
				}
				if (count($this->output) == 0) {
					// result set was empty: no messages found for a bottle with the given ID
					$this->noContent();
				} else {
					// parse and send successfully collected messages
					$this->generateResponseData();
				}
			}
			// close statement
			$stmt->close();
		} else {
			// statement preparation failed
			$this->internalServerError();
			if ($this->config['settings']['debug']) {
				echo $this->db->error;
			}
		}
	}

	/**
	 * Get a single message from a specific bottle.
	 * @var str bottleid ID of the bottle for which the requested message is stored for
	 * @var str messageid ID of the requested message
	 */
	function getMessageByID($bottleid, $messageid) {
		// prepare statement
		if ($stmt = $this->db->prepare('Select title, text, has_picture, author, timestamp, to_be_deleted, deleted, longitude, latitude, crc From messages Where bottle_id = ? And message_id = ?')) {
			// insert bottle ID and message ID; and execute statement
			if (!$stmt->bind_param('ii', $bottleid, $messageid) || !$stmt->execute()) {
				// inserting or statement execution failed due to an invalid bottle ID or message ID
				$this->badRequest();
				if ($this->config['settings']['debug']) {
					echo 'inserting or statement execution failed due to an invalid bottle ID or message ID';
				}
			} else {
				// bind variables for database result columns
				$stmt->bind_result($title, $text, $hasPicture, $author, $timestamp, $toBeDeleted, $deleteDate, $longitude, $latitude, $crc);
				
				if(!$stmt->fetch()) {
					// no message with the given message ID was found for a bottle with the given bottle ID
					$this->noContent();
				} else {
					// there is a message with the given message ID on the bottle with the given bottle ID
					// transfer informations in output array
					$this->output['btlID'] = intval($bottleid);
					$this->output['msgID'] = intval($messageid);
					
					if ($toBeDeleted == 1 || $deleteDate != NULL) {
						$this->output['txt'] = '';
					} else {
						if ($title != NULL) {
							$this->output['title'] = $title;
						}
						$this->output['txt'] = $text;
						if ($hasPicture == 1) {
							// insert direct URL to saved image
							$this->output['img'] = $this->getImageURL($bottleid, $messageid);
						}
					}
					$this->output['author'] = $author;
					$this->output['time'] = $timestamp;
					// the location is optional in the database and ignored in the result when undefined
					if ($longitude != NULL && $latitude != NULL) {
						$this->output['location'] = array();
						$this->output['location']['longitude'] = $longitude;
						$this->output['location']['latitude'] = $latitude;
					}
					$this->output['crc'] = $crc;
					// parse and send successfully collected message
					$this->generateResponseData();
				}
			}
			// close statement
			$stmt->close();
		} else {
			// statement preparation failed
			$this->internalServerError();
			if ($this->config['settings']['debug']) {
				echo $this->db->error;
			}
		}
	}
	
	/**
	 * Builds the URL for an image associated with the given ID combination for 
	 * @var str bottleid ID of the bottle the message is associated with
	 * @var str messageid ID of the message containing the image
	 * @return created URL
	 */
	function getImageURL($bottleid, $messageid) {
		// assuming it is a PNG
		return 'http://' . $_SERVER['SERVER_NAME'] . '/bottles/' . $bottleid . '/messages/' . $messageid . '.png';
	}
	
	/**
	 * Saves a single message with the given ID pair.
	 * The message itself is expected to be contained in JSON form in the body of the received request.
	 * Mandatory fields are the 'btlID' and 'msgID' which have to be equal to the given parameters in the request URL.
	 * Other mandatory fields are 'txt', 'author', 'time' and 'crc'.
	 * Further optional fields are 'img' (base64 encoded) and the 'location' array with its subfields 'longitude' and 'latitude'.
	 * @var str bottleid ID of the bottle for which the message should be stored for
	 * @var str messageid ID of the message to store
	 */
	function insertMessage($bottleid, $messageid) {
		// check if the request body is valid json and fullfills are requirements
		if (!($messageArray = json_decode($this->requestData, TRUE)) ||
			!isset($messageArray['btlID'], $messageArray['msgID'], $messageArray['txt'], $messageArray['author'],$messageArray['time'], $messageArray['crc']) || $messageArray['btlID'] != $bottleid || $messageArray['msgID'] != $messageid) {
			// something is wrong, answer with HTTP status Bad Request
			$this->badRequest();
			if ($this->config['settings']['debug']) {
				echo 'Invalid JSON object: at least one mandatory field (btlID, msgID, txt, author, time, crc) is not set or the bottle ID or message ID does not equal the IDs given in the request URL';
			}
			// else prepare insert statement
		} else if ($stmt = $this->prepareInsertMessageStatement()) {
			$error = $this->insertSingleMessage($stmt, $messageArray);
			// insert parameters; and execute statement
			if ($error) {
				// send answer wth HTTP status Bad Request
				$this->badRequest();
				if ($this->config['settings']['debug']) {
					echo $error;
				}
			} else {
				// send positive answer with the activated request URL for the newly stored message
				$this->created('http://' . $_SERVER['SERVER_NAME'] . '/bottles/' . $bottleid . '/messages/' . $messageid);
			}
			// close statement
			$stmt->close();
		} else {
			// statement preparation failed
			$this->internalServerError();
			if ($this->config['settings']['debug']) {
				echo $this->db->error;
			}
		}
	}
	
	/**
	 * Saves multiple messagea for the bottle with the given ID.
	 * The messages itself are expected to be contained in an JSON array in the body of the received request.
	 * The 'msgID' fields has to be equal to the given parameter in the request URL.
	 * Other mandatory fields are 'txt', 'author', 'time' and 'crc'.
	 * Further optional fields are 'img' (base64 encoded) and the 'location' array with its subfields 'longitude' and 'latitude'.
	 * @var str bottleid ID of the bottle for which the message should be stored for
	 * @var mixed messagesList array of messages to store
	 */
	function insertMessages($bottleid, $messagesList) {
		// prepare insert statement
		if ($stmt = $this->prepareInsertMessageStatement()) {
			// enable rollback option
			$this->db->autocommit(FALSE);
			// iterate over single messages
			foreach ($messagesList as $messageArray) {
				// check mandatory fields and if the given bottle IDs are equal
				if (!isset($messageArray['btlID'], $messageArray['msgID'], $messageArray['txt'], $messageArray['author'],$messageArray['time'], $messageArray['crc']) || $messageArray['btlID'] != $bottleid) {
					// something is wrong with the content of this message
					$error = 'Invalid JSON object: at least one mandatory field (btlID, msgID, txt, author, time, crc) is not set or the bottle ID does not equal the ID given in the request URL';
					break;
				}
				// try to insert this message
				if ($error = $this->insertSingleMessage($stmt, $messageArray)) {
					break;
				}
				
			}
			if ($error) {
				// an error occured: rollback executed statements
				$this->db->rollback();
				// send Bad Request HTTP status
				$this->badRequest();
				if ($this->config['settings']['debug']) {
					// in debug mode: output specific error message
					echo $error;
				}
			} else {
				// everything went fine: commit changes
				$this->db->commit();
				// send succesfull Created HTTP status
				$this->created('http://' . $_SERVER['SERVER_NAME'] . '/bottles/' . $bottleid . '/messages?limit=' . count($messagesList));
			}
			// close statement
			$stmt->close();
		} else {
			// statement preparation failed
			$this->internalServerError();
			if ($this->config['settings']['debug']) {
				echo $this->db->error;
			}
		}
	}
	
	/**
	 * Prepares the Insert statement for a single message.
	 * Allows to update in case of a duplicate key (overwrite previously saved message).
	 * @return mysqli_stmt prepared statement
	 */
	function prepareInsertMessageStatement() {
		return $this->db->prepare('Insert Into messages (bottle_id, message_id, title, text, has_picture, author, timestamp, longitude, latitude, crc) Values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) On Duplicate Key Update message_id=message_id');
	}
	
	/**
	 * Expects the prepared statement generated by the 'prepareInsertMessageStatement()' function.
	 * Insert the fields from the given message and executes the statement.
	 * @var mysqli_stmt stmt prepared statement from 'prepareInsertMessageStatement()' function
	 * @var mixed messageArray message to insert in database
	 * @return if an error occured: the error message string, else FALSE
	 */
	function insertSingleMessage($stmt, $messageArray) {
		// collect parsed JSON values in corresponding variables
		$bottleid = $messageArray['btlID'];
		$messageid = $messageArray['msgID'];
		$title = isset($messageArray['title']) ? $messageArray['title'] : NULL;
		$text = $messageArray['txt'];
		$author = $messageArray['author'];
		$timestamp = $messageArray['time'];
		$crc = $messageArray['crc'];
		// beware of optional location array
		$longitude = isset($messageArray['location']) && isset($messageArray['location']['longitude']) ? $messageArray['location']['longitude'] : NULL;
		$latitude = isset($messageArray['location']) && isset($messageArray['location']['latitude']) ? $messageArray['location']['latitude'] : NULL;
		// prepare designated save path for optional image
		$imageFolder ='images/bottles/' . $bottleid . '/';
		// first draft: always assume image type png
		$imagePath = $imageFolder . $messageid . '.png';
		$hasPicture = FALSE;
		// try to create the image folder for this folder, if one is needed
		if (isset($messageArray['img']) &&
			(file_exists($imageFolder) || mkdir($imageFolder, 0774, TRUE))) {
			// try to save the given image data and assume base64 encoding
			$hasPicture = file_put_contents($imagePath, base64_decode($messageArray['img'])) != FALSE;
		}
		$hasPicture = $hasPicture ? 1 : 0;
		// insert parameters; and execute statement
		if (!$stmt->bind_param('iississdds', $bottleid, $messageid, $title, $text, $hasPicture, $author, $timestamp, $longitude, $latitude, $crc) || !$stmt->execute()) {
			// variable binding or statement execution failed
			if ($hasPicture) {
				// delete the image file
				unlink($imagePath);
			}
			// return error message
			return "variable binding or statement execution failed due to invalid message data\n" . $this->db->error;
		} elseif ($this->db->affected_rows == 0) {
			return sprintf('a message with the ID %s in the selected bottle (ID = %s) already exists', $messageid, $bottleid);
		}
		return FALSE;
	}
	
	/**
	 * Marks a list of messages with the given IDs for a single bottle as successfully deleted.
	 * @var str bottleid ID of the bottle the messages are associated with
	 * @var mixed messageids IDs of the 'toBeDeleted' messages to mark as actually deleted
	 * @var str deleteDate date to set for completed deleting of the messages with the given IDs
	 */
	function markMessagesAsDeleted($bottleid, $messageids, $deleteDate) {
		// prepare update statement
		if ($stmt = $this->db->prepare('Update messages Set to_be_deleted = 0, deleted = ? Where to_be_deleted = 1 And message_id = ?')) {
			$this->db->autocommit(FALSE);
			foreach ($messageids as $singleMessageID) {
				$id = intval($singleMessageID);
				// insert date and single ID; and execute statement; and check if exactly one row was affected
				if (!$stmt->bind_param('si', $deleteDate, $id) || !$stmt->execute()) {
					//  || $this->db->affectedRows != 1
					$error = TRUE;
					break;
				}
			}
			if ($error) {
				// something was wrong: rollback former actions
				$this->db->rollback();
				// send answer with HTTP status Bad Request
				$this->badRequest();
				if ($this->config['settings']['debug']) {
					printf('invalid argument (date: %s, ids: (%s))', $deleteDate, var_export($messageids, TRUE));
				}
			} else {
				// everything went fine: commit all updates
				$this->db->commit();
				// send success status without data
				$this->noContent();
			}
			// close statement
			$stmt->close();
		} else {
			// statement preparation failed
			$this->internalServerError();
			if ($this->config['settings']['debug']) {
				echo $this->db->error;
			}
		}
	}

    /**
     * Generate the HTTP response data according to the extension variable.
	 * Possible output options are 'html', 'txt', 'xml' and the default: 'json'.
	 */
    function generateResponseData() {
		if ($this->extension == null) {
			// no extension set: use default JSON output
			$this->outputToJSON();
		} else {
			// evaluate request extension
			switch (strtolower($this->extension)) {
				case 'htm':
				case 'html':
					// output as HTML
					$this->outputToHTML();
					break;
				case 'txt':
					// output as plain text
					$this->outputToPlainText();
					break;
				case 'xml':
					// output as XML
					$this->outputToXML();
					break;
				default:
					// output as JSON
					$this->outputToJSON();
			}
		}
    }

	/**
	 * Output the content of the collected output array as JSON.
	 */
	function outputToJSON() {
		// set content header
		header('Content-Type: application/json');
		// generate the JSON formatted output
		echo json_encode($this->output);
	}

	/**
	 * Output the content of the collected output array as HTML.
	 */
	function outputToHTML() {
		// set the content header
		header('Content-Type: text/html');
		// create a dom document with utf8 encoding
		$domtree = new DOMDocument('1.0', 'UTF-8');
		// create the root element of the html tree and append it to the created document
		$htmlRoot = $domtree->appendChild($domtree->createElement("html"));
		// insert body as child element of the html root
		$bodyElement = $htmlRoot->appendChild($domtree->createElement("body"));
		// insert array values
		$this->appendArrayToHTML($this->output, $domtree, $bodyElement);
		// make sure the output is in readable format
		$domtree->formatOutput = TRUE;
		// print out created html (encoding according to the dom document: utf8)
		echo $domtree->saveHTML();
	}
	
	/**
	 * Create an unordered list representing the given array in the DOM document.
	 * @var mixed targetArray array to be transfered into an unordered HTML list
	 * @var DomDocument designated DOM document containing the HTML tree
	 * @var DomNode parent element to append the created list to 
	 */
	function appendArrayToHTML($targetArray, $domtree, $parent) {
		// create unordered list for array
		$ulElement = $parent->appendChild($domtree->createElement(isset($targetArray[0]) ? "ol" : "ul"));
		// insert each output entry as list element
		foreach ($targetArray as $key => $value) {
			// create list element for output entry
			$listElement = $ulElement->appendChild($domtree->createElement("li"));
			if (is_string($key)) {
				// insert bold key prefix
				$listElement->appendChild($domtree->createElement("b", $key . ": "));
			}
			if (is_array($value)) {
				$this->appendArrayToHTML($value, $domtree, $listElement);
			} else {
				// format the value as string regarding boolean values
				$formattedValue = is_bool($value) ? $value == FALSE ? "false" : "true" : $value;
				// insert value text
				$listElement->appendChild($domtree->createCDATASection($formattedValue));
			}
		}
	}

	/**
	 * Output the content of the collected output array as XML.
	 */
	function outputToXML() {
		// set the content header
		header('Content-Type: application/xml');
		// create a dom document with utf8 encoding
		$domtree = new DOMDocument('1.0', 'UTF-8');
		// create the root element of the xml tree and append it to the created document
		$xmlRoot = $domtree->appendChild($domtree->createElement("xml"));
		// insert array values
		$this->appendArrayToXML($this->output, $domtree, $xmlRoot);
		// make sure the output is in readable format
		$domtree->formatOutput = TRUE;
		// print out created xml
		echo $domtree->saveXML($domtree->document);
	}
	
	/**
	 * Create a sub tree representing the given array in the DOM document.
	 * @var mixed targetArray array to be transfered into a sub tree in the XML
	 * @var DomDocument designated DOM document containing the XML
	 */
	function appendArrayToXML($targetArray, $domtree, $parent) {
		// insert each output entry as list element
		foreach ($targetArray as $key => $value) {
			// insert entry as child element of the xml root
			$entryTag = is_string($key) ? $key : (!is_array($value) ? "entry" : (isset($value['msgID']) ? "message" : "bottle"));
			$entry = $parent->appendChild($domtree->createElement($entryTag));
			if (is_array($value)) {
				$this->appendArrayToXML($value, $domtree, $entry);
			} else {
				// format the value as string regarding boolean values
				$formattedValue = is_bool($value) ? $value == FALSE ? "false" : "true" : $value;
				$entry->appendChild($domtree->createCDATASection($formattedValue));
			}
		}
		return $ulElement;
	}

	/**
	 * Output the content of the collected output array as plain text.
	 */
	function outputToPlainText() {
		// set the content header
		header('Content-Type: text/plain');
		// insert each output entry
		$this->appendArrayToPlainText($this->output, 0);
	}
	
	/**
	 * Create a sub tree representing the given array in the DOM document.
	 * @var mixed targetArray array to be inserted
	 * @var int indent number of space characters to indent values
	 */
	function appendArrayToPlainText($targetArray, $indent) {
		$indentSpace = '';
		for ($i = 0; $i < $indent; $i++){
			$indentSpace .= ' ';
		}
		// insert each output entry as list element
		foreach ($targetArray as $key => $value) {
			// insert key text
			echo $indentSpace . $key . ": ";
			if (is_array($value)) {
				echo "\n";
				$this->appendArrayToPlainText($value, $indent + strlen(strval($key)) + 2);
			} else {
				// insert value text
				echo is_bool($value) ? $value == FALSE ? "false" : "true" : $value;
			}
			echo "\n";
		}
	}

	/**
	 * Output the image stored for the given ID combination.
	 * @var str bottleid ID of the bottle for which the requested image is stored for
	 * @var str messageid ID of the message the image is assigned to
	 */
	function outputPngImage($bottleid, $messageid) {
		// build conventional path to stored image
		$path = "images/bottles/".$bottleid."/".$messageid.".png";
		// load the image
		if (file_exists($path) && ($image = imagecreatefrompng($path))) {
			// send the content header
			header('Content-Type: image/png');
			// output image
			imagepng($image);
			// dispose the image resource
			imagedestroy($image);
		} else {
			// not image for the given ID combination found
			$this->notFound();
		}
	}

    /**
     * Send a HTTP 201 response header.
     */
    function created($url = FALSE) {
        header('HTTP/1.0 201 Created');
        if ($url) {
            header('Location: '.$url);
        }
    }

    /**
     * Send a HTTP 204 response header.
     */
    function noContent() {
        header('HTTP/1.0 204 No Content');
    }

    /**
     * Send a HTTP 400 response header.
     */
    function badRequest() {
        header('HTTP/1.0 400 Bad Request');
    }

    /**
     * Send a HTTP 404 response header.
     */
    function notFound() {
        header('HTTP/1.0 404 Not Found');
    }

    /**
     * Send a HTTP 405 response header.
     */
    function methodNotAllowed($allowed) {
        header('HTTP/1.0 405 Method Not Allowed');
        header('Allow: '.$allowed);
    }

    /**
     * Send a HTTP 503 response header.
     */
    function internalServerError() {
        header('HTTP/1.0 503 Internal Server Error');
    }

}

?>

