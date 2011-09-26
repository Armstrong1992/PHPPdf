<?php

/*
 * Copyright 2011 Piotr Śliwa <peter.pl7@gmail.com>
 *
 * License information is in LICENSE file
 */

namespace PHPPdf\Bridge\Zend\Pdf\Resource\Image;

use PHPPdf\Stream\Stream;

use PHPPdf\Exception\Exception;
use PHPPdf\Stream\String;
use PHPPdf\Stream\Fopen;

/**
 * Content loading type has been changed, remote files are supported.
 * 
 * @author Piotr Śliwa <peter.pl7@gmail.com>
 */
class Tiff extends \Zend_Pdf_Resource_Image_Tiff
{
    private $stream;
    
    public function __construct($imageFileName)
    {
        $isRemote = stripos($imageFileName, 'http') === 0;
        
        if (($this->stream = $this->open($isRemote, $imageFileName)) === false ) {
            
            throw new \Zend_Pdf_Exception( "Can not open '$imageFileName' file for reading." );
        }

        $byteOrderIndicator = $this->read(2);
        if($byteOrderIndicator == 'II') {
            $this->_endianType = \Zend_Pdf_Resource_Image_Tiff::TIFF_ENDIAN_LITTLE;
        } else if($byteOrderIndicator == 'MM') {
            $this->_endianType = \Zend_Pdf_Resource_Image_Tiff::TIFF_ENDIAN_BIG;
        } else {
            
            throw new \Zend_Pdf_Exception( "Not a tiff file or Tiff corrupt. No byte order indication found" );
        }

        $version = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_SHORT, $this->read(2));

        if($version != 42) {
            
            throw new \Zend_Pdf_Exception( "Not a tiff file or Tiff corrupt. Incorrect version number." );
        }
        $ifdOffset = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_LONG, $this->read(4));

        $this->_fileSize = $this->size();

        /*
         * Tiff files are stored as a series of Image File Directories (IFD) each direcctory
         * has a specific number of entries each 12 bytes in length. At the end of the directories
         * is four bytes pointing to the offset of the next IFD.
         */

        while($ifdOffset > 0) {
            if($this->seek($ifdOffset, Stream::SEEK_SET) == -1 || $ifdOffset+2 >= $this->_fileSize) {
                
                throw new \Zend_Pdf_Exception("Could not seek to the image file directory as indexed by the file. Likely cause is TIFF corruption. Offset: ". $ifdOffset);
            }

            $numDirEntries = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_SHORT, $this->read(2));

            /*
             * Since we now know how many entries are in this (IFD) we can extract the data.
             * The format of a TIFF directory entry is:
             *
             * 2 bytes (short) tag code; See TIFF_TAG constants at the top for supported values. (There are many more in the spec)
             * 2 bytes (short) field type
             * 4 bytes (long) number of values, or value count.
             * 4 bytes (mixed) data if the data will fit into 4 bytes or an offset if the data is too large.
             */
            for($dirEntryIdx = 1; $dirEntryIdx <= $numDirEntries; $dirEntryIdx++) {
                $tag         = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_SHORT, $this->read(2));
                $fieldType   = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_SHORT, $this->read(2));
                $valueCount  = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_LONG, $this->read(4));

                switch($fieldType) {
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_BYTE:
                        $fieldLength = $valueCount;
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_ASCII:
                        $fieldLength = $valueCount;
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_SHORT:
                        $fieldLength = $valueCount * 2;
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_LONG:
                        $fieldLength = $valueCount * 4;
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_RATIONAL:
                        $fieldLength = $valueCount * 8;
                        break;
                    default:
                        $fieldLength = $valueCount;
                }

                $offsetBytes = $this->read(4);

                if($fieldLength <= 4) {
                    switch($fieldType) {
                        case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_BYTE:
                            $value = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_BYTE, $offsetBytes);
                            break;
                        case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_ASCII:
                            //Fall through to next case
                        case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_LONG:
                            $value = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_LONG, $offsetBytes);
                            break;
                        case \Zend_Pdf_Resource_Image_Tiff::TIFF_FIELD_TYPE_SHORT:
                            //Fall through to next case
                        default:
                            $value = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_SHORT, $offsetBytes);
                    }
                } else {
                    $refOffset = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_LONG, $offsetBytes);
                }
                /*
                 * Linear tag processing is probably not the best way to do this. I've processed the tags according to the
                 * Tiff 6 specification and make some assumptions about when tags will be < 4 bytes and fit into $value and when
                 * they will be > 4 bytes and require seek/extraction of the offset. Same goes for extracting arrays of data, like
                 * the data offsets and length. This should be fixed in the future.
                 */
                switch($tag) {
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_TAG_IMAGE_WIDTH:
                        $this->_width = $value;
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_TAG_IMAGE_LENGTH:
                        $this->_height = $value;
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_TAG_BITS_PER_SAMPLE:
                        if($valueCount>1) {
                            $fp = $this->tell();
                            $this->seek($refOffset, Stream::SEEK_SET);
                            $this->_bitsPerSample = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_SHORT, $this->read(2));
                            $this->seek($fp, Stream::SEEK_SET);
                        } else {
                            $this->_bitsPerSample = $value;
                        }
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_TAG_COMPRESSION:
                        $this->_compression = $value;
                        switch($value) {
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_UNCOMPRESSED:
                                $this->_filter = 'None';
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_CCITT1D:
                                //Fall through to next case
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_GROUP_3_FAX:
                                //Fall through to next case
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_GROUP_4_FAX:
                                $this->_filter = 'CCITTFaxDecode';
                                
                                throw new \Zend_Pdf_Exception("CCITTFaxDecode Compression Mode Not Currently Supported");
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_LZW:
                                $this->_filter = 'LZWDecode';
                                
                                throw new \Zend_Pdf_Exception("LZWDecode Compression Mode Not Currently Supported");
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_JPEG:
                                $this->_filter = 'DCTDecode'; //Should work, doesnt...
                                
                                throw new \Zend_Pdf_Exception("JPEG Compression Mode Not Currently Supported");
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_FLATE:
                                //fall through to next case
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_FLATE_OBSOLETE_CODE:
                                $this->_filter = 'FlateDecode';
                                
                                throw new \Zend_Pdf_Exception("ZIP/Flate Compression Mode Not Currently Supported");
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_COMPRESSION_PACKBITS:
                                $this->_filter = 'RunLengthDecode';
                                break;
                        }
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_TAG_PHOTOMETRIC_INTERPRETATION:
                        $this->_colorCode = $value;
                        $this->_whiteIsZero = false;
                        $this->_blackIsZero = false;
                        switch($value) {
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_PHOTOMETRIC_INTERPRETATION_WHITE_IS_ZERO:
                                $this->_whiteIsZero = true;
                                $this->_colorSpace = 'DeviceGray';
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_PHOTOMETRIC_INTERPRETATION_BLACK_IS_ZERO:
                                $this->_blackIsZero = true;
                                $this->_colorSpace = 'DeviceGray';
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_PHOTOMETRIC_INTERPRETATION_YCBCR:
                                //fall through to next case
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_PHOTOMETRIC_INTERPRETATION_RGB:
                                $this->_colorSpace = 'DeviceRGB';
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_PHOTOMETRIC_INTERPRETATION_RGB_INDEXED:
                                $this->_colorSpace = 'Indexed';
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_PHOTOMETRIC_INTERPRETATION_CMYK:
                                $this->_colorSpace = 'DeviceCMYK';
                                break;
                            case \Zend_Pdf_Resource_Image_Tiff::TIFF_PHOTOMETRIC_INTERPRETATION_CIELAB:
                                $this->_colorSpace = 'Lab';
                                break;
                            default:
                                
                                throw new \Zend_Pdf_Exception('TIFF: Unknown or Unsupported Color Type: '. $value);
                        }
                        break;
                    case \Zend_Pdf_Resource_Image_Tiff::TIFF_TAG_STRIP_OFFSETS:
                        if($valueCount>1) {
                            $format = ($this->_endianType == \Zend_Pdf_Resource_Image_Tiff::TIFF_ENDIAN_LITTLE)?'V*':'N*';
                            $fp = $this->tell();
                            $this->seek($refOffset, Stream::SEEK_SET);
                            $stripOffsetsBytes = $this->read($fieldLength);
                            $this->_imageDataOffset = unpack($format, $stripOffsetsBytes);
                            $this->seek($fp, Stream::SEEK_SET);
                        } else {
                            $this->_imageDataOffset = $value;
                        }
                        break;
                   case \Zend_Pdf_Resource_Image_Tiff::TIFF_TAG_STRIP_BYTE_COUNTS:
                        if($valueCount>1) {
                            $format = ($this->_endianType == \Zend_Pdf_Resource_Image_Tiff::TIFF_ENDIAN_LITTLE)?'V*':'N*';
                            $fp = $this->tell();
                            $this->seek($refOffset, Stream::SEEK_SET);
                            $stripByteCountsBytes = $this->read($fieldLength);
                            $this->_imageDataLength = unpack($format, $stripByteCountsBytes);
                            $this->seek($fp, Stream::SEEK_SET);
                        } else {
                            $this->_imageDataLength = $value;
                        }
                        break;
                    default:
                        //For debugging. It should be harmless to ignore unknown tags, though there is some good info in them.
                        //echo "Unknown tag detected: ". $tag . " value: ". $value;
                }
            }
            $ifdOffset = $this->unpackBytes(\Zend_Pdf_Resource_Image_Tiff::UNPACK_TYPE_LONG, $this->read(4));
        }

        if(!isset($this->_imageDataOffset) || !isset($this->_imageDataLength)) {
            
            throw new \Zend_Pdf_Exception("TIFF: The image processed did not contain image data as expected.");
        }

        $imageDataBytes = '';
        if(is_array($this->_imageDataOffset)) {
            if(!is_array($this->_imageDataLength)) {
                
                throw new \Zend_Pdf_Exception("TIFF: The image contained multiple data offsets but not multiple data lengths. Tiff may be corrupt.");
            }
            foreach($this->_imageDataOffset as $idx => $offset) {
                $this->seek($this->_imageDataOffset[$idx], Stream::SEEK_SET);
                $imageDataBytes .= $this->read($this->_imageDataLength[$idx]);
            }
        } else {
            $this->seek($this->_imageDataOffset, Stream::SEEK_SET);
            $imageDataBytes = $this->read($this->_imageDataLength);
        }
        if($imageDataBytes === '') {
            
            throw new \Zend_Pdf_Exception("TIFF: No data. Image Corruption");
        }

        $this->close();

        \Zend_Pdf_Resource_Image::__construct();

        $imageDictionary = $this->_resource->dictionary;
        if(!isset($this->_width) || !isset($this->_width)) {
            
            throw new \Zend_Pdf_Exception("Problem reading tiff file. Tiff is probably corrupt.");
        }

        $this->_imageProperties = array();
        $this->_imageProperties['bitDepth'] = $this->_bitsPerSample;
        $this->_imageProperties['fileSize'] = $this->_fileSize;
        $this->_imageProperties['TIFFendianType'] = $this->_endianType;
        $this->_imageProperties['TIFFcompressionType'] = $this->_compression;
        $this->_imageProperties['TIFFwhiteIsZero'] = $this->_whiteIsZero;
        $this->_imageProperties['TIFFblackIsZero'] = $this->_blackIsZero;
        $this->_imageProperties['TIFFcolorCode'] = $this->_colorCode;
        $this->_imageProperties['TIFFimageDataOffset'] = $this->_imageDataOffset;
        $this->_imageProperties['TIFFimageDataLength'] = $this->_imageDataLength;
        $this->_imageProperties['PDFfilter'] = $this->_filter;
        $this->_imageProperties['PDFcolorSpace'] = $this->_colorSpace;

        $imageDictionary->Width            = \Zend_Pdf_Element_Numeric::getInstance($this->_width);
        if($this->_whiteIsZero === true) {
            $imageDictionary->Decode       = new \Zend_Pdf_Element_Array(array(\Zend_Pdf_Element_Numeric::getInstance(1), \Zend_Pdf_Element_Numeric::getInstance(0)));
        }
        $imageDictionary->Height           = \Zend_Pdf_Element_Numeric::getInstance($this->_height);
        $imageDictionary->ColorSpace       = \Zend_Pdf_Element_Name::getInstance($this->_colorSpace);
        $imageDictionary->BitsPerComponent = \Zend_Pdf_Element_Numeric::getInstance($this->_bitsPerSample);
        if(isset($this->_filter) && $this->_filter != 'None') {
            $imageDictionary->Filter = \Zend_Pdf_Element_Name::getInstance($this->_filter);
        }

        $this->_resource->value = $imageDataBytes;
        $this->_resource->skipFilters();
    }
    
    private function open($isRemote, $imageFileName)
    {
        try 
        {
            if($isRemote)
            {
                $content = @file_get_contents($imageFileName);
                
                if($content === false)
                {
                    return false;
                }
                
                return new String($content);
            }
            else
            {
                return new Fopen($imageFileName, 'rb');
            }
        }
        catch(Exception $e)
        {
            return false;
        }
    }
    
    private function seek($index, $seekMode = Stream::SEEK_CUR)
    {
        return $this->stream->seek($index, $seekMode);
    }
    
    private function read($length)
    {
        return $this->stream->read($length);
    }
    
    private function close()
    {
        $this->stream->close();
    }
    
    private function tell()
    {
        return $this->stream->tell();
    }
    
    private function size()
    {
        return $this->stream->size();
    }
}