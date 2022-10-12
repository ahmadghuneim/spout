<?php

namespace Box\Spout\Writer\Common\Helper;

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Box\Spout\Writer\Common\Creator\InternalEntityFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Class ZipHelper
 * This class provides helper functions to create zip files
 */
class ZipHelper
{
    public const ZIP_EXTENSION = '.zip';

    /** Controls what to do when trying to add an existing file */
    public const EXISTING_FILES_SKIP = 'skip';
    public const EXISTING_FILES_OVERWRITE = 'overwrite';

    /** @var InternalEntityFactory Factory to create entities */
    private $entityFactory;

    /**
     * @param InternalEntityFactory $entityFactory Factory to create entities
     */
    public function __construct($entityFactory)
    {
        $this->entityFactory = $entityFactory;
    }

    /**
     * Returns a new ZipArchive instance pointing at the given path.
     *
     * @param string $tmpFolderPath Path of the temp folder where the zip file will be created
     * @return \ZipArchive
     */
    public function createZip($tmpFolderPath)
    {
        $zip = $this->entityFactory->createZipArchive();
        $zipFilePath = $tmpFolderPath . self::ZIP_EXTENSION;

        $zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        return $zip;
    }

    /**
     * @param \ZipArchive $zip An opened zip archive object
     * @return string Path where the zip file of the given folder will be created
     */
    public function getZipFilePath(\ZipArchive $zip)
    {
        return $zip->filename;
    }

    /**
     * Adds the given file, located under the given root folder to the archive.
     * The file will be compressed.
     *
     * Example of use:
     *   addFileToArchive($zip, '/tmp/xlsx/foo', 'bar/baz.xml');
     *   => will add the file located at '/tmp/xlsx/foo/bar/baz.xml' in the archive, but only as 'bar/baz.xml'
     *
     * @param \ZipArchive $zip An opened zip archive object
     * @param string $rootFolderPath Path of the root folder that will be ignored in the archive tree.
     * @param string $localFilePath Path of the file to be added, under the root folder
     * @param string $existingFileMode Controls what to do when trying to add an existing file
     * @return void
     */
    public function addFileToArchive($zip, $rootFolderPath, $localFilePath, $existingFileMode = self::EXISTING_FILES_OVERWRITE)
    {
        $this->addFileToArchiveWithCompressionMethod(
            $zip,
            $rootFolderPath,
            $localFilePath,
            $existingFileMode,
            \ZipArchive::CM_DEFAULT
        );
    }

    public function addFileToCloudArchive($zip, $rootFolderPath, $localFilePath, $disk, $existingFileMode = self::EXISTING_FILES_OVERWRITE)
    {

        $this->addFileToCloudArchiveWithCompressionMethod(
            $zip,
            $rootFolderPath,
            $localFilePath,
            $existingFileMode,
            \ZipArchive::CM_DEFAULT,
            $disk
        );
    }

    /**
     * Adds the given file, located under the given root folder to the archive.
     * The file will NOT be compressed.
     *
     * Example of use:
     *   addUncompressedFileToArchive($zip, '/tmp/xlsx/foo', 'bar/baz.xml');
     *   => will add the file located at '/tmp/xlsx/foo/bar/baz.xml' in the archive, but only as 'bar/baz.xml'
     *
     * @param \ZipArchive $zip An opened zip archive object
     * @param string $rootFolderPath Path of the root folder that will be ignored in the archive tree.
     * @param string $localFilePath Path of the file to be added, under the root folder
     * @param string $existingFileMode Controls what to do when trying to add an existing file
     * @return void
     */
    public function addUncompressedFileToArchive($zip, $rootFolderPath, $localFilePath, $existingFileMode = self::EXISTING_FILES_OVERWRITE)
    {
        $this->addFileToArchiveWithCompressionMethod(
            $zip,
            $rootFolderPath,
            $localFilePath,
            $existingFileMode,
            \ZipArchive::CM_STORE
        );
    }

    /**
     * Adds the given file, located under the given root folder to the archive.
     * The file will NOT be compressed.
     *
     * Example of use:
     *   addUncompressedFileToArchive($zip, '/tmp/xlsx/foo', 'bar/baz.xml');
     *   => will add the file located at '/tmp/xlsx/foo/bar/baz.xml' in the archive, but only as 'bar/baz.xml'
     *
     * @param \ZipArchive $zip An opened zip archive object
     * @param string $rootFolderPath Path of the root folder that will be ignored in the archive tree.
     * @param string $localFilePath Path of the file to be added, under the root folder
     * @param string $existingFileMode Controls what to do when trying to add an existing file
     * @param int $compressionMethod The compression method
     * @return void
     */
    protected function addFileToArchiveWithCompressionMethod($zip, $rootFolderPath, $localFilePath, $existingFileMode, $compressionMethod)
    {
        if (!$this->shouldSkipFile($zip, $localFilePath, $existingFileMode)) {
            $normalizedFullFilePath = $this->getNormalizedRealPath($rootFolderPath . '/' . $localFilePath);
            $zip->addFile($normalizedFullFilePath, $localFilePath);

            if (self::canChooseCompressionMethod()) {
                $zip->setCompressionName($localFilePath, $compressionMethod);
            }
        }
    }

    protected function addFileToCloudArchiveWithCompressionMethod($zip, $rootFolderPath, $localFilePath, $existingFileMode,
                                                                  $compressionMethod, $disk)
    {
        /*if (!$this->shouldSkipFile($zip, $localFilePath, $existingFileMode)) {


            $zip->addFromString($localFilePath, Storage::disk($disk)->get($rootFolderPath . '/' . $localFilePath));

            if (self::canChooseCompressionMethod()) {
                $zip->setCompressionName($localFilePath, $compressionMethod);
            }
        }*/
    }

    /**
     * @return bool Whether it is possible to choose the desired compression method to be used
     */
    public static function canChooseCompressionMethod()
    {
        // setCompressionName() is a PHP7+ method...
        return (\method_exists(new \ZipArchive(), 'setCompressionName'));
    }

    /**
     * @param \ZipArchive $zip An opened zip archive object
     * @param string $folderPath Path to the folder to be zipped
     * @param string $existingFileMode Controls what to do when trying to add an existing file
     * @return void
     */
    public function addFolderToArchive($zip, $folderPath, $existingFileMode = self::EXISTING_FILES_OVERWRITE)
    {

        $folderRealPath = $this->getNormalizedRealPath($folderPath) . '/';

        $itemIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($itemIterator as $itemInfo) {
            $itemRealPath = $this->getNormalizedRealPath($itemInfo->getPathname());
            $itemLocalPath = \str_replace($folderRealPath, '', $itemRealPath);

            if ($itemInfo->isFile() && !$this->shouldSkipFile($zip, $itemLocalPath, $existingFileMode)) {
                $zip->addFile($itemRealPath, $itemLocalPath);

            }

        }
    }

    public function addFolderToCloudArchive($zip, $folderPath, $existingFileMode = self::EXISTING_FILES_OVERWRITE, $disk)
    {
        foreach (Storage::disk($disk)->allFiles($folderPath) as $itemInfo) {

            $path = '';
            $itemInfo = ltrim($itemInfo, '/');
            $itemRealPath = $itemInfo;

            $itemLocalPath = str_replace(ltrim($folderPath, '/'), '', $itemRealPath);

            $zip->addFromString(ltrim($itemLocalPath, '/'), Storage::disk($disk)->get($itemRealPath));
        }
    }

    /**
     * @param \ZipArchive $zip
     * @param string $itemLocalPath
     * @param string $existingFileMode
     * @return bool Whether the file should be added to the archive or skipped
     */
    protected function shouldSkipFile($zip, $itemLocalPath, $existingFileMode)
    {
        // Skip files if:
        //   - EXISTING_FILES_SKIP mode chosen
        //   - File already exists in the archive
        return ($existingFileMode === self::EXISTING_FILES_SKIP && $zip->locateName($itemLocalPath) !== false);
    }

    /**
     * Returns canonicalized absolute pathname, containing only forward slashes.
     *
     * @param string $path Path to normalize
     * @return string Normalized and canonicalized path
     */
    protected function getNormalizedRealPath($path)
    {
        $realPath = \realpath($path);

        return \str_replace(DIRECTORY_SEPARATOR, '/', $realPath);
    }

    /**
     * Closes the archive and copies it into the given stream
     *
     * @param \ZipArchive $zip An opened zip archive object
     * @param resource $streamPointer Pointer to the stream to copy the zip
     * @return void
     */
    public function closeArchiveAndCopyToStream($zip, $streamPointer)
    {
        $zipFilePath = $zip->filename;
        $zip->close();

        $this->copyZipToStream($zipFilePath, $streamPointer);
    }

    public function closeCloudArchiveAndCopyToStream($finalFilePointer, $zip, $disk)
    {
        $zipFilePath = $zip->filename;
        $zip->close();

        $this->copyCloudZipToStream($finalFilePointer, $zipFilePath, $disk);
    }

    /**
     * Streams the contents of the zip file into the given stream
     *
     * @param string $zipFilePath Path of the zip file
     * @param resource $pointer Pointer to the stream to copy the zip
     * @return void
     */
    protected function copyZipToStream($zipFilePath, $pointer)
    {
        $zipFilePointer = fopen($zipFilePath, 'r');
        \stream_copy_to_stream($zipFilePointer, $pointer);
        \fclose($zipFilePointer);
    }

    protected function copyCloudZipToStream($finalFilePointer, $zipFilePath, $disk)
    {
        $this->uploadMultiPart($finalFilePointer, $zipFilePath);
    }

    protected function uploadMultiPart($file, $zipFilePath)
    {
        $contents = fopen($zipFilePath, 'r+');
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION_REPORTS'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID_REPORTS'),
                'secret' => env('AWS_SECRET_ACCESS_KEY_REPORTS'),
            ]
        ]);
        $uploader = new MultipartUploader($s3, $contents, [
            'bucket' => env('AWS_BUCKET_REPORTS'),
            'key' => $file,
        ]);

        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            Log::alert($e->getMessage());
        }
    }
}
