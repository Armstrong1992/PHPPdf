<?php

/*
 * Copyright 2011 Piotr Śliwa <peter.pl7@gmail.com>
 *
 * License information is in LICENSE file
 */

namespace PHPPdf\Formatter;

use PHPPdf\Document,
    PHPPdf\Node\Node,
    PHPPdf\Node\BasicList;

class ListFormatter extends BaseFormatter
{
    public function format(Node $node, Document $document)
    {
        $position = $node->getAttribute('position');
        
        $node->assignEnumerationStrategyFromFactory();
        
        if($position === BasicList::POSITION_INSIDE)
        {
            $widthOfEnumerationChar = $node->getEnumerationStrategy()->getWidthOfTheBiggestPosibleEnumerationElement($document, $node);
            
            foreach($node->getChildren() as $child)
            {
                $marginLeft = $widthOfEnumerationChar + $child->getMarginLeft();
                $child->setAttribute('margin-left', $marginLeft);
            }
        }
    }
}