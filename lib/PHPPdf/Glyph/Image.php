<?php

/*
 * Copyright 2011 Piotr Śliwa <peter.pl7@gmail.com>
 *
 * License information is in LICENSE file
 */

namespace PHPPdf\Glyph;

use PHPPdf\Document,
    PHPPdf\Util\DrawingTask,
    PHPPdf\Glyph\Glyph;

/**
 * @author Piotr Śliwa <peter.pl7@gmail.com>
 */
class Image extends Glyph
{
    public function initialize()
    {
        parent::initialize();
        $this->addAttribute('src');
    }

    protected function doDraw(Document $document)
    {
        $callback = function($glyph)
        {
            $graphicsContext = $glyph->getGraphicsContext();

            list($x, $y) = $glyph->getStartDrawingPoint();
            $image = $glyph->getAttribute('src');
            $graphicsContext->drawImage($image, $x, $y-$glyph->getHeight(), $x+$glyph->getWidth(), $y);
        };
        
        $drawingTask = new DrawingTask($callback, array($this));

        $this->addDrawingTask($drawingTask);
    }

    public function preFormat(Document $document)
    {
        $src = $this->getAttribute('src');

        if(is_string($src))
        {
            $src = $document->createImage($src);
            $this->setAttribute('src', $src);
        }
        
        if(!$this->getWidth() && !$this->getHeight())
        {

            $this->setWidth($src->getOriginalWidth());
            $this->setHeight($src->getOriginalHeight());
        }
    }

    public function split($height)
    {
        return null;
    }
    
    public function getMinWidth()
    {
        return $this->getWidth() + $this->getMarginLeft() + $this->getMarginRight();
    }
}