<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is: vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

vimport ('~modules/MailManager/models/Message.php');

class MailManager_Office365_Connector extends MailManager_Connector_Connector {
    
    public $access_token;
    
    public $refresh_token;
    
    public $model;
    
    public $officeFolders = array();
    
    private $baseUrl = 'https://graph.microsoft.com/v1.0';
    
    public static function connectorWithModel($model, $type = '') {
        
        $tokens = json_decode($model->password(), true);
        
        return new MailManager_Office365_Connector($tokens);
    
    }
    
    public function __construct($tokens = false) {
        
        if (!$tokens || !isset($tokens['access_token'])) {
            throw new Exception('Invalid tokens provided');
        }
        
        $this->access_token = $tokens['access_token'];
        
        $this->refresh_token = $tokens['refresh_token'] ?? null;
        
        // Test connection
        try {
            $user = $this->makeGraphRequest('/me');
            $this->mBox = true; // Connection successful
        } catch (Exception $e) {
            error_log('Office365 Connector initialization failed: ' . $e->getMessage());
            $this->mBox = false;
        }
    }
    
    private function makeGraphRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->access_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
		
		if($method == 'SEARCH'){
			//print_r($response);
		}
		
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		
        $error = curl_error($ch);
        
		curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        
        if ($httpCode >= 400) {
            throw new Exception('HTTP Error ' . $httpCode . ': ' . $response);
        }
   
        return json_decode($response, true);
    }
    
    public function isConnected() {
        return !empty($this->mBox);
    }
    
    public function folders($ref = "{folder}") {
        
        if ($this->mFolders) return $this->mFolders;
        
        if (!$this->isConnected()) {
            return array();
        }
        
        try {
            
            $response = $this->makeGraphRequest('/me/mailFolders?$top=100');
            
            $folder_data = array();
            
            if (isset($response['value'])) {
                
                foreach ($response['value'] as $folderData) {
                    $folderInstance = $this->folderInstance($folderData['displayName']);
                    $folderInstance->setFromArray($folderData);
                    $folderInstance->folderId = base64_encode($folderData['id']);
                    $folder_data[] = $folderInstance;
                }
                
            }
            
            $this->mFolders = $folder_data;
            
            return $folder_data;
            
        } catch (Exception $e) {
            
            return array();
        
        }
        
    }
    
    public function folderInstance($val) {
        $instance = new MailManager_Office365Folder_Model($val);
        return $instance;
    }
    
    public function updateFolders($options = SA_UNSEEN) {
        $this->folders(); // Initializes the folder Instance
        
        if (!empty($this->mFolders)) {
            foreach ($this->mFolders as $folder) {
                if (strtolower($folder->name()) == 'inbox') {
                    $this->updateFolder($folder, $options);
                }
            }
        }
    }
    
    public function updateFolder($folder, $options) {
        if (!$this->isConnected()) {
            return;
        }
        
        try {
            $folder->setCount('0');
            $folderid = '';
            
            foreach ($this->getFolderList() as $key => $officeFolder) {
                if (strtoupper($officeFolder) == strtoupper($folder->name())) {
                    $folderid = $key;
                    break;
                }
            }
            
            if ($folderid) {
                $decodedFolderId = base64_decode($folderid);
                
                // Get unread messages count
                $endpoint = '/me/mailFolders/' . $decodedFolderId . '/messages?$filter=IsRead ne true&$count=true';
                $response = $this->makeGraphRequest($endpoint);
                
                $unreadCount = isset($response['@odata.count']) ? $response['@odata.count'] : 0;
                $folder->setUnreadCount((string)$unreadCount);
            }
            
        } catch (Exception $e) {
            error_log('Error updating folder: ' . $e->getMessage());
            $folder->setUnreadCount('0');
        }
    }
    
    public function getFolderList() {
        $folders = $this->folders();
        $folderLists = array();
        
        if (!empty($folders)) {
            foreach ($folders as $folder) {
                $folderLists[$folder->folderId] = $folder->name();
            }
        }
        
        return $folderLists;
    }
    
    public function folderMails($folder, $page_number, $maxLimit) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $folderid = '';
            
            foreach ($this->getFolderList() as $key => $officeFolder) {
                if (strtoupper($officeFolder) == strtoupper($folder->name())) {
                    $folderid = $key;
                    break;
                }
            }
            
            if (!$folderid) {
                return false;
            }
            
            $folderid = base64_decode($folderid);
            
            $folder->setNextLink('');
            $folder->setPreviousLink('');
            
            if (!($maxLimit > 0)) {
                $maxLimit = 20;
            }
            
            $skip_records = $page_number * $maxLimit;
            $start = $page_number * $maxLimit + 1;
            $end = $start;
            
            if ($start < 1) $start = 1;
            if (!($start <= 1)) $folder->setPreviousLink('true');
            
            // Build the endpoint URL with query parameters
            $queryParams = [
                '$expand=attachments',
                '$orderby=receivedDateTime%20desc',
                '$skip=' . $skip_records,
                '$top=' . $maxLimit,
                '$count=true'
            ];
            
            $endpoint = '/me/mailFolders/' . $folderid . '/messages?' . implode('&', $queryParams);
            $response = $this->makeGraphRequest($endpoint);
            
            $totalCount = isset($response['@odata.count']) ? $response['@odata.count'] : 0;
            $folder->setCount($totalCount);
            
            $folder_messages = isset($response['value']) ? $response['value'] : [];
            
            if (!empty($folder_messages)) {
                $end = $start + count($folder_messages) - 1;
                
				$mails = array();
                
				$mailIds = array();
                
                $messageModel = new MailManager_Office365Message_Model();
                
                foreach ($folder_messages as $messageData) {
                    
					$loaded = $messageModel->readFromDB($messageData['id']);
                    
					$mailObject = $messageModel->parseOverview($messageData);
                    
                    $attachments = array();
                    
                    if (isset($messageData['attachments'])) {
                        foreach ($messageData['attachments'] as $attachment) {
                            if (!$attachment['isInline'] && $attachment['@odata.type'] == '#microsoft.graph.fileAttachment') {
                                $attachments[] = array(
                                    "Name" => $attachment['name'],
                                    "ContentBytes" => isset($attachment['contentBytes']) ? $attachment['contentBytes'] : ''
                                );
                            }
                        }
                        
                        if (!empty($attachments)) {
                            $mailObject->_attachments = $attachments;
                        }
                    }
                    
					$mailObject->_inline_attachments = array();
					
                    if (!$loaded) {
                        $loaded = new MailManager_Office365Message_Model('office365', $messageData['id'], true, $messageData);
                    }
                    
                    $mailId = $loaded->_mailRecordId;
                    $mailObject->setMsgNo($mailId);
                    
                    $mails[] = $mailObject;
                    $mailIds[] = $mailId;
                }
                
                $folder->setMails($mails);
                $folder->setMailIds($mailIds);
                $folder->setPaging($start, $end, $maxLimit, $totalCount, $page_number);
                
                // Check if there are more messages (next page)
                if (count($folder_messages) == $maxLimit && ($skip_records + $maxLimit) < $totalCount) {
                    $folder->setNextLink('true');
                }
            }
            
        } catch (Exception $e) {
            error_log('Error fetching folder mails: ' . $e->getMessage());
            return false;
        }
    }
    
    
    public function close() {
        if (!empty($this->mBox)) {
           
            $this->mBox = null;
        }
    }
    
    
    public function openMail($msgno, $folder) {
        
        $this->clearDBCache();
        
        $message_instance = MailManager_Office365Message_Model::getMailDetailById($msgno);
        
        return $message_instance;
        
    }
    
    public function markMailRead($msgno) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $endpoint = '/me/messages/' . $msgno;
            $data = array("isRead" => true);
            
            $response = $this->makeGraphRequest($endpoint, 'PATCH', $data);
            
            $this->mModified = true;
            
            return true;
            
        } catch (Exception $e) {
            error_log('Error marking mail as read: ' . $e->getMessage());
            return false;
        }
    }
    
    public function markMailUnread($msgno) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $endpoint = '/me/messages/' . $msgno;
            $data = array("isRead" => false);
            
            $response = $this->makeGraphRequest($endpoint, 'PATCH', $data);
            
            $this->mModified = true;
            
            return true;
            
        } catch (Exception $e) {
            error_log('Error marking mail as unread: ' . $e->getMessage());
            return false;
        }
    }
    
    public function deleteMail($msgno) {
        if (!$this->isConnected()) {
            return false;
        }
        
        // Handle comma-separated message IDs
        $msgno = trim($msgno, ',');
		
        $msgnoArray = explode(',', $msgno);
        
        $success = true;
        
        try {
            for ($i = 0; $i < count($msgnoArray); $i++) {
                $messageId = trim($msgnoArray[$i]);
                
                if (!empty($messageId)) {
                    try {
                        $endpoint = '/me/messages/' . $messageId;
                        $this->makeGraphRequest($endpoint, 'DELETE');
                    } catch (Exception $e) {
                        error_log('Error deleting message ' . $messageId . ': ' . $e->getMessage());
                        $success = false;
                    }
                }
            }
            
            if ($success) {
                $this->mModified = true;
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log('Error in deleteMail: ' . $e->getMessage());
            return false;
        }
    }
    
    public function moveMail($msgno, $folderName) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            // Find folder ID
            $folderid = '';
            foreach ($this->getFolderList() as $key => $officeFolder) {
                if (strtoupper($officeFolder) == strtoupper($folderName)) {
                    $folderid = $key;
                    break;
                }
            }
            
            if (empty($folderid)) {
                error_log('Folder not found: ' . $folderName);
                return false;
            }
            
            // Handle comma-separated message IDs
            $msgno = trim($msgno, ',');
            $msgnoArray = explode(',', $msgno);
            
            $destinationFolderId = base64_decode($folderid);
            $data = array('destinationId' => $destinationFolderId);
            
            $success = true;
            
            for ($i = 0; $i < count($msgnoArray); $i++) {
                $messageId = trim($msgnoArray[$i]);
                
                if (!empty($messageId)) {
                    try {
                        $endpoint = '/me/messages/' . $messageId . '/move';
                        $this->makeGraphRequest($endpoint, 'POST', $data);
                    } catch (Exception $e) {
                        error_log('Error moving message ' . $messageId . ': ' . $e->getMessage());
                        $success = false;
                    }
                }
            }
            
            if ($success) {
                $this->mModified = true;
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log('Error in moveMail: ' . $e->getMessage());
            return false;
        }
    }
	
	function writetofile($str){
		$fh = fopen("test.txt", "a");
		fwrite($fh, PHP_EOL . $str . PHP_EOL);
		fclose($fh);
	}
    
	public function searchMails($query, $folder, $page_number, $maxLimit, $skipToken = null) {
		
		if (!$this->isConnected()) {
			return false;
		}

		try {
			$folder->setNextLink('');
			$folder->setPreviousLink('');
        
			if (!($maxLimit > 0)) {
				$maxLimit = 20; // Match folderMails default
			}
        
			// Find folder ID
			$folderId = '';
			foreach ($this->getFolderList() as $key => $officeFolder) {
				if (strtoupper($officeFolder) == strtoupper($folder->name())) {
					$folderId = $key;
					break;
				}
			}
        
			if (empty($folderId)) {
				error_log('Folder not found: ' . $folder->name());
				return false;
			}
        
			$decodedFolderId = base64_decode($folderId);
        
			// Process query format - try different approaches
			$formattedQuery = '';
			if (!empty($query)) {
				// Try the original format first
				$parts = explode(' ', trim($query));
				$keyword = $parts[0];
				$value = isset($parts[1]) ? str_replace('"', '', $parts[1]) : '';
				
				if (!empty($value)) {
					$formattedQuery = '"' . $keyword . ':' . $value . '"';
				} else {
					// If no value part, search the keyword directly
					$formattedQuery = '"' . $keyword . '"';
				}
			}
        
			if (empty($formattedQuery)) {
				return false;
			}
        
			error_log('Search query: ' . $formattedQuery); // Debug log
        
			// Calculate skip_records like folderMails
			$skip_records = $page_number * $maxLimit;
			$start = $page_number * $maxLimit + 1;
			$end = $start;
        
			if ($start < 1) $start = 1;
			
			// Build search endpoint
			$queryParams = [
				'$search=' . rawurlencode($formattedQuery),
				'$expand=attachments',
				'$top=' . $maxLimit,
				'$count=true'
			];
			
			// Add skipToken for pagination if provided
			if (!empty($_SESSION['office365']['search'][$page_number])) {
				$queryParams[] = '$skipToken=' . rawurlencode($_SESSION['office365']['search'][$page_number]);
			}
			
			$endpoint = '/me/mailFolders/' . $decodedFolderId . '/messages?' . implode('&', $queryParams);
		
			$response = $this->makeGraphRequest($endpoint, 'SEARCH');
			
			if (!$response) {
				error_log('No response from Graph API');
				return false;
			}
        
			$records = isset($response['value']) ? $response['value'] : [];
			$actualRecordCount = count($records);
        
			// Microsoft Graph API $count with $search often returns 0 even when there are results
			// So we need to estimate total count for search operations
			$totalCount = isset($response['@odata.count']) ? $response['@odata.count'] : 0;
        
			// If count is 0 but we have records, estimate total count
			if ($totalCount == 0 && $actualRecordCount > 0) {
				// If we got exactly maxLimit records, there might be more pages
				if ($actualRecordCount == $maxLimit) {
					// Estimate total as at least current page + 1 page worth
					$totalCount = ($page_number + 2) * $maxLimit;
				} else {
					// We got less than maxLimit, so this is likely the last page
					$totalCount = ($page_number * $maxLimit) + $actualRecordCount;
				}
			}
        
			$folder->setCount($totalCount);
        
			if (!empty($records)) {
				$nmsgs = count($records);
				$end = $start + $nmsgs - 1;
            
				$mails = array();
				$mailIds = array();
				
				$messageModel = new MailManager_Office365Message_Model();
            
				foreach ($records as $messageData) {
					$loaded = $messageModel->readFromDB($messageData['id']);
					$mailObject = $messageModel->parseOverview($messageData);
                
					$attachments = array();
                
					if (isset($messageData['attachments'])) {
						foreach ($messageData['attachments'] as $attachment) {
							// Only process file attachments, ignore embedded emails
							if ($attachment['@odata.type'] === '#microsoft.graph.fileAttachment' && !$attachment['isInline']) {
								$attachments[] = array(
									"Name" => $attachment['name'],
									"ContentBytes" => isset($attachment['contentBytes']) ? $attachment['contentBytes'] : ''
								);
							}
						}
                    
						if (!empty($attachments)) {
							$mailObject->_attachments = $attachments;
						}
					}
                
					$mailObject->_inline_attachments = array(); // Add this like folderMails
                
					if (!$loaded) {
						$loaded = new MailManager_Office365Message_Model('office365', $messageData['id'], true, $messageData);
					}
                
					$mailId = $loaded->_mailRecordId;
					$mailObject->setMsgNo($mailId);
                
					$mails[] = $mailObject;
					$mailIds[] = $mailId;
				}
            
				$folder->setMails($mails);
				$folder->setMailIds($mailIds);
            
			} else {
				// No results found
				$folder->setMails(array());
				$folder->setMailIds(array());
            
				if ($totalCount == 0) {
					$start = 0;
					$end = 0;
				}
				
			}
        
			$folder->setPaging($start, $end, $maxLimit, $totalCount, $page_number);
			
			if (!($start <= 1)) {
				$folder->setPreviousLink('true');
			}
        
			// Set next link logic
			if (!empty($records)) {
				
				if (isset($response['@odata.nextLink']) && $response['@odata.nextLink'] != '') {
					
					$nextLink = $response['@odata.nextLink'];
					
					if (preg_match('/(?:%24|\$)skiptoken=([^&]+)/i', $nextLink, $matches)) {
						
						$nextSkipToken = urldecode($matches[1]);
						
						if($page_number > 0){
							$_SESSION['office365']['search'][$page_number] = $nextSkipToken;
						}
						
						$folder->setNextLink($nextSkipToken);
					}
					
				} else {
					
					if (count($records) == $maxLimit) {
						$folder->setNextLink('true');
					}
					
				}
			}
			
			return true;
        
		} catch (Exception $e) {
			return false;
		}
	}
}
?>