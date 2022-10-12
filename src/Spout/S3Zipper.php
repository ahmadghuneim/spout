<?php

namespace Box\Spout;

use Illuminate\Support\Facades\Log;

class S3Zipper
{
    private $token;
    private $folderPath;
    private $zippedFile;
    private $streams3v2;

    public function __construct($folderPath, $zippedFile)
    {

        $this->folderPath = $folderPath;
        $this->zippedFile = $zippedFile;
    }

    public function start()
    {
        $valid = $this->generateToken();
        if (!$valid) {
            return false;
        }
        $valid = $this->startZip();
        if (!$valid) {
            return false;
        }
        return $this->getResult();
    }

    private function generateToken()
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.s3zipper.com/gentoken",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => null,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => array('userKey' => env('ZIPPER_USER_KEY'), 'userSecret' => env('ZIPPER_USER_SECRET')),
        ));


        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            Log::alert('Zipper Error on generate token' . $err);
        } else {

            $result = json_decode($response);
            if (empty($result->token)) {
                Log::alert('error : ' . $response);
                return false;
            }
            $this->token = $result->token;
        }
        return true;
    }

    private function startZip()
    {
        $url = 'https://api.s3zipper.com/v2/zipstart';
        $filePaths = [
            env('AWS_BUCKET_REPORTS') . $this->folderPath
        ];

        $bucketDir = explode('/', $this->folderPath);
        $bucketDir = end($bucketDir);

        $fields = array(
            'awsKey' => env('AWS_ACCESS_KEY_ID_REPORTS'),
            'awsSecret' => env('AWS_SECRET_ACCESS_KEY_REPORTS'),
            'awsRegion' => env('AWS_DEFAULT_REGION_REPORTS'),
            'awsBucket' => env('AWS_BUCKET_REPORTS'),
            'expireLink' => 24,
            'filePaths' => $filePaths,
            'zipTo' => $this->zippedFile,
            'zipFileName' => $this->zippedFile . '.zip',
            'bucketAsDir' => $bucketDir,
        );


        $payload = json_encode($fields, JSON_UNESCAPED_SLASHES);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_HTTPHEADER => array(
                'Content-Type:application/json; charset=utf-8',
                "Authorization: Bearer " . $this->token
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
        ));


        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            Log::alert('Zipper Error on start zipper' . $err);
        } else {

            $result = json_decode($response);
            if (empty($result->taskUUID)) {
                Log::alert('error : ' . $response);
                return false;
            }
            $this->streams3v2 = $result->taskUUID[0]->streams3v2;
        }
        return true;
    }

    private function getResult()
    {
        $curl = curl_init();

        $result = '{
 "message": "STARTED",
 "size": "9.1 kB",
 "chainTaskUUID": [
  {
   "idurl": "' . $this->streams3v2 . '"
  }
 ]
}';

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.s3zipper.com/v2/zipresult",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $result,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $this->token,
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            Log::alert('Zipper Error on get result' . $err);
        } else {
            $result = json_decode($response);

            if (empty($result->results)) {
                Log::alert('error : ' . $response);
                return false;
            }
        }
        return true;
    }
}
