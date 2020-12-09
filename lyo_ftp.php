<?php
namespace Lyo;
require "lyo_funcs_general.php";
use function \Lyo\Funcs\General\{extractFilepath, extractFilename, isValidDir, isStrEndsWith};



//todo: use ob
//https://www.php.net/manual/en/book.outcontrol.php
//https://stackoverflow.com/questions/927341/upload-entire-directory-via-php-ftp
//flush buffers

//make custom exception class and add object insode class
//handle symbolic links in downloadDir()
// do not show message on ftp_mkdir in uploadDir
// todo handle Exception in isFileExist
// todo close() func, sometimes can be needed to close manually to proceed to other ftp connection


class FtpHandler
{
    private $hConnection = null;
    private $blackList = array('.', '..', 'Thumbs.db');


    public function __construct($ftpHost = "", $ftpUser = "", $ftpPass = "") 
    {
        if ($ftpHost != "") 
        {
            $this->hConnection = \ftp_connect($ftpHost);
            if (isset($this->hConnection)) 
            {
                if (\ftp_login($this->hConnection, $ftpUser, $ftpPass)) 
                {
                    //nlist, rawlist, get, put do not work in active mode
                    \ftp_pasv($this->hConnection, true);
                } 
                else 
                {
                    \ftp_close($this->hConnection);
                    unset($this->hConnection);
                    //todo: handle Exception properly
                    echo("Error: ftp: $ftpUser: Login name or password is incorrect!");
                }
            }
            else
            {
                //todo: handle Exception properly
                echo ("Error: ftp: $ftpHost: Connection has failed!");
            }
        }
    }


    public function __destruct() 
    {
        if (isset($this->hConnection)) 
        {
            \ftp_close($this->hConnection);
            unset($this->hConnection);
        }
    }


    /**
     * for folders and files, except root folder '/'
     * @param string $fullPath 
     * @return bool
     */
    public function isFileExist($fullPath)
    {
        
        if($this->checkConnection())
        {
            
            $fullPath = \rtrim($fullPath, "/\\");
            $parentPath = extractFilepath($fullPath);

            //todo: windows
            if(isset($parentPath) && \strlen($parentPath) == 0) { $parentPath = '/'; }
            $fileName = extractFilename($fullPath);

            if(isValidDir($parentPath))
            {
                

                // todo handle Exception here
                if (\ftp_chdir($this->hConnection, $parentPath))
                {
                    

                    $files = \ftp_nlist($this->hConnection, "-a .");
                    
                    echo $fileName, PHP_EOL;
                    var_dump($files);

                    foreach($files as $file)
                    {
                        if($file === $fileName)
                        {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }


    /**
     * @param string $localPath 
     * @param string $ftpPath
     * @return string $error
     */
    public function putFile($localPath, $ftpPath) 
    {
        $error = "";
        try 
        {
            \ftp_put($this->hConnection, $ftpPath, $localPath, FTP_BINARY); 
        } 
        catch (\Exception $e) 
        {
            if ($e->getCode() == 2) $error = $e->getMessage(); 
        }
        return $error;
    }


    /**
     * @param string $ftpDir
     * @return string $error
     */
    public function makeDir($ftpDir) 
    {
        $error = "";
        try 
        {
            \ftp_mkdir($this->hConnection, $ftpDir);
        } 
        catch (\Exception $e) 
        {
            if ($e->getCode() == 2) $error = $e->getMessage(); 
        }
        return $error;
    }


    /**
     * upload content of localDir into ftpDir
     * @param string $localDir 
     * @param string $ftpDir
     * @return array $errorList
     */
    public function uploadDir($localDir, $ftpDir)
    {
        if($this->isFileExist($ftpDir)) // check parent folder existence
        {
            // local dir copied into ftp dir
            $this->uploadDirAndFiles($localDir, $ftpDir);
            return true;
        }
        return false;
    }


    /**
     * download content of ftpDir into localDir
     * @param string $localDir 
     * @param string $ftpDir
     * @return bool 
     */
    public function downloadDir($localDir, $ftpDir)
    {
        if($this->isFileExist($ftpDir)) // check parent folder existence
        {
            $this->downloadDirAndFiles($localDir, $ftpDir);
            return true;
        }
        return false;
    }


    /**
     * remove ftpDir and it's content
     * @param string $ftpDir
     * @return bool 
     */
    public function removeDir($ftpDir)
    {
        if($ftpDir === '\\' || $ftpDir === '/') 
        { 
            throw new \Exception("Cannot delete root directory");
            return false; 
        }

        if($this->isFileExist($ftpDir))
        {
            $this->deleteDirAndFiles($ftpDir);
            return true;
        }
        return false;
    }

    //////////////////////////////////////////////////////////////////////////////////////////////////////

    private function checkConnection()
    {
        if (isset($this->hConnection)) 
        {
            return true;
        }

        throw new \Exception("Error: ftp: Connection has failed!");
        return false;
    }


    private function isDirChanged($fullPath)
    {
        $fullPath = \rtrim($fullPath, "/\\");
        if(isset($fullPath) && \strlen($fullPath) == 0) 
        { 
            //todo: windows
            $fullPath = '/'; 
        }

        if(isValidDir($fullPath))
        {
            // todo: handle Exception here
            if (\ftp_chdir($this->hConnection, $fullPath))
            {
                if ($fullPath === \rtrim(\ftp_pwd($this->hConnection), "/\\")) 
                {
                    return true;
                }
            }
        }
        return false;
    }


    private function uploadDirAndFiles($localDir, $ftpDir)
    {
        if(isValidDir($localDir) && isValidDir($ftpDir))
        {   
            $errorList = array();
            if (!\is_dir($localDir)) 
            {
                throw new \Exception("Invalid directory: $localDir");
            }
            \chdir($localDir);
            $hDir = \opendir(".");
            while ($file = \readdir($hDir)) 
            {
                if (!\in_array($file, $this->blackList))
                {
                    //workaround for symlinks
                    if(\is_link($file))
                    {
                        $linkPath = \readlink($file);
                        if(isset($linkPath) && !empty($linkPath))
                        {
                            $symlinkName = $file.'.sym.link';
                            $hSymLink = \fopen($symlinkName, "w");
                            if($hSymLink)
                            {
                                \fwrite($hSymLink, $linkPath);
                                \fflush($hSymLink);
                                \fclose($hSymLink);
                                \chmod($symlinkName, 0777);
                                $errorList["$ftpDir/$file"] = $this->putFile("$localDir/$symlinkName", "$ftpDir/$symlinkName");
                                \unlink($symlinkName);
                            }
                        }
                    }
                    else if (\is_dir($file)) 
                    {
                        $errorList["$ftpDir/$file"] = $this->makeDir("$ftpDir/$file");
                        $errorList[] = $this->uploadDirAndFiles("$localDir/$file", "$ftpDir/$file");
                        \chdir($localDir);
                    } 
                    else
                    {
                        $errorList["$ftpDir/$file"] = $this->putFile("$localDir/$file", "$ftpDir/$file");
                    }
                        

                }
            }
            if (isset($hDir)) 
            {
                \closedir($hDir);
            }
            return $errorList;
        }
    }


    private function downloadDirAndFiles($localDir, $ftpDir)
    {
        echo $ftpDir;
        if(isValidDir($localDir) && isValidDir($ftpDir))
        {   
            
            if($this->isDirChanged($ftpDir)) 
            {
                $curDirRestore = \ftp_pwd($this->hConnection); // store here, otherwise error, because current dir changes via ftp_chdir

                //-a is not supported by all ftp apps
                $rawPaths = \ftp_rawlist($this->hConnection, "-la .");
                $filePaths = \ftp_nlist($this->hConnection, "-a .");
            
                if(\count($rawPaths) == \count($filePaths))
                {
                    for($i = 0; $i < \count($rawPaths); ++$i)
                    {
                        if(isStrEndsWith($rawPaths[$i], $filePaths[$i]))
                        {
                            if(isValidDir($filePaths[$i]))
                            {
                                $innerLocalPath = $localDir.DIRECTORY_SEPARATOR.$filePaths[$i];
                                $innerFtpPath = $curDirRestore.DIRECTORY_SEPARATOR.$filePaths[$i];
                                if($rawPaths[$i][0] === 'd')
                                {
                                    
                                    \mkdir($innerLocalPath);
                                    $this->downloadDirAndFiles($innerLocalPath, $innerFtpPath);
                                }
                                else
                                {
                                    //handle exception
                                    ftp_get($this->hConnection, $innerLocalPath, $innerFtpPath, FTP_BINARY);
                                }
                            }
                        }
                    }
                }
            } 
            else 
            { 
                //todo exception
                echo "Couldn't change directory {$ftpDir}\n";
                return;
            }
        }
    }


    private function deleteDirAndFiles($ftpDir)
    {
        if(isValidDir($ftpDir))
        {   
            if (\ftp_chdir($this->hConnection, $ftpDir)) 
            {
                $curDirRestore = \ftp_pwd($this->hConnection); // store here, otherwise error, because current dir changes via ftp_chdir

                //-A is not supported by all ftp apps
                $rawPaths = \ftp_rawlist($this->hConnection, "-la .");
                $filePaths = \ftp_nlist($this->hConnection, "-a .");
                

                var_dump($rawPaths);
                echo PHP_EOL;
                var_dump($filePaths);

                if(\count($rawPaths) == \count($filePaths))
                {
                    for($i = 0; $i < \count($rawPaths); ++$i)
                    {
                        if(isStrEndsWith($rawPaths[$i], $filePaths[$i]))
                        {
                            if(isValidDir($filePaths[$i]))
                            {
                                if($rawPaths[$i][0] === 'd')
                                {
                                    $this->deleteDirAndFiles($curDirRestore.DIRECTORY_SEPARATOR.$filePaths[$i]);
                                }
                                else
                                {
                                    //$isfilechmo = ftp_chmod($this->hConnection, 0777, $filePaths[$i]); // doesn't work? check again with pasv=true
                                    if(!\ftp_delete($this->hConnection, $curDirRestore.DIRECTORY_SEPARATOR.$filePaths[$i]))
                                    {
                                        echo "failed to delete file ".$filePaths[$i].PHP_EOL;
                                    }
                                }
                            }
                        }
                    }

                    if(!\ftp_rmdir($this->hConnection , $ftpDir))
                    {
                        echo "failed to delete folder ".$ftpDir.PHP_EOL;
                    }
                }
            } 
            else 
            { 
                echo "Couldn't change directory {$ftpDir}\n";
                return;
            }
        }
    }


};


?> 