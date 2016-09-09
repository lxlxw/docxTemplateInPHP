<?php
/**
 * User: Raghavendra K R
 * Date: 16/3/15
 * Time: 10:05 AM
 */
namespace icircle\Template\Docx;

use icircle\Template\Exceptions\RepeatRowException;
use icircle\Template\KeyNode;
use icircle\Template\Exceptions\RepeatParagraphException;

class DocxTemplate {
    private $template = null;
    private $keyStartChar = '[';
    private $keyEndChar   = ']';
    private $slNoKey = "slNo";
    private $locale = "en_IN";
    private $logFile = null;

    // for internal Use
    private $workingDir = null;
    private $workingFile = null;
    private $incompleteKeyNodes = array();
    private $development = false;

    function __construct($templatePath){
        if(!file_exists($templatePath)){
            throw new \Exception("Invalid Template Path");
        }
        $this->template = $templatePath;
    }

    function merge($data, $outputPath, $download = false, $protect=false){
        //open the Archieve to a temp folder

        $this->workingDir = sys_get_temp_dir()."/DocxTemplating";
        if(!file_exists($this->workingDir)){
            mkdir($this->workingDir,0777,true);
        }
        $workingFile = tempnam($this->workingDir,'');
        if($workingFile === FALSE || !copy($this->template,$workingFile)){
            throw new \Exception("Error in initializing working copy of the template");
        }
        $this->workingDir = $workingFile."_";
        $zip = new \ZipArchive();
        if($zip->open($workingFile) === TRUE){
            $zip->extractTo($this->workingDir);
            $zip->close();
        }else{
            throw new \Exception('Failed to extract Template');
        }

        if(!file_exists($this->workingDir)){
            throw new \Exception('Failed to extract Template');
        }

        $filesToParse = array(
            array("name"=>"word/document.xml","required"=>true),
            array("name"=>"word/header1.xml"),
            array("name"=>"word/header2.xml"),
            array("name"=>"word/header3.xml"),
            array("name"=>"word/footer1.xml"),
            array("name"=>"word/footer2.xml"),
            array("name"=>"word/footer3.xml"),
            array("name"=>"word/footnotes.xml"),
            array("name"=>"word/endnotes.xml")
        );

        foreach($filesToParse as $fileToParse){
            if(isset($fileToParse["required"]) && !file_exists($this->workingDir.'/'.$fileToParse["name"])){
                throw new \Exception("Can not merge, Template is corrupted");
            }
            if(file_exists($this->workingDir.'/'.$fileToParse["name"])){
                $this->mergeFile($this->workingDir.'/'.$fileToParse["name"],$data);
            }
        }

        if($protect === true){
            $settingsFile = $this->workingDir.'/word/settings.xml';

            $settingsDocument = new \DOMDocument();
            if($settingsDocument->load($settingsFile) === FALSE){
                throw new \Exception("Error in protecting the document");
            }

            $documentProtectionElement = $settingsDocument->createElement("w:documentProtection");
            $documentProtectionElement->setAttribute("w:cryptAlgorithmClass","hash");
            $documentProtectionElement->setAttribute("w:cryptAlgorithmSid","4");
            $documentProtectionElement->setAttribute("w:cryptAlgorithmType","typeAny");
            $documentProtectionElement->setAttribute("w:cryptProviderType","rsaFull");
            $documentProtectionElement->setAttribute("w:cryptSpinCount","100000");
            $documentProtectionElement->setAttribute("w:edit","readOnly");
            $documentProtectionElement->setAttribute("w:enforcement","1");
            $documentProtectionElement->setAttribute("w:hash","agIYzNUC1FNp4sJAazkA+rOu3Bw=");
            $documentProtectionElement->setAttribute("w:salt","ydP+pf0vmKAQkaM0gyb9TQ==");

            $settingsDocument->documentElement->appendChild($documentProtectionElement);

            if($settingsDocument->save($settingsFile) === FALSE){
                throw new \Exception("Error in creating output");
            }
        }

        // once merge is happened , zip the working directory and rename
        $mergedFile = $this->workingDir.'/output.docx';
        if($zip->open($mergedFile,\ZipArchive::CREATE) === FALSE){
            throw new \Exception("Error in creating output");
        }

        // Create recursive directory iterator
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workingDir,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach($files as $name=>$file){
                $name = substr($name,strlen($this->workingDir."/"));
                $zip->addFile($file->getRealPath(),$name);
                //echo "\n".$name ."  :  ".$file->getRealPath();
        }
        $zip->close();

        //once merged file is available copy it to $outputPath or write as downloadable file
        if($download == false){
            copy($mergedFile,$outputPath);
        }else{
            $fInfo = new \finfo(FILEINFO_MIME);
            $mimeType = $fInfo->file($mergedFile);

            header('Content-Type:'.$mimeType,true);
            header('Content-Length:'.filesize($mergedFile),true);
            header('Content-Disposition: attachment; filename="'.$outputPath.'"',true);
            if(readfile($mergedFile) === FALSE){
                throw new \Exception("Error in reading the file");
            }
        }

        // remove workingDir and workingFile
        unlink($workingFile);
        $this->deleteDir($this->workingDir);
        if($download === true){
            exit;
        }
    }

    private function mergeFile($file,$data){

        $xmlElement = new \DOMDocument();
        if($xmlElement->load($file) === FALSE){
            throw new \Exception("Error in merging , Template might be corrupted ");
        }

        $this->workingFile = $file;
        $this->parseXMLElement($xmlElement->documentElement,$data);

        if($xmlElement->save($file) === FALSE){
            throw new \Exception("Error in creating output");
        }

    }
    
    private function formatValue($keyValue,$keyOptions){
    	if($keyValue !== false){
    		if(array_key_exists("numberFormat",$keyOptions)){
    			switch(strtolower($keyOptions["numberFormat"])){
    				case "inwords":
    					$noToWords = new \Numbers_Words();
    					$keyValue = $noToWords->toCurrency($keyValue,$this->locale);
    					break;
    				case "currency":
    					if($this->locale == "en_IN"){
    						$keyValue = "".$keyValue;
    						$keyValue = preg_replace("/\,/","",$keyValue);
    	
    						$keyValueSplit = preg_split("/\./",$keyValue);
    						$decimalPart = $keyValueSplit[0];
    						$fractionPart = "00";
    						if(count($keyValueSplit) > 1){
    							$fractionPart = $keyValueSplit[1];
    						}
    	
    						$processedDecimalPart = "";
    						$decimalPart = strrev($decimalPart);
    						$decimalPart = str_split($decimalPart);
    						for($k=0;$k<count($decimalPart);$k++){
    							if($k == 3 || $k == 5 || $k == 7 || $k == 9 || $k == 11 || $k == 13){
    								$processedDecimalPart = ",".$processedDecimalPart;
    							}
    							$processedDecimalPart = $decimalPart[$k].$processedDecimalPart;
    						}
    						if(strlen($fractionPart) == 1){
    							$fractionPart = $fractionPart."0";
    						}
    						$keyValue = $processedDecimalPart.".".$fractionPart;
    	
    					}else{
    						$keyValue = number_format($keyValue,2);
    					}
    			}
    		}
    	}
    	return $keyValue;
    }

    private function parseXMLElement(\DOMElement $xmlElement,$data){

        $tagName = $xmlElement->tagName;
        switch(strtoupper($tagName)){
            case "W:T":
                //find the template keys and replace it with data
                $keys = $this->getTemplateKeys($xmlElement);
                $textContent = "";
                for($i=0;$i<count($keys);$i++){
                    $key = $keys[$i];
                    if($key->isKey() && $key->isComplete()){
                        $keyOptions = $key->options();
                        $keyName = $key->key();

                        if($keyName == "development"){
                            $this->development = true;
                            continue;
                        }

                        if(array_key_exists("repeat",$keyOptions)){
                            $repeatType = "text";
                            if(array_key_exists("repeatType",$keyOptions)){
                                $repeatType = strtolower($keyOptions["repeatType"]);
                            }
                            switch($repeatType){
                            	case "text":
                            		$keyValue = $this->getValue($keyOptions["repeat"],$data);
                            		$keyNameParts = preg_split('/\./', $keyName);
                            		if($keyValue != false && is_array($keyValue) && $keyNameParts !== false){
                            			$repeatingKeyName = $keyNameParts[0];
                            			foreach ($keyValue as $repeatingKeyValue){
                            				$repeatingValue = $this->getValue($keyName,array($repeatingKeyName=>$repeatingKeyValue));
                            				if($repeatingValue != false){
                            					if(!is_string($repeatingValue)){
                            						$repeatingValue = json_encode($repeatingValue);
                            					}
                            					$repeatingValue = $this->formatValue($repeatingValue, $keyOptions);
                            					$textContent = $textContent.$repeatingValue;
                            				}else{
                            					if($this->development){
                            						// in development mode , show the unprocessed keys in output
                            						$textContent = $textContent.$repeatingKeyName;
                            					}else{
                            						// in production mode , don't show the unprocessed keys in output
                            						// no append to $textContent
                            					}
                            				}
                            			}
                            			continue;
                            		}else{
                            			if($this->development){
                            				// in development mode , show the unprocessed keys in output
                            				$textContent = $textContent.$key->originalKey();
                            			}else{
                            				// in production mode , don't show the unprocessed keys in output
                            				// no append to $textContent
                            			}
                            		}
                            		
                            		break;
                            	case "paragraph":
                            			// remove the current key from the w:t textContent
                            			// and add the remaining key's original text unprocessed
                            			for($j=$i+1;$j<count($keys);$j++){
                            				$remainingKey = $keys[$j];
                            				$textContent = $textContent.$remainingKey->originalKey();
                            			}
                            			$this->setTextContent($xmlElement,$textContent);
                            			throw new RepeatParagraphException($keyName,$keyOptions["repeat"]);	
                            		break;
                            	case "row":
                                    // remove the current key from the w:t textContent
                                    // and add the remaining key's original text unprocessed
                                    for($j=$i+1;$j<count($keys);$j++){
                                        $remainingKey = $keys[$j];
                                        $textContent = $textContent.$remainingKey->originalKey();
                                    }
                                    $this->setTextContent($xmlElement,$textContent);
                                    throw new RepeatRowException($keyName,$keyOptions["repeat"]);
                            }
                        }

                        $keyValue = $this->getValue($keyName,$data);

                        if($keyValue !== false){
                            $textContent = $textContent.$this->formatValue($keyValue, $keyOptions);
                        }else{
                            if($this->development){
                                // in development mode , show the unprocessed keys in output
                                $textContent = $textContent.$key->originalKey();
                            }else{
                                // in production mode , don't show the unprocessed keys in output
                                // no append to $textContent
                            }
                        }
                    }else{
                        $textContent = $textContent.$key->key();
                    }
                }

                $this->setTextContent($xmlElement,$textContent);
                break;
            case "W:DRAWING":
                $docPrElement = $xmlElement->getElementsByTagNameNS("http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing","docPr")->item(0);
                if($docPrElement !== null){
                    $altText = $docPrElement->getAttribute("descr");
                    if(strlen($altText)>2 && $this->startsWith($altText,$this->keyStartChar) && $this->endsWith($altText,$this->keyEndChar)){
                        $keyNode = new KeyNode($altText,true,true,$xmlElement);
                        $imagePath = $this->getValue($keyNode->key(),$data);

                        $aBlipElem = $xmlElement->getElementsByTagName("blip")->item(0);
                        if(file_exists($imagePath) && $aBlipElem !== null){
                            $resourceId = $aBlipElem->getAttribute("r:embed");
                            $workingFileName = basename($this->workingFile);
                            $relFile = $this->workingDir.'/word/_rels/'.$workingFileName.'.rels';

                            if(file_exists($relFile)){
                                $relDocument = new \DOMDocument();
                                $relDocument->load($relFile);
                                $relElements = $relDocument->getElementsByTagName("Relationship");

                                $imageExtn = ".png";

                                $files = array_diff(scandir($this->workingDir.'/word/media'),array(".",".."));
                                $templateImageRelPath = 'media/rImage'.count($files).$imageExtn;
                                $templateImagePath = $this->workingDir.'/word/'.$templateImageRelPath;

                                $newResourceId = "rId".($relElements->length+1);
                                $aBlipElem->setAttribute("r:embed",$newResourceId);

                                $newRelElement = $relDocument->createElement("Relationship");
                                $newRelElement->setAttribute("Id",$newResourceId);
                                $newRelElement->setAttribute("Type","http://schemas.openxmlformats.org/officeDocument/2006/relationships/image");
                                $newRelElement->setAttribute("Target",$templateImageRelPath);

                                $relDocument->documentElement->appendChild($newRelElement);

                                $relDocument->save($relFile);
                                copy($imagePath,$templateImagePath);
                            }
                        }else{
                            if($this->development){
                                // in development mode, show the template Image in the Output
                            }else{
                                // in production mode, remove the template Imge from the output
                                $xmlElement = $xmlElement->ownerDocument->createTextNode("");
                            }
                        }
                    }
                }
                break;
            default:
                if($xmlElement->hasChildNodes()){
                    $childNodes = $xmlElement->childNodes;
                    $childNodesArray = array();
                    foreach($childNodes as $childNode){
                        $childNodesArray[] = $childNode;
                    }
                    foreach($childNodesArray as $childNode){
                        if($childNode->nodeType === XML_ELEMENT_NODE){
                            try{
                                $newChild = $this->parseXMLElement($childNode,$data);
                                $xmlElement->replaceChild($newChild,$childNode);
                            }catch (RepeatTextException $te){
                                //not supported yet
                            }catch (RepeatRowException $re){
                                if(strtoupper($xmlElement->tagName) === "W:TBL"){
                                    $repeatingArray = $this->getValue($re->getKey(),$data);
                                    $nextRow = $childNode->nextSibling;
                                    $repeatingRowElement = $xmlElement->removeChild($childNode);
                                    $repeatingKeyName = $re->getName();
                                    if($repeatingArray && is_array($repeatingArray)){
                                        $slNo = 1;
                                        foreach($repeatingArray as $repeatingData){
                                            $repeatedRowElement = $repeatingRowElement->cloneNode(true);
                                            $repeatingData[$this->slNoKey] = $slNo;
                                            $newData = $data;
                                            $newData[$repeatingKeyName] = $repeatingData;
                                            $generatedRow = $this->parseXMLElement($repeatedRowElement,$newData);
                                            $xmlElement->insertBefore($generatedRow,$nextRow);
                                            $slNo++;
                                        }
                                    }
                                }else{
                                    throw $re;
                                }
                            }catch (RepeatParagraphException $pe){
                                if(strtoupper($childNode->tagName) === "W:P"){
                                    $repeatingArray = $this->getValue($pe->getKey(),$data);
                                    $nextParagraph = $childNode->nextSibling;
                                    $repeatingParagraphElement = $xmlElement->removeChild($childNode);
                                    $repeatingKeyName = $pe->getName();
                                    if($repeatingArray && is_array($repeatingArray)){
                                        $slNo = 1;
                                        foreach($repeatingArray as $repeatingData){
                                            $repeatedParagraphElement = $repeatingParagraphElement->cloneNode(true);
                                            $repeatingData[$this->slNoKey] = $slNo;
                                            $newData = $data;
                                            $newData[$repeatingKeyName] = $repeatingData;
                                            $generatedRow = $this->parseXMLElement($repeatedParagraphElement,$newData);
                                            $xmlElement->insertBefore($generatedRow,$nextParagraph);
                                            $slNo++;
                                        }
                                    }
                                }else{
                                    throw $pe;
                                }
                            }
                        }
                    }
                }
        }

        return $xmlElement;
    }

    /**
     * @param DOMElement $wtElement <w:t> element in the document xml,
     * this method should be called sequentially for all the <w:t> elements in the order they appear in the document xml
     *
     */
    private function getTemplateKeys(\DOMElement $wtElement){
        if(strtoupper($wtElement->tagName) != "W:T"){
            $this->log(LOG_ALERT,"Invalid element for finding template keys : Line ".$wtElement->getLineNo());
            return false;
        }
        $keys = array();
        $textContent = $wtElement->textContent;
        $incompleteText = '';
        if(count($this->incompleteKeyNodes) > 0){
            // incomplete keys are from different <p> elements , then discard the old incomplete elements
            $firstIncompleteKey = $this->incompleteKeyNodes[0];
            if($firstIncompleteKey->element()->parentNode->parentNode !== $wtElement->parentNode->parentNode){
                $this->log(LOG_WARNING,"incomplete keys in paragraph : Line ".$firstIncompleteKey->element()->parentNode->parentNode->getLineNo());
                $this->incompleteKeyNodes = array();
            }

            foreach($this->incompleteKeyNodes as $incompleteKeyNode){
                //$incompleteKeyNode will be an instance of KeyNode class
                $incompleteText .= $incompleteKeyNode->key();
            }
        }
        $textContent  = $incompleteText.$textContent;

        $textChars = str_split($textContent);
        $key = null;
        $nonKey = "";
        for($i=0;$i<count($textChars);$i++){
            if($textChars[$i] === $this->keyStartChar || $textChars[$i] === $this->keyEndChar){
                // found keyStartChar/keyEndChar check the \ character behind the keyStartChar/keyEndChar
                $j = $i-1;
                for(; $j>= 0;$j--){
                    if($textChars[$j] != "\\"){
                        break;
                    }
                }
                if(($i-$j)%2){
                    // if i-j is odd ,
                    // then there are even numbers of \ chars behind found keyStartChar/keyEndChar
                    // so keyStartChar/keyEndChar is not escaped and hence valid
                    if($textChars[$i] === $this->keyStartChar){
                        //found keyStartChar
                        if($nonKey !== ""){
                            $keyNode = new KeyNode($nonKey,false,true,$wtElement);
                            $keys[] = $keyNode;
                        }
                        if($key != null){
                            $keyNode = new KeyNode($key,false,true,$wtElement);
                            $keys[] = $keyNode;
                        }
                        $key = $textChars[$i];
                        $nonKey = "";
                    }else{
                        //found keyEndChar
                        if($key !== null){
                            $key = $key.$textChars[$i];
                            $keyNode = new KeyNode($key,true,true,$wtElement);
                            $keys[] = $keyNode;
                            $key = null;
                            $nonKey = "";
                        }else{
                            $nonKey = $nonKey.$textChars[$i];
                        }
                    }
                    continue;
                }

            }
            //neither keyStartChar nor keyEndChar
            if($key !== null){
                // if a key is started, append to it
                $key = $key.$textChars[$i];
            }else{
                $nonKey = $nonKey.$textChars[$i];
            }
        }

        if($key !== null){
            $openKey = new KeyNode($key,true,false,$wtElement);
        }
        if($nonKey !== ""){
            $openText = new KeyNode($nonKey,false,true,$wtElement);
        }

        $incompleteKeys = false;
        if(count($this->incompleteKeyNodes) > 0){
            $incompleteKeys = true;
        }
        if($incompleteKeys && (!isset($openKey) || (isset($openKey) && count($keys) > 0))){
            // if there were incomplete keys and found one or more complete keys in current textContent
            // copy the incomplete keys content to current w:t element
            for($i = count($this->incompleteKeyNodes)-1;$i>=0;$i--){
                $incompleteKeyNode = $this->incompleteKeyNodes[$i];
                $incompleteKeyElement = $incompleteKeyNode->element();
                $incompleteKey = $incompleteKeyNode->key();

                //delete content from the incompleteKeyElement
                $incompleteKeyElementContent = $incompleteKeyElement->textContent;
                $incompleteKeyElementContent = substr($incompleteKeyElementContent,0,strlen($incompleteKeyElementContent)-strlen($incompleteKey));
                if($this->endsWith($incompleteKeyElementContent," ")){
                    $incompleteKeyElement->setAttribute("xml:space","preserve");
                }
                $this->setTextContent($incompleteKeyElement,$incompleteKeyElementContent);

                //add incomplete key to this wtElement
                $thisTextContent = $wtElement->textContent;
                $this->setTextContent($wtElement,$incompleteKey.$thisTextContent);
            }
            $this->incompleteKeyNodes = array();
        }

        if(isset($openKey) && (!$incompleteKeys || ($incompleteKeys && count($keys) > 0))){
            $this->incompleteKeyNodes[] = $openKey;
            $keys[] = $openKey;
        }

        if(isset($openKey) && $incompleteKeys && count($keys) == 0){
            $thisTextAsKeyNode = new KeyNode($wtElement->textContent,true,false,$wtElement);
            $this->incompleteKeyNodes[] = $thisTextAsKeyNode;
            $keys[] = $thisTextAsKeyNode;
        }

        if(isset($openText)){
            $keys[]= $openText;
        }
        return $keys;
    }

    private function log($level,$message){
        if(isset($this->logFile)){
            error_log($message,3,$this->logFile);
        }else{
            error_log($message);
        }
    }

    private function startsWith($haystack, $needle) {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
    }
    private function endsWith($haystack, $needle) {
        // search forward starting from end minus needle length characters
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== FALSE);
    }

    private function getValue($key,$data){
        $keyParts = preg_split('/\./',$key);
        $keyValue = $data;
        foreach($keyParts as $keyPart){
            $keyPart = trim($keyPart);
            if(is_array($keyValue) && array_key_exists($keyPart,$keyValue)){
                $keyValue = $keyValue[$keyPart];
            }else{
                $keyValue = false;
                break;
            }
        }
        return $keyValue;
    }

    private function setTextContent(\DOMNode $node,$value){
        $node->nodeValue = "";
        return $node->appendChild($node->ownerDocument->createTextNode($value));
    }

    static public function deleteDir($dirPath){
        if(is_dir($dirPath)){
            $files = array_diff(scandir($dirPath), array('..', '.'));
            foreach($files as $file){
                self::deleteDir($dirPath.'/'.$file);
            }
            rmdir($dirPath);
        }else{
            unlink($dirPath);
        }
    }
}

