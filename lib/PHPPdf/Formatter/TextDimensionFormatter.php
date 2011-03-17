<?php

namespace PHPPdf\Formatter;

use PHPPdf\Formatter\BaseFormatter,
    PHPPdf\Glyph as Glyphs,
    PHPPdf\Document,
    PHPPdf\Formatter\Chain;

/**
 * Calculates text's real dimension
 *
 * @author Piotr Śliwa <peter.pl7@gmail.com>
 */
class TextDimensionFormatter extends BaseFormatter
{
    private $charCodeMethodName;

    public function __construct($charCodeMethodName = 'ordUtf8')
    {
        $this->setCharCodeMethod($charCodeMethodName);
    }

    private function setCharCodeMethod($methodName)
    {
        if(!is_callable(array($this, $methodName)))
        {
            throw new \InvalidArgumentException('Passed argument is not valid callback.');
        }

        $this->charCodeMethodName = $methodName;
    }

    public function format(Glyphs\Glyph $glyph, Document $document)
    {
        $realHeight = 0;
        $page = $glyph->getPage();
        $graphicsContext = $page->getGraphicsContext();

        $fontSize = $glyph->getRecurseAttribute('font-size');
        $lineHeight = $glyph->getAttribute('line-height');

        $graphicsContext->saveGS();
        $graphicsContext->setFont($glyph->getFont(), $fontSize);

        $wordsInRows = array();
        $lineSizes = array();

        $this->separateTextFromGlyphIntoRows($glyph, $wordsInRows, $lineSizes);
        $graphicsContext->restoreGS();

        $realHeight = $lineHeight*count($wordsInRows);

        $padding = $glyph->getPaddingTop() + $glyph->getPaddingBottom();
        $glyph->setHeight($realHeight + $padding);

        $display = $glyph->getAttribute('display');

        if($display === Glyphs\AbstractGlyph::DISPLAY_BLOCK)
        {
            $glyph->setWidth($glyph->getWidth());
        }

        $maxLineSize = \max($lineSizes);
        if($display === Glyphs\AbstractGlyph::DISPLAY_INLINE || $maxLineSize > $glyph->getWidth())
        {
            $glyph->setWidth($maxLineSize);
            $padding = $glyph->getPaddingLeft() + $glyph->getPaddingRight();
            $glyph->setWidth($glyph->getWidth() + $padding);
        }
    }

    private function separateTextFromGlyphIntoRows(Glyphs\Text $glyph, array &$wordsInRows, array &$lineSizes)
    {
        $font = $glyph->getFont();
        $fontSize = $glyph->getRecurseAttribute('font-size');

        list($x, $y) = $glyph->getStartDrawingPoint();

        $text = $glyph->getText();
        $words = preg_split('/\s+/', $text);

        $lineHeight = $glyph->getLineHeight();

        $rowWidth = 0;
        $rowHeight = $y - $lineHeight;

        $count = count($words);

        $rowNumber = 0;

        $parent = $glyph->getParent();
        list($parentX, $parentY) = $parent->getStartDrawingPoint();

        $blockParent = $parent;

        while($blockParent && $blockParent->getDisplay() !== Glyphs\AbstractGlyph::DISPLAY_BLOCK)
        {
            $blockParent = $blockParent->getParent();
        }

        $comparationWidth = $glyph->getAttribute('display') === Glyphs\AbstractGlyph::DISPLAY_BLOCK ? $glyph->getWidthWithoutPaddings() : ($blockParent->getWidthWithoutPaddings() - ($x - $parentX));

        $lineSizes = array();
        $wordsInRows = array();
        $spaceWidth = $this->getTextWidth($font, $fontSize, ' ');
        for($i=0; $i<$count; $i++)
        {
            $word = $words[$i];
            $width = $this->getTextWidth($font, $fontSize, $word) + $spaceWidth;
            $newRowWidth = $rowWidth + $width;

            if($comparationWidth < $newRowWidth)
            {
                $lineSizes[$rowNumber] = $rowWidth;
                $rowWidth = 0;
                $newRowWidth = $width;
                $rowHeight -= $lineHeight;

                $rowNumber++;

                if($glyph->getAttribute('display') === Glyphs\AbstractGlyph::DISPLAY_INLINE)
                {
                    $comparationWidth = $glyph->getParent()->getWidth() - $glyph->getMarginLeft() - $glyph->getMarginRight();
                }
            }

            $wordsInRows[$rowNumber][] = $word;

            $rowWidth = $newRowWidth;
        }
        $lineSizes[$rowNumber] = $rowWidth - $spaceWidth;

        $glyph->setLineSizes($lineSizes);
        $glyph->setWordsInRows($wordsInRows);
    }

    private function getTextWidth(\PHPPdf\Font\Font $font, $fontSize, $text)
    {
        $callback = array($this, $this->charCodeMethodName);
        if($fontSize)
        {
            $length = strlen($text);
            $chars = array();
            $bytes = 1;
            for($i=0; $i<$length; $i+=$bytes)
            {
                list($char, $bytes) = call_user_func($callback, $text, $i, $bytes);
                if($char !== false)
                {
                    $chars[] = $char;
                }
            }

            $textWidth = $font->getCharsWidth($chars, $fontSize);

            return $textWidth;
        }

        return 0;
    }

    /**
     * code from http://php.net/manual/en/function.ord.php#78032
     */
    private function ordUtf8($text, $index = 0, $bytes = null)
    {
        $len = strlen($text);
        $bytes = 0;

        $char = false;

        if ($index < $len)
        {
            $h = ord($text{$index});

            if($h <= 0x7F)
            {
                $bytes = 1;
                $char = $h;
            }
            elseif ($h < 0xC2)
            {
                $char = false;
            }
            elseif ($h <= 0xDF && $index < $len - 1)
            {
                $bytes = 2;
                $char = ($h & 0x1F) <<  6 | (ord($text{$index + 1}) & 0x3F);
            }
            elseif($h <= 0xEF && $index < $len - 2)
            {
                $bytes = 3;
                $char = ($h & 0x0F) << 12 | (ord($text{$index + 1}) & 0x3F) << 6
                                         | (ord($text{$index + 2}) & 0x3F);
            }
            elseif($h <= 0xF4 && $index < $len - 3)
            {
                $bytes = 4;
                $char = ($h & 0x0F) << 18 | (ord($text{$index + 1}) & 0x3F) << 12
                                         | (ord($text{$index + 2}) & 0x3F) << 6
                                         | (ord($text{$index + 3}) & 0x3F);
            }
        }


        return array($char, $bytes);
    }

    public function serialize()
    {
        return serialize($this->charCodeMethodName);
    }

    public function unserialize($serialized)
    {
        $method = unserialize($serialized);

        $this->setCharCodeMethod($method);
    }
}