<?php

namespace Box\Spout\Writer\XLSX\Manager;

use Box\Spout\Common\Exception\IOException;
use Box\Spout\Common\Helper\Escaper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
/**
 * Class SharedStringsManager
 * This class provides functions to write shared strings
 */
class SharedStringsManager
{
    public const SHARED_STRINGS_FILE_NAME = 'sharedStrings.xml';

    public const SHARED_STRINGS_XML_FILE_FIRST_PART_HEADER = <<<'EOD'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
EOD;

    /**
     * This number must be really big so that the no generated file will have more strings than that.
     * If the strings number goes above, characters will be overwritten in an unwanted way and will corrupt the file.
     */
    public const DEFAULT_STRINGS_COUNT_PART = 'count="99999999999999999999999999999999999" uniqueCount="99999999999999999999999999999999999"';


    /** @var resource Pointer to the sharedStrings.xml file */
    protected $sharedStringsFilePointer;

    /** @var int Number of shared strings already written */
    protected $numSharedStrings = 0;

    /** @var Escaper\XLSX Strings escaper */
    protected $stringsEscaper;

    /**
     * @param string $xlFolder Path to the "xl" folder
     * @param Escaper\XLSX $stringsEscaper Strings escaper
     */
    public function __construct($xlFolder, $stringsEscaper)
    {
        $sharedStringsFilePath = $xlFolder . '/' . self::SHARED_STRINGS_FILE_NAME;

        $this->sharedStringsFilePointer = $sharedStringsFilePath;
//        $this->sharedStringsFilePointer = fopen($sharedStringsFilePath, 'w');

//        $this->throwIfSharedStringsFilePointerIsNotAvailable();

        // the headers is split into different parts so that we can fseek and put in the correct count and uniqueCount later
        $header = self::SHARED_STRINGS_XML_FILE_FIRST_PART_HEADER . ' ' . self::DEFAULT_STRINGS_COUNT_PART . '>';
        Storage::disk('s3Xlsx')->append($this->sharedStringsFilePointer, $header);
//        fwrite($this->sharedStringsFilePointer, $header);
        Log::alert('header');
        Log::alert($header);

        $this->stringsEscaper = $stringsEscaper;
    }

    /**
     * Checks if the book has been created. Throws an exception if not created yet.
     *
     * @return void
     * @throws \Box\Spout\Common\Exception\IOException If the sheet data file cannot be opened for writing
     */
    protected function throwIfSharedStringsFilePointerIsNotAvailable()
    {
        if (!is_resource($this->sharedStringsFilePointer)) {
            throw new IOException('Unable to open shared strings file for writing.');
        }
    }

    /**
     * Writes the given string into the sharedStrings.xml file.
     * Starting and ending whitespaces are preserved.
     *
     * @param string $string
     * @return int ID of the written shared string
     */
    public function writeString($string)
    {
        Storage::disk('s3Xlsx')->append($this->sharedStringsFilePointer, '<si><t xml:space="preserve">' . $this->stringsEscaper->escape($string) . '</t></si>');
//        fwrite($this->sharedStringsFilePointer, '<si><t xml:space="preserve">' . $this->stringsEscaper->escape($string) . '</t></si>');
        $this->numSharedStrings++;

        // Shared string ID is zero-based
        return ($this->numSharedStrings - 1);
    }

    /**
     * Finishes writing the data in the sharedStrings.xml file and closes the file.
     *
     * @return void
     */
    public function close()
    {
       /* if (!\is_resource($this->sharedStringsFilePointer)) {
            return;
        }*/

//        fwrite($this->sharedStringsFilePointer, '</sst>');
        Storage::disk('s3Xlsx')->append($this->sharedStringsFilePointer, '</sst>');
        // Replace the default strings count with the actual number of shared strings in the file header
        $firstPartHeaderLength = \strlen(self::SHARED_STRINGS_XML_FILE_FIRST_PART_HEADER);
        $defaultStringsCountPartLength = \strlen(self::DEFAULT_STRINGS_COUNT_PART);

        // Adding 1 to take into account the space between the last xml attribute and "count"
        Log::alert('sprint if');
        Log::alert(\sprintf("%-{$defaultStringsCountPartLength}s", 'count="' . $this->numSharedStrings . '" uniqueCount="' . $this->numSharedStrings . '"'));
//        fseek($this->sharedStringsFilePointer, $firstPartHeaderLength + 1);
        Storage::disk('s3Xlsx')->append($this->sharedStringsFilePointer, \sprintf("%-{$defaultStringsCountPartLength}s", 'count="99999999999999999999999999999999999" uniqueCount="99999999999999999999999999999999999"'));
//        fwrite($this->sharedStringsFilePointer, \sprintf("%-{$defaultStringsCountPartLength}s", 'count="99999999999999999999999999999999999" uniqueCount="99999999999999999999999999999999999"'));

//        fclose($this->sharedStringsFilePointer);
    }
}
