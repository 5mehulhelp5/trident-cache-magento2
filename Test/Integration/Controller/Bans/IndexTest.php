<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Bans;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Bans\Index;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\ViewModel\Bans;

class IndexTest extends TestCase
{
    public function testAdminResourceMatchesBansAcl(): void
    {
        $this->assertEquals('Qoliber_TridentCache::bans', Index::ADMIN_RESOURCE);
    }

    public function testViewModelImplementsArgumentInterface(): void
    {
        $this->assertContains(
            \Magento\Framework\View\Element\Block\ArgumentInterface::class,
            class_implements(Bans::class)
        );
    }

    public function testCountActiveAndExpiredSplitsByExpiryTimestamp(): void
    {
        /** @var ScopeConfigInterface&MockObject $scopeConfig */
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        /** @var EncryptorInterface&MockObject $encryptor */
        $encryptor = $this->createMock(EncryptorInterface::class);
        $config = new Config($scopeConfig, $encryptor);

        $tridentClient = $this->getMockBuilder(\Qoliber\TridentCache\Model\TridentClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $viewModel = new Bans($tridentClient, $config);

        $bans = [
            ['id' => '1', 'pattern' => '/a', 'type' => 'url', 'expires_at' => null],
            ['id' => '2', 'pattern' => '/b', 'type' => 'tag', 'expires_at' => time() + 3600],
            ['id' => '3', 'pattern' => '/c', 'type' => 'host', 'expires_at' => time() - 3600],
        ];

        $this->assertSame(2, $viewModel->countActive($bans));
        $this->assertSame(1, $viewModel->countExpired($bans));
        $this->assertTrue($viewModel->isExpired($bans[2]));
        $this->assertFalse($viewModel->isExpired($bans[0]));
    }
}
