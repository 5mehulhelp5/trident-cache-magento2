<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Cache;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Controller\Adminhtml\Cache\PurgeHost;
use Qoliber\TridentCache\Controller\Adminhtml\Cache\PurgeVary;
use Qoliber\TridentCache\Model\TridentClient;

class PurgeHostTest extends TestCase
{
    public function testPurgeHostAdminResourceMatchesPurgeAcl(): void
    {
        $this->assertSame('Qoliber_TridentCache::purge', PurgeHost::ADMIN_RESOURCE);
    }

    public function testPurgeVaryAdminResourceMatchesPurgeAcl(): void
    {
        $this->assertSame('Qoliber_TridentCache::purge', PurgeVary::ADMIN_RESOURCE);
    }

    public function testControllersExtendBackendAction(): void
    {
        $this->assertTrue(is_subclass_of(PurgeHost::class, Action::class));
        $this->assertTrue(is_subclass_of(PurgeVary::class, Action::class));
    }

    public function testEmptyHostAddsErrorAndRedirectsToPurge(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn('');

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('trident/cache/purge')
            ->willReturnSelf();

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messageManager = $this->createMock(ManagerInterface::class);
        $messageManager->expects($this->once())->method('addErrorMessage');

        $tridentClient = $this->createMock(TridentClient::class);
        $tridentClient->expects($this->never())->method('purgeHost');

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messageManager);

        $controller = new PurgeHost($context, $tridentClient, $this->createMock(LoggerInterface::class));

        $this->assertInstanceOf(Redirect::class, $controller->execute());
    }

    public function testValidHostPurgesAndRedirectsToPurge(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn('www.example.com');

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('trident/cache/purge')
            ->willReturnSelf();

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messageManager = $this->createMock(ManagerInterface::class);
        $messageManager->expects($this->once())->method('addSuccessMessage');

        $tridentClient = $this->createMock(TridentClient::class);
        $tridentClient->expects($this->once())
            ->method('purgeHost')
            ->with('www.example.com')
            ->willReturn(['affected' => 12]);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messageManager);

        $controller = new PurgeHost($context, $tridentClient, $this->createMock(LoggerInterface::class));

        $this->assertInstanceOf(Redirect::class, $controller->execute());
    }
}
