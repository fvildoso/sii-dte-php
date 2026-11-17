<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\SiiException;
use SiiDte\Utils\XmlFragment;

class XmlFragmentTest extends TestCase
{
    public function testExtractsVerbatimElementIgnoringCommentsCdataAndQuotedGreaterThan(): void
    {
        $xml = '<?xml version="1.0"?><Root><Set note="a > b"><DTE><CAF version="1.0">'
            . '<DA><!-- </CAF> --><RS><![CDATA[<CAF>fake</CAF>]]></RS></DA>'
            . '</CAF></DTE></Set></Root>';

        self::assertSame(
            '<CAF version="1.0"><DA><!-- </CAF> --><RS><![CDATA[<CAF>fake</CAF>]]></RS></DA></CAF>',
            XmlFragment::extract($xml, ['Root', 'Set', 'DTE', 'CAF'])
        );
    }

    public function testRestauraMultiplesFragmentosEnOrdenSinCambiarOtrosBytes(): void
    {
        $xml = '<Root><Set><DTE><CAF><DA>A</DA></CAF></DTE>'
            . '<DTE><CAF><DA>B</DA></CAF></DTE></Set></Root>';
        $updated = str_replace(['<DA>A</DA>', '<DA>B</DA>'], ['<DA>A1</DA>', '<DA>B1</DA>'], $xml);
        $caf1 = '<CAF>  <DA>A&#49;</DA> </CAF>';
        $caf2 = '<CAF><DA>B&#49;</DA></CAF>';

        $result = XmlFragment::replaceAll(
            $updated,
            ['Root', 'Set', 'DTE', 'CAF'],
            [$caf1, $caf2]
        );

        self::assertSame(
            '<Root><Set><DTE>' . $caf1 . '</DTE><DTE>' . $caf2 . '</DTE></Set></Root>',
            $result
        );
    }

    public function testRechazaDtdYCantidadAmbiguaDeReemplazos(): void
    {
        try {
            XmlFragment::extract('<!DOCTYPE Root><Root/>', ['Root']);
            self::fail('Las declaraciones DTD no deben aceptarse.');
        } catch (SiiException $exception) {
            self::assertStringContainsString('declaraciones XML', $exception->getMessage());
        }

        $this->expectException(SiiException::class);
        XmlFragment::replaceAll('<Root><CAF/></Root>', ['Root', 'CAF'], []);
    }
}
