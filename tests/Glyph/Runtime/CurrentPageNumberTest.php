<?php

use PHPPdf\Util\DrawingTask;
use PHPPdf\Glyph\Runtime\CurrentPageNumber,
    PHPPdf\Document,
    PHPPdf\Glyph\DynamicPage,
    PHPPdf\Glyph\Page;

class CurrentPageNumberTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var PHPPdf\Glyph\Glyph
     */
    private $glyph;

    public function setUp()
    {
        $this->glyph = new CurrentPageNumber();
    }

    /**
     * @test
     */
    public function drawing()
    {
        $mock = $this->getPageMock();

        $this->glyph->setParent($mock);

        $tasks = $this->glyph->getDrawingTasks(new Document());
        $this->assertEmpty($tasks);
    }

    private function getPageMock()
    {
        $mock = $this->getMock('PHPPdf\Glyph\Page', array('markAsRuntimeGlyph'));
        $mock->expects($this->once())
             ->method('markAsRuntimeGlyph');

        return $mock;
    }

    /**
     * @test
     */
    public function cannotMergeEnhancements()
    {
        $this->glyph->mergeEnhancementAttributes('name', array('name' => 'value'));

        $this->assertEmpty($this->glyph->getEnhancementsAttributes());
    }

    /**
     * @test
     */
    public function valueBeforeEvaluation()
    {
        $dummyText = $this->glyph->getAttribute('dummy-text');
        $text = $this->glyph->getText();

        $this->assertNotEmpty($dummyText);
        $this->assertEquals($dummyText, $text);
    }
    
    /**
     * @test
     */
    public function drawingAfterEvaluating()
    {
        $pageMock = $this->getMock('PHPPdf\Glyph\Page', array('getContext'));
        $contextMock = $this->getMock('PHPPdf\Glyph\PageContext', array('getPageNumber'), array(5, new DynamicPage()));

        $pageMock->expects($this->atLeastOnce())
                 ->method('getContext')
                 ->will($this->returnValue($contextMock));

        $pageNumber = 5;
        $contextMock->expects($this->atLeastOnce())
                    ->method('getPageNumber')
                    ->will($this->returnValue(5));

        $this->glyph->setParent($pageMock);
        $linePart = $this->getMockBuilder('PHPPdf\Glyph\Paragraph\LinePart')
                         ->setMethods(array('setWords', 'getDrawingTasks'))
                         ->disableOriginalConstructor()
                         ->getMock();
                         
        $linePart->expects($this->at(0))
                 ->method('setWords')
                 ->with($pageNumber);
                         
        $drawingTaskStub = new DrawingTask(function(){});
        $linePart->expects($this->at(1))
                 ->method('getDrawingTasks')
                 ->will($this->returnValue(array($drawingTaskStub)));
                 
        $this->glyph->addLinePart($linePart);

        $this->glyph->evaluate();
        $tasks = $this->glyph->getDrawingTasks(new Document());
        $this->assertEquals(array($drawingTaskStub), $tasks);
        $this->assertEquals($pageNumber, $this->glyph->getText());
    }

    /**
     * @test
     */
    public function settingPage()
    {
        $page = new Page();

        $this->glyph->setPage($page);

        $this->assertTrue($page === $this->glyph->getPage());
    }

    /**
     * @test
     */
    public function afterCopyParentIsntDetached()
    {
        $page = new Page();

        $this->glyph->setParent($page);
        $copy = $this->glyph->copyAsRuntime();

        $this->assertTrue($copy->getParent() === $page);
    }
}