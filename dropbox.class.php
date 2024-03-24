<?php
/*
    Dropbox API

    get access_token
    get file_list
    get file_url
    file upload
    file remove
-------------------------------------------------------------------------------*/
class Dropbox {
    // constant definition
    const APP_KEY = 'YOUR_APP_KEY';
    const APP_SECRET = 'YOUR_APP_SECRET';
    const REFRESH_TOKEN = 'YOUR_REFRESH_TOKEN';
    const FILE_CHIP_SIZE = 150000000;

    private $access_token;
    private $session_id;    // upload_session_id
    private $tmpdir;

    // constructor
    public function __construct() {
        // Get temporary access token
        $this->access_token = $this->getAccessToken();
    }

    // get access_token
    private function getAccessToken() {
        $url = 'https://api.dropboxapi.com/oauth2/token';
        $data = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => self::REFRESH_TOKEN,
            'client_id'     => self::APP_KEY,
            'client_secret' => self::APP_SECRET
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $access_token = '';
        // if (!curl_errno($ch) && $http_code == "200") {}
        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
            $access_token = $res['access_token'];
        }

        curl_close($ch);
        return $access_token;
    }

    // get file list
    public function getFileList($dirname) {
        $url = "https://api.dropboxapi.com/2/files/list_folder";
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/json",
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                "path" => $dirname,
                "recursive" => true,
            ]),
            CURLOPT_RETURNTRANSFER => true,
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $files = [];
        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
            if ($res["entries"]) {
                foreach ($res["entries"] as $index => $content) {
                    if ($content[".tag"] == "file") {
                        $files[] = $content;
                    }
                }
            }
            if ($res["has_more"]) {
                $morefiles = $this->getFileListRecursive($res["cursor"]);
                $files = array_merge($files, $morefiles);
            }
        }

        curl_close($ch);
        return $files;
    }

    // get file list (sub)
    public function getFileListRecursive($cursor) {
        $url = "https://api.dropboxapi.com/2/files/list_folder/continue";
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/json",
        ];
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(["cursor" =>"{$cursor}"]),
            CURLOPT_RETURNTRANSFER => true,
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $files = [];

        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
            if ($res["entries"]) {
                foreach ($res["entries"] as $content) {
                    if ($content[".tag"] == "file") {
                        $files[] = $content;
                    }
                }
            }
            if ($res["has_more"]) {
                $morefiles = $this->getFileListRecursive($res["cursor"]);
                $files = array_merge($files, $morefiles);
            }
        }

        curl_close($ch);
        return $files;
    }

    // get file url
    public function getFileURL($local_filepath) {
        $url = "https://api.dropboxapi.com/2/files/get_temporary_link";
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/json",
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                "path" => $local_filepath,
            ]),
            CURLOPT_RETURNTRANSFER => true,
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        $file_url = '';
        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
            $file_uri = $res['link'];
        }

        curl_close($ch);
        return $file_uri;
    }

    // file upload < 150MB
    public function uploadFile($local_filepath, $upload_filepath) {
        if (filesize($local_filepath) > self::FILE_CHIP_SIZE) {
            $ret = $this->uploadFile_session($local_filepath, $upload_filepath);
            return $ret;
        }

        $url = "https://content.dropboxapi.com/2/files/upload";
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/octet-stream",
            "Dropbox-API-Arg: " . json_encode([
                "path" => $upload_filepath,
                "mode" => "add",
                "autorename" => true,
            ]),
        ];
        $file = fopen($local_filepath, 'rb');
        $size = filesize($local_filepath);

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $file,
            CURLOPT_INFILESIZE => $size,
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        $path_lower = '';
        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
            $path_lower = $res['path_lower'];
        }

        fclose($file);
        curl_close($ch);

        return $path_lower;
    }


    // file upload > 150MB
    private function uploadFile_session($local_filepath, $upload_filepath) {
        // split local file
        list($array_chunk, $filesize) = $this->file_split($local_filepath);

        // get session id
        $this->session_id = $this->get_session_id();

        // upload chunk
        $offset = 0;
        foreach ($array_chunk as $chunk){
            $offset = $this->upload_chunk($chunk, $offset);
        }

        // finish
        $ret = $this->finish_upload($upload_filepath, $filesize);

        // remove tmpdir
        $this->rrmdir('./'.$local_filepath.'.d');

        return $ret;
	}


    private function file_split($filepath){
        $array_chunk = [];

        if (filesize($filepath) <= self::FILE_CHIP_SIZE){
            $array_chunk[] = $filepath;
            return $array_chunk;
        }

        $tmpdir = './'.$filepath.'.d';
        if (!file_exists($tmpdir)){ mkdir($tmpdir); }
        if (!is_writable($tmpdir)){ chmod($tmpdir, 0775); }

        $i = 0;
        $fp = fopen($filepath, 'r');
        while (!feof($fp)){
            $n = sprintf('%04d', $i);
            file_put_contents($tmpdir.'/'.$filepath.'_'.$n, fread($fp, self::FILE_CHIP_SIZE));
            $array_chunk[] = $tmpdir.'/'.$filepath.'_'.$n;
            $i++;
        }
        fclose($fp);
        return [$array_chunk, filesize($filepath)];
    }


    // get upload session ID
    private function get_session_id(){
        $url = "https://content.dropboxapi.com/2/files/upload_session/start";
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/octet-stream",
            "Dropbox-API-Arg: " . json_encode([
                    "close" => false,
            ]),
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        $session_id = '';
        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
            $session_id = $res['session_id'];
        }

        curl_close($ch);
        return $session_id;
    }

    // upload chunk
    private function upload_chunk($chunk, $offset){
        $url = "https://content.dropboxapi.com/2/files/upload_session/append_v2";
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/octet-stream",
            "Dropbox-API-Arg: " . json_encode([
                "close" => false,
                "cursor" => [
                    "offset" => $offset,
                    "session_id" => $this->session_id,
                ],
            ]),
        ];

        $chunk_file = fopen($chunk, 'r');
        $chunk_size = filesize($chunk);

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $chunk_file,
            CURLOPT_INFILESIZE => $chunk_size,
        ];
        curl_setopt_array($ch, $options);
        $res = curl_exec($ch);

        if ($this->get_curlError($ch, $url) === false){ $res = json_decode($res, true); }

        fclose($chunk_file);
        curl_close($ch);

        $offset += $chunk_size;
        return $offset;
    }

    private function finish_upload($filepath, $offset){
        $url = "https://content.dropboxapi.com/2/files/upload_session/finish";
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/octet-stream",
            "Dropbox-API-Arg: " . json_encode([
                "commit" => [
                    "autorename" => true,
                    "mode" => "add",
                    "mute" => false,
                    "path" => $filepath,
                    "strict_conflict" => false,
                ],
                "cursor" => [
                    "offset" => $offset,
                    "session_id" => $this->session_id,
                ],
            ]),
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
        }
        curl_close($ch);

        return $res;
    }

    // file remove from dropbox
    public function remove($filepath) {
        $url = 'https://api.dropboxapi.com/2/files/delete_v2';
        $headers = [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/json",
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            // CURLOPT_POSTFIELDS => http_build_query([
            CURLOPT_POSTFIELDS => json_encode([
                'path' => $filepath,
            ]),
        ];
        curl_setopt_array($ch, $options);

        $res = curl_exec($ch);
        if ($this->get_curlError($ch, $url) === false){
            $res = json_decode($res, true);
        }
        curl_close($ch);

        return $res;
    }


    private function get_curlError($ch, $url) {
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ret = false;
        if (curl_errno($ch)) {
            $errno = curl_errno($ch);
            print <<<_END_
ERROR: Failed to access Dropbox API: {$err_no}<br />
URL: {$url}<br />
HTTP Code: {$http_code}<br />
_END_;
            $ret = true;
        }

        return $ret;
    }

    // removes files and non-empty directories
    private function rrmdir($dir) {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file)
                if ($file != "." && $file != "..") $this->rrmdir("$dir/$file");
                rmdir($dir);
        }
        else if (file_exists($dir)) unlink($dir);
    }

    private function xprint($var){
        if (is_array($var) || is_object($var)){
            print "<pre>"; print_r($var); print "</pre>\n";
        }
        else {
            print "<pre>"; print($var); print "</pre>\n";
        }
        return;
    }


}
