<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Frontend\Tests\Unit\Controller;

use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Information;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\ErrorController;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class ErrorControllerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    /**
     * @test
     */
    public function pageNotFoundHandlingReturns404ResponseIfNotConfigured(): void
    {
        $typo3InformationMock = $this->getMockBuilder(Typo3Information::class)->disableOriginalConstructor()->getMock();
        $typo3InformationMock->method('getCopyrightYear')->willReturn('1999-20XX');
        GeneralUtility::addInstance(Typo3Information::class, $typo3InformationMock);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->pageNotFoundAction($request, 'This test page was not found!');
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('This test page was not found!', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function unavailableHandlingReturns503ResponseIfNotConfigured(): void
    {
        $typo3InformationMock = $this->getMockBuilder(Typo3Information::class)->disableOriginalConstructor()->getMock();
        $typo3InformationMock->method('getCopyrightYear')->willReturn('1999-20XX');
        GeneralUtility::addInstance(Typo3Information::class, $typo3InformationMock);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->unavailableAction($request, 'This page is temporarily unavailable.');
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('This page is temporarily unavailable.', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function unavailableHandlingDoesNotTriggerDueToDevIpMask(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->expectExceptionMessage('All your system are belong to us!');
        $this->expectExceptionCode(1518472181);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $subject->unavailableAction($request, 'All your system are belong to us!');
    }

    /**
     * @test
     */
    public function internalErrorHandlingReturns500ResponseIfNotConfigured(): void
    {
        $typo3InformationMock = $this->getMockBuilder(Typo3Information::class)->disableOriginalConstructor()->getMock();
        $typo3InformationMock->method('getCopyrightYear')->willReturn('1999-20XX');
        GeneralUtility::addInstance(Typo3Information::class, $typo3InformationMock);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->internalErrorAction($request, 'All your system are belong to us!');
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('All your system are belong to us!', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function internalErrorHandlingDoesNotTriggerDueToDevIpMask(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->expectExceptionMessage('All your system are belong to us!');
        $this->expectExceptionCode(1607585445);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $subject->internalErrorAction($request, 'All your system are belong to us!');
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithHtmlResponseIsChosenWhenNoSiteConfiguredForPageNotFoundAction(): void
    {
        $typo3InformationMock = $this->getMockBuilder(Typo3Information::class)->disableOriginalConstructor()->getMock();
        $typo3InformationMock->method('getCopyrightYear')->willReturn('1999-20XX');
        GeneralUtility::addInstance(Typo3Information::class, $typo3InformationMock);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->pageNotFoundAction($request, 'Error handler is not configured.');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Error handler is not configured.', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithJsonResponseIsChosenWhenNoSiteConfiguredForPageNotFoundAction(): void
    {
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->pageNotFoundAction($request->withAddedHeader('Accept', 'application/json'), 'Error handler is not configured.');
        $responseContent = \json_decode($response->getBody()->getContents(), true);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertEquals(['reason' => 'Error handler is not configured.'], $responseContent);
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithHtmlResponseIsChosenWhenNoSiteConfiguredForUnavailableAction(): void
    {
        $typo3InformationMock = $this->getMockBuilder(Typo3Information::class)->disableOriginalConstructor()->getMock();
        $typo3InformationMock->method('getCopyrightYear')->willReturn('1999-20XX');
        GeneralUtility::addInstance(Typo3Information::class, $typo3InformationMock);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->unavailableAction($request, 'Error handler is not configured.');
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Error handler is not configured.', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithJsonResponseIsChosenWhenNoSiteConfiguredForUnavailableAction(): void
    {
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->unavailableAction($request->withAddedHeader('Accept', 'application/json'), 'Error handler is not configured.');
        $responseContent = \json_decode($response->getBody()->getContents(), true);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertEquals(['reason' => 'Error handler is not configured.'], $responseContent);
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithHtmlResponseIsChosenWhenNoSiteConfiguredForInternalErrorAction(): void
    {
        $typo3InformationMock = $this->getMockBuilder(Typo3Information::class)->disableOriginalConstructor()->getMock();
        $typo3InformationMock->method('getCopyrightYear')->willReturn('1999-20XX');
        GeneralUtility::addInstance(Typo3Information::class, $typo3InformationMock);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->internalErrorAction($request, 'Error handler is not configured.');
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Error handler is not configured.', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithJsonResponseIsChosenWhenNoSiteConfiguredForInternalErrorAction(): void
    {
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->internalErrorAction($request->withAddedHeader('Accept', 'application/json'), 'Error handler is not configured.');
        $responseContent = \json_decode($response->getBody()->getContents(), true);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertEquals(['reason' => 'Error handler is not configured.'], $responseContent);
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithHtmlResponseIsChosenWhenNoSiteConfiguredForAccessDeniedAction(): void
    {
        $typo3InformationMock = $this->getMockBuilder(Typo3Information::class)->disableOriginalConstructor()->getMock();
        $typo3InformationMock->method('getCopyrightYear')->willReturn('1999-20XX');
        GeneralUtility::addInstance(Typo3Information::class, $typo3InformationMock);
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->accessDeniedAction($request, 'Error handler is not configured.');
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Error handler is not configured.', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function defaultErrorHandlerWithJsonResponseIsChosenWhenNoSiteConfiguredForAccessDeniedAction(): void
    {
        $request = new ServerRequest();
        $request = (new ServerRequest())->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $subject = new ErrorController();
        $response = $subject->accessDeniedAction($request->withAddedHeader('Accept', 'application/json'), 'Error handler is not configured.');
        $responseContent = \json_decode($response->getBody()->getContents(), true);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertEquals(['reason' => 'Error handler is not configured.'], $responseContent);
    }
}
